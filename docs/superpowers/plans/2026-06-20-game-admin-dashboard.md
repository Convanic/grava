# Game Admin-Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Server-gerendertes Admin-Dashboard (A–F) für die Gamification, erreichbar nur unter `admin.grava.world`, plus die Backend-Rückwirkungen (Pass-Invalidierung, Ban-Check, Ingest-Log).

**Architecture:** Gleicher Docroot + host-bewusstes Routing in `public/index.php`. Admin-Auth über bestehendes `ADMIN_EMAILS`-Gate + host-gebundene Web-Session. Neue Tabellen `game_ingest_log/game_audit/game_user_flag` + Invalidierungsspalten auf `game_edge_pass`. Alle Spiel-Berechnungen schließen invalidierte Pässe aus. Server-gerenderte Views via `WebView`.

**Tech Stack:** PHP 8.2+, PDO/MySQL, PSR-4 `App\`, PHPUnit, server-rendered Views (`views/web/admin/game/*`), bestehende `Csrf`/`WebSession`-Middleware.

**Branch:** `feat/game-admin-dashboard` (baut auf `feat/game-stage1`).

---

## Konventionen (für alle Tasks)

- TDD: erst Test (rot), dann minimale Implementierung (grün), dann Commit. Integrationstests brauchen die MySQL-Test-DB → außerhalb der Sandbox laufen lassen (`composer test:integration -- --filter <Class>`); in der Sandbox werden sie übersprungen (SQLSTATE 2002).
- Branch NICHT wechseln.
- Views: nur bestehende CSS-Klassen/Tokens (`.card`, `.data-table`, `.btn-primary`, `.btn-accent`, `.muted`, …); KEINE hartkodierten Hex-Farben (Design-System-Rule). Alle Ausgaben mit `htmlspecialchars` escapen.
- Schreibende Admin-Aktionen: POST + CSRF + Audit.

## File Structure

| Datei | Verantwortung |
|---|---|
| `migrations/0016_game_dashboard.sql` | Tabellen + ALTER + Config-Seeds |
| `src/Game/GameRepository.php` (mod) | Invalidierungs-Filter, `allPassesForEdge`, `edgeIdsInBbox`, `isUserBanned`, `insertIngestLog`, Inspector/Leaderboard-Reads |
| `src/Game/GameIngestionService.php` (mod) | Ban-Check + Ingest-Log-Schreiben |
| `src/Game/Admin/AdminGuard.php` | Admin-Gate (ADMIN_EMAILS), testbar (bool) |
| `src/Game/Admin/GameAuditService.php` | Audit schreiben + letzte Aktionen lesen |
| `src/Game/Admin/GameConfigAdminService.php` | Config-Validierung + Update + Audit |
| `src/Game/Admin/GamePassAdminService.php` | Pass invalidieren/reaktivieren + Kante neu rechnen + Audit |
| `src/Game/Admin/GameUserFlagService.php` | Ban/Unban + betroffene Kanten neu rechnen + Audit |
| `src/Game/Admin/GameModerationService.php` | Heuristik-Queries (Review-Queue) |
| `src/Game/Admin/GameAdminService.php` | Health-Kennzahlen, Ingest-Monitor-Reads, Inspector-Aggregate, Leaderboard |
| `src/Game/Admin/AdminHost.php` | Host-Entscheidung (testbarer Helfer) |
| `src/Controllers/Web/Admin/GameAdminController.php` | Health, Config, Ingest, Moderation, Players |
| `src/Controllers/Web/Admin/GameEdgeInspectorController.php` | Inspector + Pass-Aktionen |
| `views/web/admin/game/*.php` | Health/Config/Ingest/Edge/Moderation/Players |
| `src/Cli/Commands.php` (mod) | `game:recompute --bbox=` |
| `public/index.php` (mod) | Host-Routing + Admin-Routen + `/admin/*`-Block auf Hauptdomain |
| `.env.example` (mod) | `ADMIN_HOST` |

## Task-Übersicht

1. Migration `0016_game_dashboard.sql`
2. GameRepository: Invalidierungs-Filter + neue Reads (+ Tests)
3. GameIngestionService: Ban-Check + Ingest-Log (+ Tests)
4. AdminGuard + AdminHost (+ Unit-Tests)
5. GameAuditService (+ Test)
6. GameConfigAdminService: Validierung/Update (+ Test)
7. GamePassAdminService: Invalidieren/Reaktivieren wirkt (+ Test, §5.5)
8. GameUserFlagService: Ban/Unban (+ Test, §5.6)
9. GameModerationService: Heuristiken (+ Test)
10. GameAdminService: Health/Monitor/Leaderboard/Inspector (+ Test, §5.7/§5.8)
11. CLI `game:recompute --bbox=`
12. Controller + Views (A–F)
13. Wiring `public/index.php` (Host-Routing) + `.env.example`
14. Testbericht-Erweiterung + DoD

---

### Task 1: Migration `0016_game_dashboard.sql`

**Files:** Create `migrations/0016_game_dashboard.sql`

- [ ] **Step 1: Migration schreiben** (Konventionen wie `0015_game_stage1.sql`: up-only, `IF NOT EXISTS`)

```sql
-- 0016 Game Dashboard: Ingest-Log, Audit, User-Flags, Pass-Invalidierung, Heuristik-Config.

CREATE TABLE IF NOT EXISTS game_ingest_log (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  route_id        BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  status          ENUM('ok','pending','failed') NOT NULL,
  matched_edges   INT NOT NULL DEFAULT 0,
  new_passes      INT NOT NULL DEFAULT 0,
  skipped_json    JSON NULL,
  valhalla_error  VARCHAR(255) NULL,
  duration_ms     INT NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ingest_status (status),
  KEY idx_ingest_route (route_id),
  KEY idx_ingest_created (created_at)
);

CREATE TABLE IF NOT EXISTS game_audit (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin_user_id BIGINT UNSIGNED NOT NULL,
  action        VARCHAR(40) NOT NULL,
  target        VARCHAR(80) NULL,
  detail_json   JSON NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_audit_admin (admin_user_id),
  KEY idx_audit_action (action),
  KEY idx_audit_created (created_at)
);

CREATE TABLE IF NOT EXISTS game_user_flag (
  user_id     BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  banned      TINYINT(1) NOT NULL DEFAULT 0,
  reason      VARCHAR(160) NULL,
  updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);

ALTER TABLE game_edge_pass
  ADD COLUMN invalidated_at DATETIME(3) NULL,
  ADD COLUMN invalidated_by BIGINT UNSIGNED NULL,
  ADD COLUMN invalid_reason VARCHAR(120) NULL;

INSERT INTO game_config (config_key, config_value) VALUES
  ('auth_max_speed_kmh', '80'),
  ('mod_max_new_edges_per_min', '30'),
  ('mod_max_passes_per_day', '200')
ON DUPLICATE KEY UPDATE config_key = config_key;
```

- [ ] **Step 2: Migration anwenden + verifizieren** (außerhalb Sandbox)

Run: `php public/index.php cli:migrate`
Expected: `Migriert: 0016_game_dashboard.sql`; `SHOW COLUMNS FROM game_edge_pass LIKE 'invalidated_at'` liefert eine Zeile.

- [ ] **Step 3: GameConfig DEFAULTS ergänzen** in `src/Game/GameConfig.php` (Array `DEFAULTS`), damit Lesepfade ohne DB-Seed lauffähig bleiben:

```php
        'auth_max_speed_kmh'        => '80',
        'mod_max_new_edges_per_min' => '30',
        'mod_max_passes_per_day'    => '200',
```

- [ ] **Step 4: Commit**

```bash
git add migrations/0016_game_dashboard.sql src/Game/GameConfig.php
git commit -m "feat(game-admin): migration 0016 (ingest log, audit, user flag, pass invalidation, heuristics)"
```

---

### Task 2: GameRepository — Invalidierungs-Filter + neue Reads

**Files:** Modify `src/Game/GameRepository.php`; Test `tests/Integration/Game/GameInvalidationTest.php`

- [ ] **Step 1: Failing test schreiben** — invalidierte Pässe sind aus distinct/Präsenz/Kohorte ausgeschlossen.

```php
<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameRepository;
use Tests\IntegrationTestCase;

final class GameInvalidationTest extends IntegrationTestCase
{
    public function testInvalidatedPassesExcludedFromCounts(): void
    {
        $repo = new GameRepository($this->pdo);
        $u1 = $this->createUser('a'); $u2 = $this->createUser('b');
        $c1 = $repo->riderClaimantId($u1); $c2 = $repo->riderClaimantId($u2);
        $a = $repo->upsertNode(10, 47.1, 9.6); $b = $repo->upsertNode(11, 47.2, 9.7);
        $edgeId = $repo->upsertEdge(1001, $a, $b, 100.0, '{"type":"LineString","coordinates":[[9.6,47.1],[9.7,47.2]]}', 'gravel', 47.1, 9.6, 47.2, 9.7);
        $repo->insertPassIfAbsent($edgeId, $c1, $u1, 1, '2026-06-20', '2026-06-20 08:00:00.000');
        $repo->insertPassIfAbsent($edgeId, $c2, $u2, 2, '2026-06-20', '2026-06-20 09:00:00.000');

        $this->assertSame(2, $repo->distinctRidersTotal($edgeId));
        $this->assertCount(2, $repo->passesForEdge($edgeId));

        // Invalidieren von User 2.
        $this->pdo->prepare('UPDATE game_edge_pass SET invalidated_at = NOW(3), invalidated_by = ?, invalid_reason = ? WHERE edge_id = ? AND user_id = ?')
            ->execute([$u1, 'test', $edgeId, $u2]);

        $this->assertSame(1, $repo->distinctRidersTotal($edgeId), 'invalidierter Pass darf nicht zählen');
        $this->assertSame(1, $repo->distinctRidersSince($edgeId, '2000-01-01'));
        $this->assertCount(1, $repo->passesForEdge($edgeId), 'Präsenz-Pässe ohne invalidierte');
        $this->assertCount(2, $repo->allPassesForEdge($edgeId), 'Inspector sieht alle inkl. invalidiert');
        $cohort = $repo->firstPassPerUser($edgeId, 10);
        $this->assertCount(1, $cohort);
        $this->assertSame('a', $cohort[0]['handle']);
    }

    public function testEdgeIdsInBbox(): void
    {
        $repo = new GameRepository($this->pdo);
        $a = $repo->upsertNode(20, 47.1, 9.6); $b = $repo->upsertNode(21, 47.2, 9.7);
        $edgeId = $repo->upsertEdge(2002, $a, $b, 100.0, '{"type":"LineString","coordinates":[[9.6,47.1],[9.7,47.2]]}', null, 47.1, 9.6, 47.2, 9.7);
        $this->assertSame([$edgeId], $repo->edgeIdsInBbox(9.5, 47.0, 9.8, 47.3));
        $this->assertSame([], $repo->edgeIdsInBbox(10.0, 48.0, 10.1, 48.1));
    }
}
```

- [ ] **Step 2: Test ausführen (rot)** — `composer test:integration -- --filter GameInvalidationTest` → `allPassesForEdge`/`edgeIdsInBbox` fehlen bzw. Counts falsch.

- [ ] **Step 3: GameRepository anpassen.**
(a) In `passesForEdge`, `distinctRidersTotal`, `distinctRidersSince` jeweils `AND invalidated_at IS NULL` in die WHERE-Klausel ergänzen.
(b) In `firstPassPerUser`: `WHERE p.edge_id = ? AND p.invalidated_at IS NULL`.
(c) In `refreshEdgeDiscovery`: in allen drei Subqueries `AND invalidated_at IS NULL` (bzw. `WHERE edge_id = e.id AND invalidated_at IS NULL`).
(d) Neue Methoden hinzufügen:

```php
    /** Inspector: ALLE Pässe inkl. invalidierte (mit Handle + Route + Invalidierungs-Info). */
    public function allPassesForEdge(int $edgeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.user_id, u.public_handle AS handle, p.route_id,
                    p.ridden_on, p.ridden_at, p.invalidated_at, p.invalid_reason
               FROM game_edge_pass p
               JOIN users u ON u.id = p.user_id
              WHERE p.edge_id = ?
              ORDER BY p.ridden_at ASC, p.id ASC'
        );
        $stmt->execute([$edgeId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<int> Kanten-IDs im BBox (für Region-Recompute). */
    public function edgeIdsInBbox(float $minLon, float $minLat, float $maxLon, float $maxLat): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM game_edge
              WHERE max_lat >= ? AND min_lat <= ? AND max_lon >= ? AND min_lon <= ?
              ORDER BY id'
        );
        $stmt->execute([$minLat, $maxLat, $minLon, $maxLon]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Spiel-Sperre eines Users (Dashboard). */
    public function isUserBanned(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT banned FROM game_user_flag WHERE user_id = ?');
        $stmt->execute([$userId]);
        $v = $stmt->fetchColumn();
        return $v !== false && (int)$v === 1;
    }

    /** Schreibt eine Ingest-Log-Zeile. */
    public function insertIngestLog(int $routeId, int $userId, string $status, int $matchedEdges, int $newPasses, ?array $skipped, ?string $valhallaError, ?int $durationMs): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_ingest_log (route_id, user_id, status, matched_edges, new_passes, skipped_json, valhalla_error, duration_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $routeId, $userId, $status, $matchedEdges, $newPasses,
            $skipped !== null ? json_encode($skipped, JSON_THROW_ON_ERROR) : null,
            $valhallaError, $durationMs,
        ]);
    }
