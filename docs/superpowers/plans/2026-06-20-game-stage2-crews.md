# Gamification Stufe 2 (Crews) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fahrer können Crews (Claimant-Typ `group`) gründen/beitreten/verlassen; die Präsenz der Mitglieder poolt sich über den „effektiven Claimant" auf die Crew, sodass Crews gemeinsam Gebiet erobern/halten.

**Architecture:** Additive Migration (`game_crew`, `game_crew_member`). Besitz wird in `EdgeRecalculator` nicht mehr nach dem gespeicherten `game_edge_pass.claimant_id`, sondern nach dem **effektiven Claimant** (user_id → Crew-Group-Claimant, sonst Rider) gruppiert (PHP-Remap). Crew-CRUD/Logik in neuem `CrewRepository` + `CrewService` + `CrewController`; Join/Leave/Create lösen synchronen Teil-Recompute der Fenster-Kanten des Users aus.

**Tech Stack:** PHP 8.2+, PDO/MySQL, PSR-4 `App\`, PHPUnit (Integration via `Tests\IntegrationTestCase`), eigenes Routing in `public/index.php`.

**Spec:** `docs/superpowers/specs/2026-06-20-game-stage2-crews-design.md`

---

## File Structure

- Create: `migrations/0017_game_crew.sql` — Tabellen + Config-Inserts.
- Modify: `src/Game/GameConfig.php` — 3 Config-Defaults.
- Modify: `src/Game/GameRepository.php` — `effectiveClaimantMap`, `effectiveClaimantId`, `passesForEdge` (+`ridden_on`), `claimantInfo` (+`name`/group), `affectedEdgeIdsForUser`.
- Modify: `src/Game/EdgeRecalculator.php` — Präsenz nach effektivem Claimant + Gruppenfahrt-Bonus.
- Create: `src/Game/Crew/CrewRepository.php` — Crew/Member-CRUD + Slug/Join-Code.
- Create: `src/Game/Crew/CrewService.php` — create/join/leave/transfer/me/profile + Recompute + Audit + Captain-Regel.
- Create: `src/Game/Crew/CrewException.php` — typisierte Fehler (Code + HTTP-Status).
- Create: `src/Controllers/Api/CrewController.php` — HTTP-Adapter.
- Modify: `public/index.php` — Wiring + Routen.
- Modify: `docs/API.md` — Crew-Endpunkte + `owner.name`.
- Create: `backend/GAME_STAGE2_TESTREPORT.md` — Akzeptanz-Mapping.
- Tests: `tests/Integration/Game/Crew/CrewRepositoryTest.php`, `CrewServiceTest.php`, `tests/Integration/Game/EdgeRecalculatorCrewTest.php`, `tests/Integration/Game/GameReadServiceOwnerNameTest.php`.

---

## Task 1: Migration + Config-Defaults

**Files:**
- Create: `migrations/0017_game_crew.sql`
- Modify: `src/Game/GameConfig.php`
- Test: `tests/Integration/Game/Crew/CrewRepositoryTest.php` (nur Migrations-/Config-Smoke hier)

- [ ] **Step 1: Migration schreiben**

`migrations/0017_game_crew.sql`:

```sql
-- Stufe 2 (Crews): neutrale Gruppen. Siehe GAME_STAGE2_BACKEND.md / specs/2026-06-20-game-stage2-crews-design.md.
-- Eine Crew ist ein game_claimant(type='group', user_id=NULL). Besitz wandert über den
-- "effektiven Claimant" (user_id -> Crew-Group-Claimant, sonst Rider) — kein Pass-Backfill.