```

- [ ] **Step 4: Test ausführen (grün)** + Regression: `composer test:integration -- --filter Game` (gesamte Game-Suite weiter grün).

- [ ] **Step 5: Commit**

```bash
git add src/Game/GameRepository.php tests/Integration/Game/GameInvalidationTest.php
git commit -m "feat(game-admin): exclude invalidated passes from calcs; add bbox/ban/ingest-log reads"
```

---

### Task 3: GameIngestionService — Ban-Check + Ingest-Log

**Files:** Modify `src/Game/GameIngestionService.php`; Test `tests/Integration/Game/GameIngestBanLogTest.php`

- [ ] **Step 1: Failing test** — gebannter User erzeugt keine Pässe; jeder Lauf schreibt eine `game_ingest_log`-Zeile.

```php
<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Routes\GeometryParser;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class GameIngestBanLogTest extends IntegrationTestCase
{
    private function svc(array $segs): array
    {
        $repo = new GameRepository($this->pdo);
        $config = new GameConfig($this->pdo);
        $svc = new GameIngestionService(new FakeEdgeMatcher($segs), $repo, new EdgeRecalculator($repo, $config), $config, $this->pdo);
        return [$svc, $repo];
    }

    private function route(): \App\Routes\ParsedRoute
    {
        return (new GeometryParser())->parse('{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13]]}');
    }

    private function seg(): MatchedSegment
    {
        $now = new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));
        return new MatchedSegment(1001, 10, 11, 120.0, [[9.65, 47.12], [9.66, 47.13]], 'gravel', 18.0, 8.0, true, $now);
    }

    public function testBannedUserCreatesNoPasses(): void
    {
        $u1 = $this->createUser('armin');
        [$svc, $repo] = $this->svc([$this->seg()]);
        $this->pdo->prepare('INSERT INTO game_user_flag (user_id, banned, reason) VALUES (?,1,?)')->execute([$u1, 'cheat']);
        $now = new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));
        $res = $svc->ingest(1, $u1, $this->route(), true, $now);
        $this->assertSame(0, $res['passes_new']);
        $this->assertTrue(($res['banned'] ?? false));
        $logs = $this->pdo->query('SELECT status FROM game_ingest_log')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertNotEmpty($logs);
    }

    public function testNormalIngestWritesOkLog(): void
    {
        $u1 = $this->createUser('armin');
        [$svc] = $this->svc([$this->seg()]);
        $now = new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));
        $svc->ingest(1, $u1, $this->route(), true, $now);
        $row = $this->pdo->query('SELECT status, new_passes, matched_edges FROM game_ingest_log ORDER BY id DESC LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('ok', $row['status']);
        $this->assertSame(1, (int)$row['new_passes']);
        $this->assertSame(1, (int)$row['matched_edges']);
    }
}
```

- [ ] **Step 2: Test ausführen (rot).**

- [ ] **Step 3: GameIngestionService anpassen.**
(a) Ganz am Anfang von `ingest()` (nach `$now ??= …` und vor dem Matcher-Aufruf NICHT — der Ban-Check kommt VOR dem Matcher, damit gebannte User gar nicht matchen): Ban-Check. Summary um `'banned'=>false` erweitern.
(b) `microtime(true)`-Start für `duration_ms`.
(c) Am Ende (Erfolg) `insertIngestLog(..., 'ok', matched, passes_new, skipped..., null, durationMs)`.
(d) Bei Ban: Log mit status `'ok'` (oder `'pending'`)? → status `'ok'`, `matched_edges=0`, `new_passes=0`, `skipped_json={"banned":1}`, früh zurück.

Konkret (Anfang der Methode, nach `$now ??= …; $summary = […]`):

```php
        $summary['banned'] = false;
        if ($this->repo->isUserBanned($userId)) {
            $summary['banned'] = true;
            $this->repo->insertIngestLog($routeId, $userId, 'ok', 0, 0, ['banned' => 1], null, 0);
            return $summary;
        }
        $startedAt = microtime(true);
```

und unmittelbar vor `return $summary;` (am Erfolgsende):

```php
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->repo->insertIngestLog(
            $routeId, $userId, 'ok', $summary['matched'], $summary['passes_new'],
            [
                'day_cap'     => $summary['skipped_day_cap'],
                'auth_speed'  => $summary['skipped_auth_speed'],
                'auth_hacc'   => $summary['skipped_auth_hacc'],
                'no_motion'   => $summary['skipped_no_motion'],
            ],
            null, $durationMs,
        );
```

> Der `pending`/`failed`-Log-Pfad bei Valhalla-Ausfall wird im Upload-Hook / reingest-Controller geschrieben (Task 13), weil dort die Exception gefangen wird. `GameIngestionService::ingest` selbst lässt die Matcher-Exception weiterhin propagieren (Stufe-1-Verhalten unverändert, §10.9).

- [ ] **Step 4: Test ausführen (grün)** + `composer test:integration -- --filter Game`.

- [ ] **Step 5: Commit**

```bash
git add src/Game/GameIngestionService.php tests/Integration/Game/GameIngestBanLogTest.php
git commit -m "feat(game-admin): ban check + ingest log in ingestion pipeline"
```

---

### Task 4: AdminGuard + AdminHost (testbare Helfer)

**Files:** Create `src/Game/Admin/AdminGuard.php`, `src/Game/Admin/AdminHost.php`; Test `tests/Unit/Game/Admin/AdminGuardTest.php`, `tests/Unit/Game/Admin/AdminHostTest.php`

- [ ] **Step 1: Failing unit tests.**

```php
<?php
declare(strict_types=1);
namespace Tests\Unit\Game\Admin;