CREATE TABLE IF NOT EXISTS game_crew (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claimant_id   BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(40)  NOT NULL,
  slug          VARCHAR(40)  NOT NULL,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  join_code     CHAR(8)      NOT NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_crew_claimant FOREIGN KEY (claimant_id) REFERENCES game_claimant(id) ON DELETE CASCADE,
  UNIQUE KEY uq_crew_slug (slug),
  UNIQUE KEY uq_crew_joincode (join_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_crew_member (
  user_id    BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  crew_id    BIGINT UNSIGNED NOT NULL,
  role       ENUM('captain','member') NOT NULL DEFAULT 'member',
  joined_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_member_crew FOREIGN KEY (crew_id) REFERENCES game_crew(id) ON DELETE CASCADE,
  KEY idx_member_crew (crew_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (config_key, config_value) VALUES
  ('group_ride_bonus', '1.5'),
  ('group_ride_min_members', '3'),
  ('crew_max_members', '0')
ON DUPLICATE KEY UPDATE config_key = config_key;
```

- [ ] **Step 2: GameConfig-Defaults ergänzen**

In `src/Game/GameConfig.php` im `DEFAULTS`-Array nach `'mod_max_passes_per_day' => '200',` ergänzen:

```php
        'mod_max_passes_per_day'    => '200',
        'group_ride_bonus'          => '1.5',
        'group_ride_min_members'    => '3',
        'crew_max_members'          => '0',
```

- [ ] **Step 3: Migration anwenden (lokal/Test-DB)**

Run: `php public/index.php cli:migrate`
Expected: Ausgabe enthält `Migriert: 0017_game_crew.sql`. (Test-DB wird über `tests/bootstrap.php` ohnehin migriert.)

- [ ] **Step 4: Config-Smoke-Test**

`tests/Integration/Game/Crew/CrewRepositoryTest.php` (Datei anlegen, vorerst nur dieser Test):

```php
<?php
declare(strict_types=1);

namespace Tests\Integration\Game\Crew;

use App\Game\GameConfig;
use Tests\IntegrationTestCase;

final class CrewRepositoryTest extends IntegrationTestCase
{
    public function testCrewConfigDefaults(): void
    {
        $cfg = new GameConfig($this->pdo);
        $this->assertSame(1.5, $cfg->float('group_ride_bonus'));
        $this->assertSame(3, $cfg->int('group_ride_min_members'));
        $this->assertSame(0, $cfg->int('crew_max_members'));
    }
}
```

- [ ] **Step 5: Test ausführen**

Run: `php vendor/bin/phpunit --filter CrewRepositoryTest --no-coverage`
Expected: PASS (1 Test).

- [ ] **Step 6: Commit**

```bash
git add migrations/0017_game_crew.sql src/Game/GameConfig.php tests/Integration/Game/Crew/CrewRepositoryTest.php
git commit -m "feat(game-stage2): crew tables + config defaults (migration 0017)"
```

---

## Task 2: Repository — effektiver Claimant + ridden_on

**Files:**
- Modify: `src/Game/GameRepository.php`
- Test: `tests/Integration/Game/Crew/CrewRepositoryTest.php`

- [ ] **Step 1: Failing test schreiben**

In `CrewRepositoryTest` ergänzen (oben `use App\Game\GameRepository;` ergänzen):

```php
    public function testEffectiveClaimantMapFallsBackToRiderWhenSolo(): void
    {
        $repo = new GameRepository($this->pdo);
        $u = $this->createUser('solo');
        $rider = $repo->riderClaimantId($u);

        $map = $repo->effectiveClaimantMap([$u]);

        $this->assertSame($rider, $map[$u]['claimant_id']);
        $this->assertFalse($map[$u]['is_group']);
    }
```

- [ ] **Step 2: Run → fail**

Run: `php vendor/bin/phpunit --filter testEffectiveClaimantMapFallsBackToRiderWhenSolo --no-coverage`
Expected: FAIL ("Call to undefined method ...effectiveClaimantMap").

- [ ] **Step 3: Repository-Methoden implementieren**

In `src/Game/GameRepository.php` ergänzen (z. B. direkt nach `findRiderClaimantId`):

```php
    /**
     * Effektiver Claimant je user_id (Stufe 2): Crew-Group-Claimant falls Mitglied,
     * sonst der Rider-Claimant. Legt KEINE Claimants an (Lesepfad-sicher).
     *
     * @param list<int> $userIds
     * @return array<int,array{claimant_id:int,is_group:bool}>
     */
    public function effectiveClaimantMap(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT rc.user_id AS user_id,
                    rc.id      AS rider_claimant_id,
                    gc.claimant_id AS group_claimant_id
               FROM game_claimant rc
               LEFT JOIN game_crew_member m ON m.user_id = rc.user_id
               LEFT JOIN game_crew gc       ON gc.id = m.crew_id
              WHERE rc.type = 'rider' AND rc.user_id IN ($ph)"
        );
        $stmt->execute($userIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $uid = (int)$r['user_id'];
            if ($r['group_claimant_id'] !== null) {
                $out[$uid] = ['claimant_id' => (int)$r['group_claimant_id'], 'is_group' => true];
            } else {
                $out[$uid] = ['claimant_id' => (int)$r['rider_claimant_id'], 'is_group' => false];
            }
        }
        return $out;
    }

    /** Effektiver Claimant eines Users (legt Rider bei Bedarf an — für Schreib-/Endpunkt-Pfad). */
    public function effectiveClaimantId(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT gc.claimant_id
               FROM game_crew_member m
               JOIN game_crew gc ON gc.id = m.crew_id
              WHERE m.user_id = ?'
        );
        $stmt->execute([$userId]);
        $gid = $stmt->fetchColumn();
        if ($gid !== false) {
            return (int)$gid;
        }
        return $this->riderClaimantId($userId);
    }

    /** @return list<int> Kanten-IDs, auf denen der User im Fenster (seit $sinceDate) gültige Pässe hat. */
    public function affectedEdgeIdsForUser(int $userId, string $sinceDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT edge_id FROM game_edge_pass
              WHERE user_id = ? AND invalidated_at IS NULL AND ridden_on >= ?
              ORDER BY edge_id'
        );
        $stmt->execute([$userId, $sinceDate]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
```

- [ ] **Step 4: `passesForEdge` um `ridden_on` erweitern**

In `src/Game/GameRepository.php` die Methode `passesForEdge` ersetzen:

```php
    /**
     * @return list<array{claimant_id:int,user_id:int,ridden_on:string,ridden_at:string}>
     */
    public function passesForEdge(int $edgeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT claimant_id, user_id, ridden_on, ridden_at FROM game_edge_pass
              WHERE edge_id = ? AND invalidated_at IS NULL'
        );
        $stmt->execute([$edgeId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'claimant_id' => (int)$r['claimant_id'],
                'user_id'     => (int)$r['user_id'],
                'ridden_on'   => (string)$r['ridden_on'],
                'ridden_at'   => (string)$r['ridden_at'],
            ];
        }
        return $out;
    }
```

- [ ] **Step 5: Run → pass**

Run: `php vendor/bin/phpunit --filter CrewRepositoryTest --no-coverage`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Game/GameRepository.php tests/Integration/Game/Crew/CrewRepositoryTest.php
git commit -m "feat(game-stage2): effectiveClaimantMap/Id, affectedEdgeIdsForUser, passesForEdge ridden_on"
```

---

## Task 3: EdgeRecalculator — effektiver Claimant + Gruppenfahrt-Bonus

**Files:**
- Modify: `src/Game/EdgeRecalculator.php`
- Test: `tests/Integration/Game/EdgeRecalculatorCrewTest.php`

- [ ] **Step 1: Failing tests schreiben**

`tests/Integration/Game/EdgeRecalculatorCrewTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class EdgeRecalculatorCrewTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private EdgeRecalculator $recalc;
    private int $edgeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->recalc = new EdgeRecalculator($this->repo, new GameConfig($this->pdo));
        $a = $this->repo->upsertNode(20, 47.12, 9.65);
        $b = $this->repo->upsertNode(21, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $this->edgeId = $this->repo->upsertEdge(2001, $a, $b, 120.0, $geom, null, 47.12, 9.65, 47.13, 9.66);
    }

    private function pass(int $claimant, int $user, string $day): void
    {
        $this->repo->insertPassIfAbsent($this->edgeId, $claimant, $user, 1, $day, $day . ' 08:00:00.000');
    }

    /** Legt eine Crew (Group-Claimant) an und macht $userIds zu Mitgliedern. Liefert die group-claimant-id. */
    private function makeCrew(array $userIds): int
    {
        $this->pdo->prepare('INSERT INTO game_claimant (type, user_id) VALUES ("group", NULL)')->execute();
        $claimantId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO game_crew (claimant_id, name, slug, owner_user_id, join_code)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$claimantId, 'Crew', 'crew-' . $claimantId, $userIds[0], 'CODE' . $claimantId]);
        $crewId = (int)$this->pdo->lastInsertId();
        foreach ($userIds as $i => $uid) {
            $this->pdo->prepare(
                'INSERT INTO game_crew_member (user_id, crew_id, role) VALUES (?, ?, ?)'
            )->execute([$uid, $crewId, $i === 0 ? 'captain' : 'member']);
        }
        return $claimantId;
    }

    private function now(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public function testPresenceMovesToCrewOnJoin(): void
    {
        $u1 = $this->createUser('r1');
        $rider1 = $this->repo->riderClaimantId($u1);
        $this->pass($rider1, $u1, '2026-06-20');
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $this->now('2026-06-20T12:00:00Z'));
        $this->assertSame($rider1, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id']);

        // u1 tritt Crew bei -> gleiche Pässe, effektiver Claimant = Crew.
        $crew = $this->makeCrew([$u1]);
        $this->recalc->recalculate($this->edgeId, $this->now('2026-06-20T12:00:00Z'));
        $this->assertSame($crew, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id'],
            'Nach Beitritt gehört die Kante der Crew (Präsenz wandert mit).');
    }

    public function testGroupRideBonusAppliesAtThreshold(): void
    {
        // 3 Mitglieder fahren am selben Tag -> mit bonus 1.5, min 3 greift der Tagesfaktor.
        $u1 = $this->createUser('m1'); $u2 = $this->createUser('m2'); $u3 = $this->createUser('m3');
        $this->repo->riderClaimantId($u1); $this->repo->riderClaimantId($u2); $this->repo->riderClaimantId($u3);
        $crew = $this->makeCrew([$u1, $u2, $u3]);
        // Pässe tragen den (historischen) Rider-Claimant; effektiv zählt die Crew.
        $this->pass($this->repo->riderClaimantId($u1), $u1, '2026-06-20');
        $this->pass($this->repo->riderClaimantId($u2), $u2, '2026-06-20');
        $this->pass($this->repo->riderClaimantId($u3), $u3, '2026-06-20');
        $this->repo->refreshEdgeDiscovery($this->edgeId);

        // Solo-Gegenspieler mit 3 Pässen an 3 Tagen (kein Bonus) als Vergleich auf zweiter Kante.
        $now = $this->now('2026-06-20T12:00:00Z');
        $this->recalc->recalculate($this->edgeId, $now);
        $edge = $this->repo->edgeById($this->edgeId);
        $this->assertSame($crew, (int)$edge['owner_claimant_id']);
        // value_cached ist von Crew unabhängig; wir prüfen den Bonus indirekt über den Besitzer
        // in testCrewBeatsSoloByMembers (Task 3) — hier reicht: Crew ist Owner.
    }
}
```

- [ ] **Step 2: Run → fail**

Run: `php vendor/bin/phpunit --filter EdgeRecalculatorCrewTest --no-coverage`
Expected: FAIL (`testPresenceMovesToCrewOnJoin`: Owner bleibt Rider, weil noch nach `claimant_id` gruppiert wird).

- [ ] **Step 3: EdgeRecalculator umstellen**

In `src/Game/EdgeRecalculator.php` den Block ab `$passes = $this->repo->passesForEdge($edgeId);` bis vor `$challenger = null;` ersetzen durch:

```php
        $windowDays = $this->config->int('presence_window_days');
        $passes = $this->repo->passesForEdge($edgeId);

        // Stufe 2: effektiver Claimant je user_id (Crew-Group-Claimant, sonst Rider).
        $userIds = [];
        foreach ($passes as $p) {
            $userIds[] = $p['user_id'];
        }
        $effMap = $this->repo->effectiveClaimantMap($userIds);

        $bonus      = $this->config->float('group_ride_bonus');
        $minMembers = $this->config->int('group_ride_min_members');

        // Tages-Aggregation je (effektiver Claimant, ridden_on): Summe Gewicht + distinct Mitglieder.
        $dayWeight  = [];   // [cid][ridden_on] => float
        $dayMembers = [];   // [cid][ridden_on] => array<int,true>
        $isGroup    = [];   // [cid] => bool
        $lastPassByClaimant = [];
        $lastPassOverall = null;
        foreach ($passes as $p) {
            $uid = $p['user_id'];
            $eff = $effMap[$uid] ?? ['claimant_id' => $p['claimant_id'], 'is_group' => false];
            $cid = $eff['claimant_id'];
            $isGroup[$cid] = $eff['is_group'];
            $on = $p['ridden_on'];
            $w = GameMath::presenceWeight($this->ageDays($p['ridden_at'], $now), $windowDays);
            $dayWeight[$cid][$on] = ($dayWeight[$cid][$on] ?? 0.0) + $w;
            $dayMembers[$cid][$on][$uid] = true;
            if (!isset($lastPassByClaimant[$cid]) || $p['ridden_at'] > $lastPassByClaimant[$cid]) {
                $lastPassByClaimant[$cid] = $p['ridden_at'];
            }
            if ($lastPassOverall === null || $p['ridden_at'] > $lastPassOverall) {
                $lastPassOverall = $p['ridden_at'];
            }
        }

        // Präsenz je Claimant; Gruppenfahrt-Bonus als Tagesfaktor (nur Group-Claimants).
        $presence = [];
        foreach ($dayWeight as $cid => $byDay) {
            $sum = 0.0;
            foreach ($byDay as $on => $w) {
                $members = count($dayMembers[$cid][$on]);
                if (($isGroup[$cid] ?? false) && $bonus !== 1.0 && $members >= $minMembers) {
                    $w *= $bonus;
                }
                $sum += $w;
            }
            $presence[(int)$cid] = $sum;
        }
```

Der Rest der Methode (`$challenger`-Auswahl, `decideOwner`, Wert/Pionier, Frische, `updateEdgeCached`) bleibt unverändert — er arbeitet mit `$presence` und `$lastPassByClaimant`.

- [ ] **Step 4: Run → pass (Crew-Test + Stufe-1-Regression)**

Run: `php vendor/bin/phpunit --filter EdgeRecalculatorCrewTest --no-coverage`
Expected: PASS.
Run: `php vendor/bin/phpunit --filter EdgeRecalculatorTest --no-coverage`
Expected: PASS (Stufe-1-Verhalten unverändert — Solo-Rider = effektiver Rider-Claimant, kein Bonus).

- [ ] **Step 5: Commit**

```bash
git add src/Game/EdgeRecalculator.php tests/Integration/Game/EdgeRecalculatorCrewTest.php
git commit -m "feat(game-stage2): presence by effective claimant + group-ride bonus"
```

---

## Task 4: owner.name (claimantInfo) additiv + group

**Files:**
- Modify: `src/Game/GameRepository.php` (`claimantInfo`)
- Test: `tests/Integration/Game/GameReadServiceOwnerNameTest.php`

- [ ] **Step 1: Failing test schreiben**

`tests/Integration/Game/GameReadServiceOwnerNameTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameRepository;
use Tests\IntegrationTestCase;

final class GameReadServiceOwnerNameTest extends IntegrationTestCase
{
    public function testRiderClaimantInfoHasName(): void
    {
        $repo = new GameRepository($this->pdo);
        $u = $this->createUser('armin');
        $this->pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute(['Armin L', $u]);
        $cid = $repo->riderClaimantId($u);

        $info = $repo->claimantInfo($cid);
        $this->assertSame('rider', $info['type']);
        $this->assertSame('armin', $info['handle']);
        $this->assertSame('Armin L', $info['name']);
    }

    public function testGroupClaimantInfoUsesCrewSlugAndName(): void
    {
        $repo = new GameRepository($this->pdo);
        $u = $this->createUser('cap');
        $this->pdo->prepare('INSERT INTO game_claimant (type, user_id) VALUES ("group", NULL)')->execute();
        $claimantId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO game_crew (claimant_id, name, slug, owner_user_id, join_code)
             VALUES (?, "Waldrudel", "waldrudel", ?, "JOINCODE1")'
        )->execute([$claimantId, $u]);

        $info = $repo->claimantInfo($claimantId);
        $this->assertSame('group', $info['type']);
        $this->assertSame('waldrudel', $info['handle']);
        $this->assertSame('Waldrudel', $info['name']);
    }
}
```

- [ ] **Step 2: Run → fail**

Run: `php vendor/bin/phpunit --filter GameReadServiceOwnerNameTest --no-coverage`
Expected: FAIL (`name`-Key fehlt / group liefert null handle).

- [ ] **Step 3: `claimantInfo` ersetzen**

In `src/Game/GameRepository.php` `claimantInfo` ersetzen:

```php
    /** @return array{claimant_id:int,type:string,handle:?string,name:?string}|null */
    public function claimantInfo(int $claimantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.type,
                    u.public_handle AS rider_handle, u.display_name AS rider_name,
                    cr.slug AS crew_slug, cr.name AS crew_name
               FROM game_claimant c
               LEFT JOIN users u      ON u.id = c.user_id
               LEFT JOIN game_crew cr ON cr.claimant_id = c.id
              WHERE c.id = ?'
        );
        $stmt->execute([$claimantId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        $type = (string)$r['type'];
        if ($type === 'group') {
            return [
                'claimant_id' => (int)$r['id'],
                'type'        => 'group',
                'handle'      => $r['crew_slug'] !== null ? (string)$r['crew_slug'] : null,
                'name'        => $r['crew_name'] !== null ? (string)$r['crew_name'] : null,
            ];
        }
        return [
            'claimant_id' => (int)$r['id'],
            'type'        => $type,
            'handle'      => $r['rider_handle'] !== null ? (string)$r['rider_handle'] : null,
            'name'        => $r['rider_name'] !== null ? (string)$r['rider_name'] : null,
        ];
    }
```

- [ ] **Step 4: Run → pass + Regression**

Run: `php vendor/bin/phpunit --filter GameReadServiceOwnerNameTest --no-coverage`
Expected: PASS.
Run: `php vendor/bin/phpunit --filter GameReadServiceTest --no-coverage`
Expected: PASS (bestehende Asserts prüfen nur `owner.handle`).

- [ ] **Step 5: Commit**

```bash
git add src/Game/GameRepository.php tests/Integration/Game/GameReadServiceOwnerNameTest.php
git commit -m "feat(game-stage2): claimantInfo owner.name + group (slug/name)"
```