use App\Game\Admin\AdminGuard;
use PHPUnit\Framework\TestCase;

final class AdminGuardTest extends TestCase
{
    public function testEmailMatchingCommaList(): void
    {
        $g = new AdminGuard('a@x.de, Admin@Y.de');
        $this->assertTrue($g->isAdminEmail('admin@y.de'));
        $this->assertTrue($g->isAdminEmail('a@x.de'));
        $this->assertFalse($g->isAdminEmail('nope@z.de'));
        $this->assertFalse($g->isAdminEmail(''));
    }
    public function testEmptyConfigDeniesAll(): void
    {
        $this->assertFalse((new AdminGuard(''))->isAdminEmail('a@x.de'));
    }
}
```

```php
<?php
declare(strict_types=1);
namespace Tests\Unit\Game\Admin;

use App\Game\Admin\AdminHost;
use PHPUnit\Framework\TestCase;

final class AdminHostTest extends TestCase
{
    public function testExplicitAdminHost(): void
    {
        $this->assertTrue(AdminHost::isAdmin('admin.grava.world', 'admin.grava.world', 'https://grava.world'));
        $this->assertFalse(AdminHost::isAdmin('grava.world', 'admin.grava.world', 'https://grava.world'));
    }
    public function testDerivedFromAppUrlWhenNoExplicit(): void
    {
        $this->assertTrue(AdminHost::isAdmin('admin.grava.world', '', 'https://grava.world'));
    }
    public function testCaseInsensitiveAndPortStripped(): void
    {
        $this->assertTrue(AdminHost::isAdmin('Admin.Grava.World:443', 'admin.grava.world', ''));
    }
}
```

- [ ] **Step 2: rot ausführen** — `composer test:unit -- --filter AdminGuardTest` / `AdminHostTest`.

- [ ] **Step 3: Implementieren.**

```php
<?php
declare(strict_types=1);
namespace App\Game\Admin;

/** ADMIN_EMAILS-Gate, rein + testbar (keine Response-Seiteneffekte). */
final class AdminGuard
{
    public function __construct(private readonly string $adminEmailsCsv) {}

    public function isAdminEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || trim($this->adminEmailsCsv) === '') {
            return false;
        }
        foreach (explode(',', $this->adminEmailsCsv) as $cand) {
            if (strtolower(trim($cand)) === $email) {
                return true;
            }
        }
        return false;
    }
}
```

```php
<?php
declare(strict_types=1);
namespace App\Game\Admin;

/** Entscheidet, ob ein Request-Host der Admin-Host ist. Rein + testbar. */
final class AdminHost
{
    public static function isAdmin(string $requestHost, string $configuredAdminHost, string $appUrl): bool
    {
        $host = strtolower(trim(explode(':', $requestHost)[0] ?? ''));
        $admin = strtolower(trim($configuredAdminHost));
        if ($admin === '') {
            $base = strtolower((string) (parse_url($appUrl, PHP_URL_HOST) ?: ''));
            $admin = $base !== '' ? 'admin.' . ltrim($base, '.') : '';
        } else {
            $admin = trim(explode(':', $admin)[0] ?? '');
        }
        return $admin !== '' && $host === $admin;
    }
}
```

- [ ] **Step 4: grün** + **Step 5: Commit** (`feat(game-admin): admin guard + host decision helpers`).

---

### Task 5: GameAuditService

**Files:** Create `src/Game/Admin/GameAuditService.php`; Test `tests/Integration/Game/Admin/GameAuditServiceTest.php`

Contract:
```php
final class GameAuditService
{
    public function __construct(private readonly \PDO $pdo) {}
    /** @param array<string,mixed>|null $detail */
    public function record(int $adminUserId, string $action, ?string $target = null, ?array $detail = null): void;
    /** @return list<array<string,mixed>> letzte N Audit-Zeilen (neueste zuerst). */
    public function recent(int $limit = 20): array;
}
```
- [ ] Failing test: `record(7,'config_update','config:hysteresis_factor',['before'=>'1.15','after'=>'1.2'])` → `recent(10)` enthält die Zeile mit dekodiertem `detail_json`.
- [ ] Implementieren (INSERT in `game_audit`; `recent` SELECT ORDER BY id DESC LIMIT, `detail_json` mit `json_decode(...,true)`), grün, Commit.

---

### Task 6: GameConfigAdminService — Validierung + Update

**Files:** Create `src/Game/Admin/GameConfigAdminService.php`; Test `tests/Integration/Game/Admin/GameConfigAdminServiceTest.php`

Contract:
```php
final class GameConfigAdminService
{
    public function __construct(private readonly \PDO $pdo, private readonly GameConfig $config, private readonly GameAuditService $audit) {}
    /** @return array<string,string> Validierungsfehler je key (leer = ok). Bei ok: schreibt game_config + audit (action=config_update, before/after je geänderten Key). @param array<string,string> $values */
    public function update(int $adminUserId, array $values): array;
}
```
Validierungsregeln (Dashboard §5.2, Spec B):
- numerische Keys (`presence_window_days`, `hysteresis_factor`, `pioneer_p0/k/s`, `popularity_c`, `curation_per_hint/per_like`, `auth_min_speed_kmh`, `auth_max_hacc_m`, `start_buffer_m`, `auth_max_speed_kmh`, `mod_max_new_edges_per_min`, `mod_max_passes_per_day`): müssen ≥ 0 sein; `presence_window_days` zusätzlich ≥ 1 (int).
- `presence_decay ∈ {linear}`; `value_combine ∈ {max,sum}`; `auth_require_motion ∈ {0,1,true,false}`.
- Unbekannte Keys ignorieren.

- [ ] Failing tests: (a) `update(7,['hysteresis_factor'=>'1.2'])` → `[]` (keine Fehler), DB-Wert `1.2`, eine Audit-Zeile mit before `1.15`/after `1.2`. (b) `update(7,['presence_window_days'=>'-5'])` → Fehler-Map enthält `presence_window_days`, DB unverändert, KEIN Audit. (c) `update(7,['value_combine'=>'avg'])` → Fehler.
- [ ] Implementieren (pro Key validieren; nur bei 0 Fehlern schreiben; before-Werte via `GameConfig`/`raw`), grün, Commit.

> Hinweis: `GameConfig` cached die Werte pro Objekt. Nach einem Update im selben Request ggf. eine frische `GameConfig`-Instanz nutzen (Tests bauen ihre eigene).

---

### Task 7: GamePassAdminService — Invalidieren/Reaktivieren wirkt (Dashboard §5.5)

**Files:** Create `src/Game/Admin/GamePassAdminService.php`; Test `tests/Integration/Game/Admin/GamePassAdminServiceTest.php`

Contract:
```php
final class GamePassAdminService
{
    public function __construct(private readonly \PDO $pdo, private readonly GameRepository $repo, private readonly EdgeRecalculator $recalc, private readonly GameAuditService $audit) {}
    public function invalidate(int $adminUserId, int $passId, string $reason, ?\DateTimeImmutable $now = null): bool;   // setzt invalidated_*, refreshEdgeDiscovery + recalc der Kante, audit(pass_invalidate); false wenn Pass fehlt
    public function reactivate(int $adminUserId, int $passId, ?\DateTimeImmutable $now = null): bool;                    // null't invalidated_*, refreshEdgeDiscovery + recalc, audit(pass_reactivate)
}
```
- [ ] Failing test (§5.5): Eine Kante mit 2 Usern (distinct=2). `invalidate` von User-2-Pass → `edgeById` zeigt `distinct_riders_total=1`, Besitzer/Wert ohne den Pass; Audit-Zeile `pass_invalidate`. `reactivate` → distinct=2 wieder, Audit `pass_reactivate`.
- [ ] Implementieren: UPDATE des Passes (per `id`), Kante-ID aus dem Pass lesen, `repo->refreshEdgeDiscovery(edgeId)` + `recalc->recalculate(edgeId, now)`, `audit->record(...)`. grün, Commit.

---

### Task 8: GameUserFlagService — Ban/Unban (Dashboard §5.6)

**Files:** Create `src/Game/Admin/GameUserFlagService.php`; Test `tests/Integration/Game/Admin/GameUserFlagServiceTest.php`

Contract:
```php
final class GameUserFlagService
{
    public function __construct(private readonly \PDO $pdo, private readonly GameRepository $repo, private readonly EdgeRecalculator $recalc, private readonly GameAuditService $audit) {}
    public function ban(int $adminUserId, int $userId, string $reason, ?\DateTimeImmutable $now = null): void;   // upsert game_user_flag banned=1; audit(user_game_ban)
    public function unban(int $adminUserId, int $userId, ?\DateTimeImmutable $now = null): void;                 // banned=0; audit(user_game_unban)
}
```
- [ ] Failing test (§5.6): User bannen → `repo->isUserBanned` true; erneute Ingestion (FakeMatcher) erzeugt 0 neue Pässe (nutzt Task-3-Verhalten). Audit-Zeile.
- [ ] Implementieren (`INSERT … ON DUPLICATE KEY UPDATE banned=…, reason=…, updated_at=NOW(3)`), grün, Commit.

> Optional (YAGNI-Grenze): „betroffene Kanten neu rechnen" nach Ban — da Ban nur *künftige* Pässe verhindert und bestehende Pässe nicht invalidiert, ist kein Recompute nötig. Wenn bestehende Pässe eines gebannten Users entfernt werden sollen, geschieht das per Pass-Invalidierung (Task 7). Im Plan bewusst getrennt.

---

### Task 9: GameModerationService — Heuristiken (Review-Queue)

**Files:** Create `src/Game/Admin/GameModerationService.php`; Test `tests/Integration/Game/Admin/GameModerationServiceTest.php`

Contract:
```php
final class GameModerationService
{
    public function __construct(private readonly \PDO $pdo, private readonly GameConfig $config) {}
    /** @return list<array{user_id:int,handle:?string,passes_that_day:int,ridden_on:string}> User über mod_max_passes_per_day. */
    public function highVolumeRiders(int $limit = 50): array;
    /** @return list<array{user_id:int,handle:?string,edge_id:int,ridden_on:string,avg_speed_kmh:float}> verdächtig schnelle Pässe (>auth_max_speed_kmh). NUR markieren. */
    public function suspiciousSpeed(int $limit = 50): array;
}
```
> Stufe 1: `game_edge_pass` speichert keine Geschwindigkeit pro Pass. `suspiciousSpeed` ist daher in Stufe 1 ein **Platzhalter, der konsistent leer** zurückkommt (oder via `game_ingest_log.skipped_json`/`auth_max_speed_kmh`-Heuristik auf Routenebene arbeitet). Test prüft `highVolumeRiders` mit einem Fixture (User mit > Schwelle Pässen an einem Tag) und `suspiciousSpeed` als leere Liste (deterministisch). Markieren-nur (keine Auto-Invalidierung).

- [ ] Failing test: Fixture mit einem User, der an einem Tag > `mod_max_passes_per_day` Pässe hat (Schwelle im Test via `game_config` herabsetzen, z. B. auf 2), erscheint in `highVolumeRiders`. `suspiciousSpeed` → `[]`.
- [ ] Implementieren, grün, Commit.

---

### Task 10: GameAdminService — Health / Monitor / Leaderboard / Inspector (Dashboard §5.7/§5.8)

**Files:** Create `src/Game/Admin/GameAdminService.php`; Test `tests/Integration/Game/Admin/GameAdminServiceTest.php`

Contract:
```php
final class GameAdminService
{
    public function __construct(private readonly \PDO $pdo, private readonly GameRepository $repo, private readonly GameConfig $config) {}
    /** @return array{nodes:int,edges:int,passes_total:int,passes_24h:int,active_riders_90d:int} */
    public function healthMetrics(?\DateTimeImmutable $now = null): array;
    /** @return array{ok:int,pending:int,failed:int,match_rate:float} */
    public function ingestHealth(): array;
    /** @return list<array<string,mixed>> letzte Ingest-Log-Zeilen, optional gefiltert nach status. */
    public function recentIngests(?string $status, int $limit = 50): array;
    /** @return list<array{claimant_id:int,handle:?string,held_edges:int,held_length_m:float,pioneered:int}> */
    public function leaderboard(int $limit = 50): array;
    /** Inspector-Aggregat einer Kante: owner+handle, Wert-Aufschlüsselung (pioneer/popularity/curation/total), n, n90, freshness, Kohorte, alle Pässe inkl. invalidiert, geom. @return array<string,mixed>|null */
    public function edgeInspector(int $edgeId, ?\DateTimeImmutable $now = null): ?array;
}
```
Wert-Aufschlüsselung im Inspector nutzt `GameMath::pioneer/popularity/combineValue` mit `GameConfig`-Werten und `repo->distinctRidersTotal/distinctRidersSince` (also exkl. invalidierter Pässe) — muss zu Golden-Numbers passen (§5.8).
Leaderboard-Aggregate aus `game_edge` (`owner_claimant_id`/`discoverer_claimant_id`/`length_m`) join `game_claimant`/`users`.

- [ ] Failing tests: (a) **§5.8** Kante mit n=12 (12 User je 1 Pass über FakeIngest) → `edgeInspector` liefert `value.pioneer ≈ 50.0`. (b) **§5.7** Leaderboard: 2 User mit unterschiedlich vielen gehaltenen Kanten → korrekte Reihenfolge/Aggregate. (c) `healthMetrics` zählt nodes/edges/passes korrekt; `ingestHealth` zählt ok/pending/failed.
- [ ] Implementieren, grün, Commit.

---

### Task 11: CLI `game:recompute --bbox=`

**Files:** Modify `src/Game/GameRecomputeService.php` (+ `recomputeBbox`), `src/Cli/Commands.php`; Test `tests/Integration/Game/GameRecomputeBboxTest.php`

- [ ] Failing test: nach FakeIngest zweier Kanten in verschiedenen BBoxen rechnet `recomputeBbox(minLon,minLat,maxLon,maxLat,now)` nur die Kanten im Rechteck neu (Rückgabe = Anzahl), Werte identisch zum Live-Pfad.
- [ ] `GameRecomputeService::recomputeBbox(float,float,float,float,?DateTimeImmutable): int` (nutzt `repo->edgeIdsInBbox`, dann `refreshEdgeDiscovery`+`recalculate` je Kante; resettet NUR diese Kanten — eigener `resetEdgeCache(int)` in Repo oder per-Edge-Reset über `updateEdgeCached` mit Defaults vor recalc). CLI: `game:recompute --bbox=minLon,minLat,maxLon,maxLat` parst die Option (bestehender `parseOptions`) und ruft `recomputeBbox`, sonst `recomputeAll`.
- [ ] grün, Commit.

---

### Task 12: Controller + Views (A–F)

**Files:** Create `src/Controllers/Web/Admin/GameAdminController.php`, `src/Controllers/Web/Admin/GameEdgeInspectorController.php`; Create `views/web/admin/game/{health,config,ingest,edge,moderation,players}.php`

Muster: wie `AdminReferralPagesController` (Web-Session via `WebSession::resolve()` → `/login`-Redirect wenn keine Session; sonst `AdminGuard::isAdminEmail` auf `user['email']`; Nicht-Admin → `Response::error('not_found', …, 404)`). Rendern via `WebView::render('admin/game/<view>', $vars)`. Schreibende Aktionen sind POST mit `[$csrf]`-Middleware (in `index.php`), rufen den jeweiligen Admin-Service, schreiben Audit (im Service), `Response::redirect(...)` mit Flash.

Controller-Methoden:
- `GameAdminController`: `health`, `config` (GET), `saveConfig` (POST → `GameConfigAdminService::update`, Flash mit Fehlern oder Erfolg), `recompute` (POST → voll/BBox + Audit), `ingest` (GET Monitor), `reingest` (POST → ruft `GameIngestionService::ingest` über bereits gespeicherte Route, schreibt `failed`/`pending`/`ok`-Log + Audit `ingest_rerun`), `moderation` (GET), `players` (GET Leaderboard).
- `GameEdgeInspectorController`: `show` (GET `/admin/game/edge/{id}` + Suche per `?way_id=`/`?id=`), `invalidatePass` (POST → `GamePassAdminService::invalidate`), `reactivatePass` (POST → `reactivate`), `recalcEdge` (POST → recalc + Audit), `banUser` (POST → `GameUserFlagService::ban`).

Views: server-gerendert, Design-System-Klassen (`.card`, `.data-table`, `.btn-primary`, `.btn-accent`, `.muted`), alle dynamischen Werte mit `htmlspecialchars`. Formulare enthalten `<input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">`. Pionier-Vorschau auf der Config-Seite: kleine Tabelle aus `GameMath::pioneer` für n∈{1,5,10,12,20,30} mit den aktuellen `pioneer_*`-Werten.

- [ ] **Step 1: `php -l` für jede neue Datei** (kein DB nötig).
- [ ] **Step 2: Smoke** über die Routen in Task 13 (manuell/Review).
- [ ] **Step 3: Commit** (`feat(game-admin): admin controllers + views (A–F)`).

> Da kein Web-HTTP-Testharness existiert, ist die *funktionale* Absicherung über die Service-Tests (Tasks 5–10) abgedeckt; die Controller bleiben dünne Adapter (Parsing + Service-Call + Render/Redirect). Zugriffsschutz wird über `AdminGuard` (Task 4, getestet) gewährleistet.

---

### Task 13: Wiring `public/index.php` (Host-Routing) + `.env.example`

**Files:** Modify `public/index.php`, `.env.example`

- [ ] **Step 1: Host-Flag bestimmen** (im HTTP-Dispatch, nach `$request = Request::fromGlobals();`):

```php
use App\Game\Admin\AdminHost;
$requestHost = $request->header('Host', '');
$isAdminHost = AdminHost::isAdmin($requestHost, (string)$config->get('ADMIN_HOST',''), (string)$config->get('APP_URL',''));
```

- [ ] **Step 2: Admin-Controller verdrahten** (bei den `$webAdminRef`-Zeilen):

```php
$adminGuard = new \App\Game\Admin\AdminGuard((string)$config->get('ADMIN_EMAILS',''));
$gameAudit  = new \App\Game\Admin\GameAuditService(Db::pdo());
$gameAdminSvc = new \App\Game\Admin\GameAdminService(Db::pdo(), $gameRepo, $gameConfig);
$gameCfgAdmin = new \App\Game\Admin\GameConfigAdminService(Db::pdo(), $gameConfig, $gameAudit);
$gamePassAdmin = new \App\Game\Admin\GamePassAdminService(Db::pdo(), $gameRepo, $gameRecalc, $gameAudit);
$gameUserFlag = new \App\Game\Admin\GameUserFlagService(Db::pdo(), $gameRepo, $gameRecalc, $gameAudit);
$gameMod = new \App\Game\Admin\GameModerationService(Db::pdo(), $gameConfig);
$webGameAdmin = new \App\Controllers\Web\Admin\GameAdminController($webSession, $auth, $adminGuard, $gameAdminSvc, $gameCfgAdmin, $gameMod, $gameRecompute, $gameAudit, $routeService, new GeometryParser(), $gameIngest, $basePath.'/views');
$webGameEdge  = new \App\Controllers\Web\Admin\GameEdgeInspectorController($webSession, $auth, $adminGuard, $gameAdminSvc, $gamePassAdmin, $gameUserFlag, $gameRecalc, $gameRepo, $basePath.'/views');
```

- [ ] **Step 3: Routen host-abhängig registrieren.** Die `/admin/*`-Routen (bestehend + neu) NUR registrieren, wenn `$isAdminHost`. Die übrigen API/Web-Routen NUR, wenn `!$isAdminHost`. Auth-Routen (`/login`, `/logout`, `/auth/web-refresh`) in BEIDEN Hosts registrieren (Admin braucht Login). Konkret den bestehenden Routen-Block in zwei Gruppen aufteilen:

```php
if ($isAdminHost) {
    // Auth (Login) + alle Admin-Seiten
    $router->get('/login',  fn($r) => $webAuth->showLogin($r));
    $router->post('/login', fn($r) => $webAuth->doLogin($r), [$csrf]);
    $router->post('/logout',fn($r) => $webAuth->doLogout($r), [$csrf]);
    $router->get('/auth/web-refresh', fn($r) => $webRefresh->handle($r));
    $router->get('/',       fn($r) => Response::redirect('/admin/game'));
    // bestehend:
    $router->get('/admin/referrals',     fn($r) => $webAdminRef->index($r));
    $router->get('/admin/referrals.csv', fn($r) => $webAdminRef->csv($r));
    // Game-Admin A–F:
    $router->get('/admin/game',              fn($r) => $webGameAdmin->health($r));
    $router->get('/admin/game/config',       fn($r) => $webGameAdmin->config($r));
    $router->post('/admin/game/config',      fn($r) => $webGameAdmin->saveConfig($r), [$csrf]);
    $router->post('/admin/game/recompute',   fn($r) => $webGameAdmin->recompute($r),  [$csrf]);
    $router->get('/admin/game/ingest',       fn($r) => $webGameAdmin->ingest($r));
    $router->post('/admin/game/ingest/{route_id}', fn($r) => $webGameAdmin->reingest($r), [$csrf]);
    $router->get('/admin/game/moderation',   fn($r) => $webGameAdmin->moderation($r));
    $router->get('/admin/game/players',      fn($r) => $webGameAdmin->players($r));
    $router->get('/admin/game/edge/{id}',    fn($r) => $webGameEdge->show($r));
    $router->get('/admin/game/edge',         fn($r) => $webGameEdge->show($r)); // Suche ?way_id=/?id=
    $router->post('/admin/game/pass/{pass_id}/invalidate', fn($r) => $webGameEdge->invalidatePass($r), [$csrf]);
    $router->post('/admin/game/pass/{pass_id}/reactivate', fn($r) => $webGameEdge->reactivatePass($r), [$csrf]);
    $router->post('/admin/game/edge/{id}/recalc', fn($r) => $webGameEdge->recalcEdge($r), [$csrf]);
    $router->post('/admin/game/user/{user_id}/ban', fn($r) => $webGameEdge->banUser($r), [$csrf]);
} else {
    // ... der GESAMTE bestehende API + Web-Routen-Block, ABER ohne die
    // beiden /admin/referrals*-Zeilen (die wandern in den Admin-Host).
}
```

> Wichtig: Der `$runInternal`-Block (interne Cron/Migrate-Endpunkte) bleibt host-unabhängig erreichbar (oder ebenfalls in den `!isAdminHost`-Zweig). Healthcheck `/healthz` in beiden Zweigen registrieren.

- [ ] **Step 4: `.env.example`** ergänzen (bei den App-/Domain-Keys):

```bash
# Admin-Dashboard-Host (Subdomain). Leer = aus APP_URL abgeleitet (admin.<host>).
ADMIN_HOST=admin.grava.world
```

- [ ] **Step 5: Verify** — `php -l public/index.php` + `composer test` (alles grün; Routing-Smoke per Review).
- [ ] **Step 6: Commit** (`feat(game-admin): host-aware routing for admin.grava.world`).

---

### Task 14: Testbericht-Erweiterung + DoD

**Files:** Modify `scripts/game_report.php`, `backend/GAME_STAGE1_TESTREPORT.md`, `docs/API.md` (Hinweis), Create `backend/GAME_DASHBOARD_SETUP.md`

- [ ] Report-Generator um die Dashboard-Akzeptanzkriterien (§5 1–8) → Test-Mapping erweitern; `composer test && php scripts/game_report.php`.
- [ ] `backend/GAME_DASHBOARD_SETUP.md`: Subdomain-Setup (DNS `admin` → gleicher Docroot; vhost/`.htaccess`-Hinweis; `ADMIN_HOST`/`ADMIN_EMAILS`-env), Hinweis host-gebundene Session.
- [ ] `docs/API.md`: kurzer Hinweis, dass `/admin/*` nur unter `admin.grava.world` erreichbar ist und Spielwerte sich durch Admin-Aktionen rückwirkend ändern können (iOS: Cache nicht als unveränderlich behandeln).
- [ ] DoD (Design-Doc) abhaken. Commit (`docs(game-admin): dashboard test report + setup + API note`).

---

## Self-Review (Plan-Autor)

**Spec-Abdeckung (Dashboard-Spec):** §1 Zugriff/Sicherheit → Tasks 4, 12, 13. §2.1 ingest_log → Tasks 1, 3. §2.2 audit → Task 5. §2.3 Invalidierung → Tasks 1, 2, 7. §2.4 user_flag → Tasks 1, 8. §3 Seiten A–F → Tasks 10, 12. §4 Heuristik-Params → Tasks 1, 9. §5 Akzeptanz 1–8 → 1:Task4/13, 2:Task6, 3:Task11, 4:Task12-reingest, 5:Task7, 6:Task8, 7:Task10, 8:Task10. §6 DoD → Task 14. Backend §5-Rückwirkung → Tasks 2, 3.

**Typ-Konsistenz:** Service-Konstruktoren (`PDO`, `GameRepository`, `EdgeRecalculator`, `GameConfig`, `GameAuditService`) konsistent über Tasks 5–10, 13. `EdgeRecalculator::recalculate(int,?DateTimeImmutable)` + `GameRepository`-Reads unverändert.

**Bekannte Grenzen (bewusst):** (a) Kein Web-HTTP-Testharness → Controller dünn, Logik in getesteten Services. (b) `suspiciousSpeed`-Heuristik in Stufe 1 leer (kein per-Pass-Speed gespeichert). (c) Ban verhindert nur künftige Pässe; rückwirkendes Entfernen via Pass-Invalidierung.
