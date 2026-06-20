# Gamification Stufe 1 (Solo-Claim) — Implementierungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (empfohlen) oder superpowers:executing-plans, um diesen Plan Task für Task umzusetzen. Steps nutzen Checkbox-Syntax (`- [ ]`) zum Tracking.

**Goal:** Ein Territorial-Spiel im Backend, das jede hochgeladene Route per Valhalla-Map-Matching auf OSM-Kanten legt, pro Kante authentizitätsgeprüfte `EdgePass`-Events erzeugt und daraus Besitzer/Wert/Frische live berechnet — vollständig durch deterministische Tests (Spec §10) abgesichert, bevor iOS startet.

**Architecture:** Neues Modul `src/Game/` (PSR-4 `App\Game\`) mit reinen Berechnungs-Klassen (Unit-testbar), einem mockbaren `EdgeMatcher`-Interface (Fake für Tests, Valhalla-Adapter für echt), `GameRepository` (PDO), `GameIngestionService` (Orchestrierung) und `GameController` (Endpunkte). Persistenz event-sourced: `game_edge_pass` ist die Quelle der Wahrheit, `game_edge.*_cached` ist materialisierte Sicht. Hook nicht-blockierend nach `RouteService::createOrAddVersion()`-Commit.

**Tech Stack:** PHP 8.2, PDO/MySQL, PHPUnit 11, bestehender `App\Heatmap\ValhallaClient`, `App\Support\Clock`, `App\Http\{Router,Response}`.

---

## Referenz: Spec

Die vollständige Spec liegt unter `~/Documents/GravelExplorer/backend/GAME_STAGE1_BACKEND.md`. Dieser Plan setzt §3 (Datenmodell), §4 (Ingestion), §5 (Berechnung), §6 (Endpunkte), §7 (Recompute), §9 (Test-Strategie), §10 (Akzeptanzkriterien) um.

### Wichtige Design-Entscheidungen (vom Plan getroffen)

1. **Knoten-Identität:** Die Spec will `(way_id, node_a_osm, node_b_osm)`. Valhallas `trace_attributes` liefert nativ Graph-Node-IDs, keine OSM-Node-IDs. Wir abstrahieren das hinter dem `EdgeMatcher`-Interface: Es liefert pro Segment zwei stabile Integer-Knoten-Refs (`nodeARef`, `nodeBRef`), die in `game_node.osm_node_id` landen. Der Fake-Matcher liefert synthetische Refs aus dem Fixture; der Valhalla-Adapter nutzt die verfügbaren Node-Refs (Graph-Node-ID als Surrogat, dokumentiert in `VALHALLA_SETUP.md`). Schema-Spalte heißt weiterhin `osm_node_id` (forward-compat).
2. **Auth-Aggregate im Matcher:** Geschwindigkeit/Accuracy/Motion werden pro Segment als Aggregate (`avgSpeedKmh`, `maxHaccM`, `hasMotion`) vom Matcher geliefert, damit `GameIngestionService` deterministisch und parser-unabhängig bleibt. Der Valhalla-Adapter berechnet sie aus Track + Match; der Fake-Matcher aus dem Fixture.
3. **`game_config`:** Als key/value-Tabelle (seed in Migration) plus `GameConfig`-Klasse mit Default-Fallback. Ohne Deploy änderbar (Spec §3.5 / DoD).
4. **Determinismus:** `now` wird über `App\Support\Clock` bezogen, aber in Services als injizierbarer `?DateTimeImmutable $now` Parameter durchgereicht (Test-Zeit).

---

## Datei-Struktur

**Neu erstellen:**

| Datei | Verantwortung |
|---|---|
| `migrations/0015_game_stage1.sql` | Tabellen `game_claimant/node/edge/edge_pass/config` + Seed der Config-Defaults |
| `src/Game/GameConfig.php` | Liest `game_config` (key/value) mit Default-Fallback; getypte Getter |
| `src/Game/GameMath.php` | Reine Funktionen: `pioneer()`, `popularity()`, `presenceWeight()`, `combineValue()`, `decideOwner()` |
| `src/Game/MatchedSegment.php` | Value-Object: gematchtes OSM-Segment + Auth-Aggregate |
| `src/Game/EdgeMatcher.php` | Interface: `match(ParsedRoute): array<MatchedSegment>` |
| `src/Game/FakeEdgeMatcher.php` | Test-Matcher: liefert feste Segmente aus einem Fixture |
| `src/Game/ValhallaEdgeMatcher.php` | Echt-Adapter: nutzt `ValhallaClient` + Track für Auth-Aggregate |
| `src/Game/GameRepository.php` | Alle PDO-Operationen (upsert node/edge, insert pass idempotent, recompute-Queries, reads) |
| `src/Game/GameIngestionService.php` | Orchestriert: match → auth-filter → pass → pionier → recompute; nicht-blockierend |
| `src/Game/GameRecomputeService.php` | Voller Recompute aus `game_edge_pass` (CLI `game:recompute`) |
| `src/Game/EdgeValue.php` | Value-Object: aufgeschlüsselter Wert (`total/pioneer/popularity/curation`) |
| `src/Controllers/Api/GameController.php` | Endpunkte `GET /game/edges`, `/game/edges/{id}`, `/game/me`, `/game/config`, `POST /game/ingest/{id}` |
| `tests/fixtures/game/route_a.json` | Synthetischer Track A (deterministisch) |
| `tests/fixtures/game/match_route_a.json` | Erwartetes Fake-Match für Track A |
| `tests/Unit/Game/GameMathTest.php` | §10.1–10.3 (Golden Numbers) |
| `tests/Integration/Game/GameIngestionTest.php` | §10.4–10.9 |
| `tests/Integration/Game/GameEndpointsTest.php` | §10.10 |
| `backend/VALHALLA_SETUP.md` | Build/Region/Node-Ref-Ableitung dokumentiert |
| `backend/GAME_STAGE1_TESTREPORT.md` | Generierter Testbericht (Soll/Ist + Golden-Tabelle) |

**Modifizieren:**

| Datei | Änderung |
|---|---|
| `src/Routes/ParsedPoint.php` | Optionale Felder `?float $speedMps`, `?float $horizontalAccuracyM` (am Ende, defaults `null`) |
| `src/Routes/RouteService.php` | Optionaler `?GameIngestionService $game` Konstruktor-Param; Hook nach `commit()` (best effort) |
| `src/Cli/Commands.php` | Befehl `game:recompute` |
| `public/index.php` | Wiring: `GameConfig`, Matcher, Repository, IngestionService in RouteService + Controller-Routen + CLI |
| `.env.example` | Keys `VALHALLA_BASE_URL`, `GAME_ENABLED` |
| `docs/API.md` | Doku der `/game/*`-Endpunkte (am Ende, nach grünen Tests) |

**Test-Befehle:** `composer test:unit`, `composer test:integration`, `composer test`. Migration: `composer migrate`.

> **Hinweis Reihenfolge:** Tasks 1–3 (Schema, Config, Math) sind ohne Valhalla/DB-Risiko und liefern bereits die Golden-Numbers (§10.1–10.3). Tasks 4–8 bauen die Pipeline. Task 9 die Endpunkte. Task 10 Verdrahtung + Testbericht.

---

### Task 1: Schema-Migration

**Files:**
- Create: `migrations/0015_game_stage1.sql`

- [ ] **Step 1: Migration schreiben**

```sql
-- Stufe 1 (Solo-Claim) Territorialspiel. Siehe GAME_STAGE1_BACKEND.md.
-- Event-sourced: game_edge_pass ist die Quelle der Wahrheit; game_edge.*_cached
-- ist materialisierte Sicht. claimant_id (nicht user_id) traegt den Besitz —
-- forward-compat fuer Stufe 2/3 (Gruppen/Fraktionen) ohne Schema-Migration.

CREATE TABLE IF NOT EXISTS game_claimant (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type          ENUM('rider','group','faction') NOT NULL,
  user_id       BIGINT UNSIGNED NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_claimant_rider (type, user_id),
  CONSTRAINT fk_claimant_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_node (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  osm_node_id   BIGINT          NOT NULL,
  lat           DOUBLE          NOT NULL,
  lon           DOUBLE          NOT NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_node_osm (osm_node_id),
  KEY idx_node_geo (lat, lon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_edge (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  way_id                 BIGINT          NOT NULL,
  node_a_id              BIGINT UNSIGNED NOT NULL,
  node_b_id              BIGINT UNSIGNED NOT NULL,
  length_m               DOUBLE          NOT NULL,
  geom_geojson           JSON            NOT NULL,
  surface_character      VARCHAR(16)     NULL,
  min_lat                DOUBLE          NOT NULL,
  min_lon                DOUBLE          NOT NULL,
  max_lat                DOUBLE          NOT NULL,
  max_lon                DOUBLE          NOT NULL,
  discovered_at          DATETIME(3) NULL,
  discoverer_claimant_id BIGINT UNSIGNED NULL,
  distinct_riders_total  INT       NOT NULL DEFAULT 0,
  owner_claimant_id      BIGINT UNSIGNED NULL,
  owner_since            DATETIME(3) NULL,
  value_cached           DOUBLE      NOT NULL DEFAULT 0,
  freshness_cached       DOUBLE      NOT NULL DEFAULT 0,
  last_pass_at           DATETIME(3) NULL,
  created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_edge_segment (way_id, node_a_id, node_b_id),
  KEY idx_edge_bbox (min_lat, max_lat, min_lon, max_lon),
  KEY idx_edge_owner (owner_claimant_id),
  CONSTRAINT fk_edge_node_a FOREIGN KEY (node_a_id) REFERENCES game_node(id),
  CONSTRAINT fk_edge_node_b FOREIGN KEY (node_b_id) REFERENCES game_node(id),
  CONSTRAINT fk_edge_owner  FOREIGN KEY (owner_claimant_id) REFERENCES game_claimant(id),
  CONSTRAINT fk_edge_discoverer FOREIGN KEY (discoverer_claimant_id) REFERENCES game_claimant(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_edge_pass (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  edge_id       BIGINT UNSIGNED NOT NULL,
  claimant_id   BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  route_id      BIGINT UNSIGNED NOT NULL,
  ridden_on     DATE        NOT NULL,
  ridden_at     DATETIME(3) NOT NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pass_daycap (edge_id, user_id, ridden_on),
  KEY idx_pass_edge_user (edge_id, user_id),
  KEY idx_pass_claimant (claimant_id),
  KEY idx_pass_route (route_id),
  CONSTRAINT fk_pass_edge     FOREIGN KEY (edge_id)     REFERENCES game_edge(id) ON DELETE CASCADE,
  CONSTRAINT fk_pass_claimant FOREIGN KEY (claimant_id) REFERENCES game_claimant(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_config (
  config_key    VARCHAR(40)  NOT NULL,
  config_value  VARCHAR(64)  NOT NULL,
  PRIMARY KEY (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (config_key, config_value) VALUES
  ('presence_window_days', '90'),
  ('presence_decay',       'linear'),
  ('hysteresis_factor',    '1.15'),
  ('pioneer_p0',           '100'),
  ('pioneer_k',            '12'),
  ('pioneer_s',            '4'),
  ('popularity_c',         '30'),
  ('value_combine',        'max'),
  ('curation_per_hint',    '5'),
  ('curation_per_like',    '2'),
  ('auth_min_speed_kmh',   '5'),
  ('auth_max_hacc_m',      '30'),
  ('auth_require_motion',  '1'),
  ('start_buffer_m',       '0')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
```

- [ ] **Step 2: Migration anwenden**

Run: `composer migrate`
Expected: `Migriert: 0015_game_stage1.sql`

- [ ] **Step 3: Tabellen verifizieren**

Run: `php -r "require 'vendor/autoload.php'; App\Config\Config::boot(__DIR__); var_dump(App\Database\Db::pdo()->query('SHOW TABLES LIKE \"game_%\"')->fetchAll(PDO::FETCH_COLUMN));"`
Expected: 5 Tabellen `game_claimant, game_config, game_edge, game_edge_pass, game_node`

- [ ] **Step 4: Commit**

```bash
git add migrations/0015_game_stage1.sql
git commit -m "feat(game): add stage 1 schema (claimant, node, edge, edge_pass, config)"
```

---

### Task 2: GameConfig

**Files:**
- Create: `src/Game/GameConfig.php`
- Test: `tests/Integration/Game/GameConfigTest.php`

- [ ] **Step 1: Failing test schreiben**

```php
<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameConfig;
use Tests\IntegrationTestCase;

final class GameConfigTest extends IntegrationTestCase
{
    public function testReadsSeededDefaults(): void
    {
        // Migration seedet die Defaults; TRUNCATE in setUp leert game_config,
        // daher hier neu seeden.
        $this->pdo->exec("INSERT INTO game_config (config_key, config_value) VALUES
            ('pioneer_p0','100'),('pioneer_k','12'),('pioneer_s','4'),
            ('hysteresis_factor','1.15'),('presence_window_days','90'),
            ('popularity_c','30'),('value_combine','max'),
            ('auth_min_speed_kmh','5'),('auth_max_hacc_m','30'),
            ('auth_require_motion','1'),('start_buffer_m','0'),
            ('curation_per_hint','5'),('curation_per_like','2'),
            ('presence_decay','linear')");

        $cfg = new GameConfig($this->pdo);
        $this->assertSame(100.0, $cfg->float('pioneer_p0'));
        $this->assertSame(12.0, $cfg->float('pioneer_k'));
        $this->assertSame(1.15, $cfg->float('hysteresis_factor'));
        $this->assertSame(90, $cfg->int('presence_window_days'));
        $this->assertTrue($cfg->bool('auth_require_motion'));
        $this->assertSame('max', $cfg->string('value_combine'));
    }

    public function testFallsBackToDefaultWhenKeyMissing(): void
    {
        $cfg = new GameConfig($this->pdo); // game_config leer nach TRUNCATE
        $this->assertSame(100.0, $cfg->float('pioneer_p0'));
        $this->assertSame(1.15, $cfg->float('hysteresis_factor'));
    }
}
```

- [ ] **Step 2: Test ausführen (muss fehlschlagen)**

Run: `composer test:integration -- --filter GameConfigTest`
Expected: FAIL — `Class "App\Game\GameConfig" not found`

- [ ] **Step 3: GameConfig implementieren**

```php
<?php
declare(strict_types=1);

namespace App\Game;

use PDO;

/**
 * Server-justierbare Spiel-Parameter (Spec §3.5). Liest die key/value-
 * Tabelle game_config einmal lazy und cached sie im Objekt. Fehlt ein
 * Key, greift der eingebaute Default — so bleibt das System lauffaehig,
 * auch wenn ein neuer Parameter noch nicht geseedet ist.
 */
final class GameConfig
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    /** @var array<string,string> */
    private const DEFAULTS = [
        'presence_window_days' => '90',
        'presence_decay'       => 'linear',
        'hysteresis_factor'    => '1.15',
        'pioneer_p0'           => '100',
        'pioneer_k'            => '12',
        'pioneer_s'            => '4',
        'popularity_c'         => '30',
        'value_combine'        => 'max',
        'curation_per_hint'    => '5',
        'curation_per_like'    => '2',
        'auth_min_speed_kmh'   => '5',
        'auth_max_hacc_m'      => '30',
        'auth_require_motion'  => '1',
        'start_buffer_m'       => '0',
    ];

    public function __construct(private readonly PDO $pdo) {}

    private function raw(string $key): string
    {
        if ($this->cache === null) {
            $this->cache = [];
            try {
                $rows = $this->pdo->query('SELECT config_key, config_value FROM game_config')
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
                $this->cache = is_array($rows) ? $rows : [];
            } catch (\PDOException) {
                $this->cache = [];
            }
        }
        return $this->cache[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    public function string(string $key): string
    {
        return $this->raw($key);
    }

    public function float(string $key): float
    {
        return (float)$this->raw($key);
    }

    public function int(string $key): int
    {
        return (int)$this->raw($key);
    }

    public function bool(string $key): bool
    {
        return in_array(strtolower(trim($this->raw($key))), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string,string> Alle effektiven Werte (DB ueber Default). */
    public function all(): array
    {
        $out = self::DEFAULTS;
        foreach (self::DEFAULTS as $k => $_) {
            $out[$k] = $this->raw($k);
        }
        return $out;
    }
}
```

- [ ] **Step 4: Test ausführen (muss passen)**

Run: `composer test:integration -- --filter GameConfigTest`
Expected: PASS (2 Tests)

- [ ] **Step 5: Commit**

```bash
git add src/Game/GameConfig.php tests/Integration/Game/GameConfigTest.php
git commit -m "feat(game): add GameConfig with DB-backed tunables and defaults"
```

---

### Task 3: GameMath — reine Berechnung (Golden Numbers §10.1–10.3)

**Files:**
- Create: `src/Game/GameMath.php`
- Test: `tests/Unit/Game/GameMathTest.php`

- [ ] **Step 1: Failing test schreiben** (deckt §10.1, §10.2, §10.3)

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\GameMath;
use PHPUnit\Framework\TestCase;

final class GameMathTest extends TestCase
{
    // §10.1 Pionier-Formel
    public function testPioneerGoldenNumbers(): void
    {
        $this->assertEqualsWithDelta(100.0, GameMath::pioneer(1, 100.0, 12.0, 4.0), 0.1);
        $this->assertEqualsWithDelta(67.5,  GameMath::pioneer(10, 100.0, 12.0, 4.0), 0.1);
        $this->assertEqualsWithDelta(50.0,  GameMath::pioneer(12, 100.0, 12.0, 4.0), 0.1);
        $this->assertEqualsWithDelta(11.5,  GameMath::pioneer(20, 100.0, 12.0, 4.0), 0.1);
        $this->assertEqualsWithDelta(2.5,   GameMath::pioneer(30, 100.0, 12.0, 4.0), 0.1);
    }

    public function testPioneerPlateauBelowTen(): void
    {
        // Plateau: n<=10 bleibt nahe P0 (kein steiler Abfall vor dem Wendepunkt)
        $this->assertGreaterThan(67.0, GameMath::pioneer(10, 100.0, 12.0, 4.0));
        $this->assertGreaterThan(95.0, GameMath::pioneer(5, 100.0, 12.0, 4.0));
    }

    // §10.2 Praesenz-Verfall (linear, window=90)
    public function testPresenceWeightLinearDecay(): void
    {
        $this->assertSame(1.0, GameMath::presenceWeight(0.0, 90));
        $this->assertSame(0.5, GameMath::presenceWeight(45.0, 90));
        $this->assertSame(0.0, GameMath::presenceWeight(90.0, 90));
        $this->assertSame(0.0, GameMath::presenceWeight(120.0, 90)); // nie negativ
    }

    public function testPresenceSumOverThreePasses(): void
    {
        // Pässe vor 0, 45, 90 Tagen → 1.0 + 0.5 + 0.0 = 1.5
        $sum = GameMath::presenceWeight(0.0, 90)
             + GameMath::presenceWeight(45.0, 90)
             + GameMath::presenceWeight(90.0, 90);
        $this->assertSame(1.5, $sum);
    }

    // §10.3 Wert-Verknüpfung
    public function testValueAtFirstRiderIsPioneer(): void
    {
        $pioneer = GameMath::pioneer(1, 100.0, 12.0, 4.0);     // ~100
        $popularity = GameMath::popularity(1, 30.0);           // 30*ln(2) ~20.8
        $value = GameMath::combineValue($pioneer, $popularity, 0.0);
        $this->assertEqualsWithDelta($pioneer, $value, 0.1);
        $this->assertGreaterThanOrEqual(max($pioneer, $popularity), $value);
    }

    public function testValueAtManyRidersIsPopularity(): void
    {
        $pioneer = GameMath::pioneer(30, 100.0, 12.0, 4.0);    // ~2.5
        $popularity = GameMath::popularity(25, 30.0);          // 30*ln(26) ~97.7
        $value = GameMath::combineValue($pioneer, $popularity, 0.0);
        $this->assertEqualsWithDelta($popularity, $value, 0.1);
        // Kein "Tal": value nie kleiner als das Maximum der beiden
        $this->assertGreaterThanOrEqual(max($pioneer, $popularity), $value);
    }

    public function testCurationAddsOnTop(): void
    {
        $value = GameMath::combineValue(50.0, 30.0, 7.0);
        $this->assertSame(57.0, $value); // max(50,30) + 7
    }
}
```

- [ ] **Step 2: Test ausführen (muss fehlschlagen)**

Run: `composer test:unit -- --filter GameMathTest`
Expected: FAIL — `Class "App\Game\GameMath" not found`

- [ ] **Step 3: GameMath implementieren**

```php
<?php
declare(strict_types=1);

namespace App\Game;

/**
 * Reine, seiteneffektfreie Spiel-Mathematik (Spec §5). Alle Methoden
 * statisch und ohne I/O → direkt Unit-testbar (Golden Numbers §10.1–10.3).
 */
final class GameMath
{
    /**
     * Pionier-Hill-Funktion: P0 / (1 + (n/k)^s).
     * Plateau fuer kleine n, Wendepunkt bei n=k (dort genau P0/2).
     */
    public static function pioneer(int $n, float $p0, float $k, float $s): float
    {
        if ($n <= 0 || $k <= 0.0) {
            return $p0;
        }
        return $p0 / (1.0 + ($n / $k) ** $s);
    }

    /** Beliebtheit: c * ln(1 + n90). */
    public static function popularity(int $n90, float $c): float
    {
        if ($n90 <= 0) {
            return 0.0;
        }
        return $c * log(1.0 + $n90);
    }

    /**
     * Praesenz-Gewicht eines Passes: max(0, 1 - age/window) (linear).
     * @param float $ageDays Alter des Passes in Tagen (>=0)
     */
    public static function presenceWeight(float $ageDays, int $windowDays): float
    {
        if ($windowDays <= 0) {
            return 0.0;
        }
        return max(0.0, 1.0 - $ageDays / $windowDays);
    }

    /** value = max(pioneer, popularity) + curation. */
    public static function combineValue(float $pioneer, float $popularity, float $curation): float
    {
        return max($pioneer, $popularity) + $curation;
    }

    /**
     * Besitzer-Entscheidung mit Hysterese (Spec §5.2).
     * Gibt die claimant_id des neuen Besitzers zurueck.
     *
     * @param int|null $currentOwnerId aktueller Besitzer (null = niemand)
     * @param float    $currentPresence Praesenz des aktuellen Besitzers
     * @param int      $challengerId    staerkster Herausforderer (argmax Praesenz)
     * @param float    $challengerPresence
     */
    public static function decideOwner(
        ?int $currentOwnerId,
        float $currentPresence,
        int $challengerId,
        float $challengerPresence,
        float $hysteresisFactor,
    ): int {
        if ($currentOwnerId === null) {
            return $challengerId;
        }
        if ($challengerId === $currentOwnerId) {
            return $currentOwnerId;
        }
        if ($challengerPresence > $currentPresence * $hysteresisFactor) {
            return $challengerId;
        }
        return $currentOwnerId;
    }
}
```

- [ ] **Step 4: Test ausführen (muss passen)**

Run: `composer test:unit -- --filter GameMathTest`
Expected: PASS (alle §10.1–10.3-Assertions grün)

- [ ] **Step 5: Commit**

```bash
git add src/Game/GameMath.php tests/Unit/Game/GameMathTest.php
git commit -m "feat(game): add GameMath (pioneer, popularity, presence decay, hysteresis)"
```

---

### Task 4: ParsedPoint um speed/accuracy erweitern

**Files:**
- Modify: `src/Routes/ParsedPoint.php`

- [ ] **Step 1: Felder ergänzen** (optionale Parameter am Ende, defaults `null` → bestehende named-arg-Aufrufe in `GeometryParser` bleiben gültig)

```php
final class ParsedPoint
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lon,
        public readonly ?float $elevationM,
        public readonly ?DateTimeImmutable $timestamp,
        public readonly ?float $speedMps = null,
        public readonly ?float $horizontalAccuracyM = null,
    ) {
    }
}
```

- [ ] **Step 2: Bestehende Tests laufen lassen (keine Regression)**

Run: `composer test:unit -- --filter GeometryParserTest`
Expected: PASS (unverändert)

- [ ] **Step 3: Commit**

```bash
git add src/Routes/ParsedPoint.php
git commit -m "feat(routes): add optional speed/accuracy fields to ParsedPoint"
```

> Hinweis: Das Befüllen aus GPX-Extensions ist für Stufe 1 nicht nötig — die Auth-Aggregate kommen aus dem Matcher (Task 5). Felder existieren für den späteren Valhalla-Adapter.

---

### Task 5: MatchedSegment + EdgeMatcher-Interface + FakeEdgeMatcher

**Files:**
- Create: `src/Game/MatchedSegment.php`
- Create: `src/Game/EdgeMatcher.php`
- Create: `src/Game/FakeEdgeMatcher.php`
- Test: `tests/Unit/Game/FakeEdgeMatcherTest.php`

- [ ] **Step 1: Failing test schreiben**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\FakeEdgeMatcher;
use App\Game\MatchedSegment;
use App\Routes\GeometryParser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FakeEdgeMatcherTest extends TestCase
{
    public function testReturnsConfiguredSegments(): void
    {
        $seg = new MatchedSegment(
            wayId: 1001,
            nodeARef: 10,
            nodeBRef: 11,
            lengthM: 120.0,
            geometry: [[9.65, 47.12], [9.66, 47.13]],
            surface: 'gravel',
            avgSpeedKmh: 18.0,
            maxHaccM: 8.0,
            hasMotion: true,
            riddenAt: new DateTimeImmutable('2026-06-20T08:00:00Z'),
        );
        $matcher = new FakeEdgeMatcher([$seg]);
        $parsed = (new GeometryParser())->parse(
            '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13]]}'
        );
        $out = $matcher->match($parsed);
        $this->assertCount(1, $out);
        $this->assertSame(1001, $out[0]->wayId);
        $this->assertSame('gravel', $out[0]->surface);
    }
}
```

- [ ] **Step 2: Test ausführen (muss fehlschlagen)**

Run: `composer test:unit -- --filter FakeEdgeMatcherTest`
Expected: FAIL — Klassen nicht gefunden

- [ ] **Step 3: MatchedSegment implementieren**

```php
<?php
declare(strict_types=1);

namespace App\Game;

use DateTimeImmutable;

/**
 * Ein auf eine OSM-Kante gematchtes Track-Segment inkl. der Auth-
 * Aggregate, die der Pass-Filter (§4.3) braucht. Bewusst vom konkreten
 * Matcher entkoppelt: Fake (Test) und Valhalla (echt) liefern dasselbe VO.
 */
final class MatchedSegment
{
    /**
     * @param list<array{0:float,1:float}> $geometry [lon,lat]-Paare
     */
    public function __construct(
        public readonly int $wayId,
        public readonly int $nodeARef,
        public readonly int $nodeBRef,
        public readonly float $lengthM,
        public readonly array $geometry,
        public readonly ?string $surface,
        public readonly ?float $avgSpeedKmh,
        public readonly ?float $maxHaccM,
        public readonly bool $hasMotion,
        public readonly DateTimeImmutable $riddenAt,
    ) {}
}
```

- [ ] **Step 4: EdgeMatcher-Interface implementieren**

```php
<?php
declare(strict_types=1);

namespace App\Game;

use App\Routes\ParsedRoute;

/**
 * Map-Matching-Abstraktion (Spec §9.1). Bildet eine geparste Route auf
 * eine Folge von OSM-Segmenten ab. Implementierungen: ValhallaEdgeMatcher
 * (echt) und FakeEdgeMatcher (deterministische Tests).
 */
interface EdgeMatcher
{
    /**
     * @return list<MatchedSegment> leere Liste = kein Match
     * @throws \RuntimeException wenn der Matcher hart ausfaellt (Spec §10.9)
     */
    public function match(ParsedRoute $route): array;
}
```

- [ ] **Step 5: FakeEdgeMatcher implementieren**

```php
<?php
declare(strict_types=1);

namespace App\Game;

use App\Routes\ParsedRoute;
use RuntimeException;

/**
 * Liefert eine fest vorgegebene Segment-Folge — unabhaengig von der Route.
 * Damit sind alle nachgelagerten Berechnungen deterministisch testbar.
 * Mit $throw=true simuliert er einen Valhalla-Ausfall (Spec §10.9).
 */
final class FakeEdgeMatcher implements EdgeMatcher
{
    /** @param list<MatchedSegment> $segments */
    public function __construct(
        private readonly array $segments,
        private readonly bool $throw = false,
    ) {}

    public function match(ParsedRoute $route): array
    {
        if ($this->throw) {
            throw new RuntimeException('Fake-Matcher: simulierter Valhalla-Ausfall.');
        }
        return $this->segments;
    }
}
```

- [ ] **Step 6: Test ausführen (muss passen)**

Run: `composer test:unit -- --filter FakeEdgeMatcherTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Game/MatchedSegment.php src/Game/EdgeMatcher.php src/Game/FakeEdgeMatcher.php tests/Unit/Game/FakeEdgeMatcherTest.php
git commit -m "feat(game): add EdgeMatcher abstraction + MatchedSegment + fake matcher"
```

---

### Task 6: GameRepository (PDO-Operationen)

**Files:**
- Create: `src/Game/GameRepository.php`
- Test: `tests/Integration/Game/GameRepositoryTest.php`

- [ ] **Step 1: Failing test schreiben** (Upsert-Idempotenz + Tages-Deckel §10.5)

```php
<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameRepository;
use Tests\IntegrationTestCase;

final class GameRepositoryTest extends IntegrationTestCase
{
    public function testRiderClaimantIsLazyAndUnique(): void
    {
        $uid = $this->createUser('armin');
        $repo = new GameRepository($this->pdo);
        $c1 = $repo->riderClaimantId($uid);
        $c2 = $repo->riderClaimantId($uid);
        $this->assertSame($c1, $c2, 'pro User genau ein rider-Claimant');
    }

    public function testUpsertNodeAndEdgeAreIdempotent(): void
    {
        $repo = new GameRepository($this->pdo);
        $a = $repo->upsertNode(10, 47.12, 9.65);
        $b = $repo->upsertNode(11, 47.13, 9.66);
        $this->assertSame($a, $repo->upsertNode(10, 47.12, 9.65));

        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $e1 = $repo->upsertEdge(1001, $a, $b, 120.0, $geom, 'gravel', 47.12, 9.65, 47.13, 9.66);
        $e2 = $repo->upsertEdge(1001, $a, $b, 120.0, $geom, 'gravel', 47.12, 9.65, 47.13, 9.66);
        $this->assertSame($e1, $e2, 'gleiche Kante → kein Duplikat');
    }

    public function testInsertPassRespectsDayCap(): void
    {
        $uid = $this->createUser('armin');
        $repo = new GameRepository($this->pdo);
        $cid = $repo->riderClaimantId($uid);
        $a = $repo->upsertNode(10, 47.12, 9.65);
        $b = $repo->upsertNode(11, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $eid = $repo->upsertEdge(1001, $a, $b, 120.0, $geom, null, 47.12, 9.65, 47.13, 9.66);

        $first = $repo->insertPassIfAbsent($eid, $cid, $uid, 1, '2026-06-20', '2026-06-20 08:00:00.000');
        $second = $repo->insertPassIfAbsent($eid, $cid, $uid, 1, '2026-06-20', '2026-06-20 09:00:00.000');
        $this->assertTrue($first, 'erster Pass am Tag → angelegt');
        $this->assertFalse($second, 'zweiter Pass am selben Tag → kein neuer Pass');
        $this->assertSame(1, $repo->distinctRidersTotal($eid));
    }
}
```

- [ ] **Step 2: Test ausführen (muss fehlschlagen)**

Run: `composer test:integration -- --filter GameRepositoryTest`
Expected: FAIL — `Class "App\Game\GameRepository" not found`

- [ ] **Step 3: GameRepository implementieren**

```php
<?php
declare(strict_types=1);

namespace App\Game;

use PDO;

/**
 * Alle PDO-Operationen des Spiels. Keine Geschaeftslogik (die lebt in
 * GameMath / EdgeRecalculator / GameIngestionService) — nur Lesen/Schreiben.
 */
final class GameRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function riderClaimantId(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM game_claimant WHERE type = "rider" AND user_id = ?'
        );
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        // Race-safe: INSERT IGNORE, dann erneut lesen.
        $this->pdo->prepare(
            'INSERT IGNORE INTO game_claimant (type, user_id) VALUES ("rider", ?)'
        )->execute([$userId]);
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    public function upsertNode(int $osmNodeId, float $lat, float $lon): int
    {
        $this->pdo->prepare(
            'INSERT INTO game_node (osm_node_id, lat, lon) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE lat = VALUES(lat), lon = VALUES(lon)'
        )->execute([$osmNodeId, $lat, $lon]);
        $stmt = $this->pdo->prepare('SELECT id FROM game_node WHERE osm_node_id = ?');
        $stmt->execute([$osmNodeId]);
        return (int)$stmt->fetchColumn();
    }

    public function upsertEdge(
        int $wayId,
        int $nodeAId,
        int $nodeBId,
        float $lengthM,
        string $geomJson,
        ?string $surface,
        float $minLat,
        float $minLon,
        float $maxLat,
        float $maxLon,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO game_edge
                (way_id, node_a_id, node_b_id, length_m, geom_geojson, surface_character,
                 min_lat, min_lon, max_lat, max_lon)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                length_m = VALUES(length_m),
                geom_geojson = VALUES(geom_geojson),
                surface_character = COALESCE(VALUES(surface_character), surface_character)'
        )->execute([$wayId, $nodeAId, $nodeBId, $lengthM, $geomJson, $surface,
                    $minLat, $minLon, $maxLat, $maxLon]);
        $stmt = $this->pdo->prepare(
            'SELECT id FROM game_edge WHERE way_id = ? AND node_a_id = ? AND node_b_id = ?'
        );
        $stmt->execute([$wayId, $nodeAId, $nodeBId]);
        return (int)$stmt->fetchColumn();
    }

    /** @return bool true wenn ein NEUER Pass angelegt wurde (sonst Tages-Deckel). */
    public function insertPassIfAbsent(
        int $edgeId,
        int $claimantId,
        int $userId,
        int $routeId,
        string $riddenOn,
        string $riddenAt,
    ): bool {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_edge_pass
                (edge_id, claimant_id, user_id, route_id, ridden_on, ridden_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ridden_at = GREATEST(ridden_at, VALUES(ridden_at))'
        );
        $stmt->execute([$edgeId, $claimantId, $userId, $routeId, $riddenOn, $riddenAt]);
        // MySQL: rowCount() == 1 → Insert, == 2 → Update, == 0 → unveraendert.
        return $stmt->rowCount() === 1;
    }

    public function distinctRidersTotal(int $edgeId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM game_edge_pass WHERE edge_id = ?'
        );
        $stmt->execute([$edgeId]);
        return (int)$stmt->fetchColumn();
    }

    /** n90: verschiedene user_id mit Pass seit $sinceDate (Y-m-d). */
    public function distinctRidersSince(int $edgeId, string $sinceDate): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM game_edge_pass
              WHERE edge_id = ? AND ridden_on >= ?'
        );
        $stmt->execute([$edgeId, $sinceDate]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Alle Pässe einer Kante (für Präsenz-Berechnung).
     * @return list<array{claimant_id:int,user_id:int,ridden_at:string}>
     */
    public function passesForEdge(int $edgeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT claimant_id, user_id, ridden_at FROM game_edge_pass WHERE edge_id = ?'
        );
        $stmt->execute([$edgeId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'claimant_id' => (int)$r['claimant_id'],
                'user_id'     => (int)$r['user_id'],
                'ridden_at'   => (string)$r['ridden_at'],
            ];
        }
        return $out;
    }

    /**
     * Erst-Pass je User, aufsteigend nach Zeitpunkt (Pionier-Kohorte + Discoverer).
     * @return list<array{user_id:int,claimant_id:int,first_ridden_at:string,handle:?string}>
     */
    public function firstPassPerUser(int $edgeId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.user_id, MIN(p.ridden_at) AS first_ridden_at,
                    MIN(p.claimant_id) AS claimant_id, u.public_handle AS handle
               FROM game_edge_pass p
               JOIN users u ON u.id = p.user_id
              WHERE p.edge_id = ?
              GROUP BY p.user_id, u.public_handle
              ORDER BY first_ridden_at ASC, p.user_id ASC
              LIMIT ?'
        );
        $stmt->bindValue(1, $edgeId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'user_id'         => (int)$r['user_id'],
                'claimant_id'     => (int)$r['claimant_id'],
                'first_ridden_at' => (string)$r['first_ridden_at'],
                'handle'          => $r['handle'] !== null ? (string)$r['handle'] : null,
            ];
        }
        return $out;
    }

    /** Setzt discovered_at/discoverer + distinct_riders_total aus den Pässen. */
    public function refreshEdgeDiscovery(int $edgeId): void
    {
        $this->pdo->prepare(
            'UPDATE game_edge e SET
                e.distinct_riders_total = (
                    SELECT COUNT(DISTINCT user_id) FROM game_edge_pass WHERE edge_id = e.id
                ),
                e.discovered_at = (
                    SELECT MIN(ridden_at) FROM game_edge_pass WHERE edge_id = e.id
                ),
                e.discoverer_claimant_id = (
                    SELECT claimant_id FROM game_edge_pass
                     WHERE edge_id = e.id
                     ORDER BY ridden_at ASC, id ASC LIMIT 1
                )
             WHERE e.id = ?'
        )->execute([$edgeId]);
    }

    public function updateEdgeCached(
        int $edgeId,
        ?int $ownerClaimantId,
        ?string $ownerSince,
        float $value,
        float $freshness,
        ?string $lastPassAt,
    ): void {
        // owner_since nur setzen, wenn sich der Besitzer aendert; bestehenden
        // Wert behalten, wenn $ownerSince null und Besitzer gleich bleibt.
        $this->pdo->prepare(
            'UPDATE game_edge SET
                owner_claimant_id = ?,
                owner_since = COALESCE(?, owner_since),
                value_cached = ?,
                freshness_cached = ?,
                last_pass_at = ?
             WHERE id = ?'
        )->execute([$ownerClaimantId, $ownerSince, $value, $freshness, $lastPassAt, $edgeId]);
    }

    /** @return array<string,mixed>|null Roh-Zeile der Kante. */
    public function edgeById(int $edgeId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_edge WHERE id = ?');
        $stmt->execute([$edgeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<int> alle Kanten-IDs (für vollen Recompute). */
    public function allEdgeIds(): array
    {
        return array_map('intval', $this->pdo->query('SELECT id FROM game_edge ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Kanten im BBox-Rechteck (Min/Max-Vergleich, Spec §3.3).
     * @return list<array<string,mixed>>
     */
    public function edgesInBbox(
        float $minLon,
        float $minLat,
        float $maxLon,
        float $maxLat,
        ?int $mineClaimantId,
        int $limit,
    ): array {
        $sql = 'SELECT * FROM game_edge
                 WHERE max_lat >= ? AND min_lat <= ? AND max_lon >= ? AND min_lon <= ?';
        $params = [$minLat, $maxLat, $minLon, $maxLon];
        if ($mineClaimantId !== null) {
            $sql .= ' AND owner_claimant_id = ?';
            $params[] = $mineClaimantId;
        }
        $sql .= ' ORDER BY id LIMIT ?';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $i => $v) {
            $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{claimant_id:int,type:string,handle:?string}|null */
    public function claimantInfo(int $claimantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.type, u.public_handle AS handle
               FROM game_claimant c
               LEFT JOIN users u ON u.id = c.user_id
              WHERE c.id = ?'
        );
        $stmt->execute([$claimantId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return [
            'claimant_id' => (int)$r['id'],
            'type'        => (string)$r['type'],
            'handle'      => $r['handle'] !== null ? (string)$r['handle'] : null,
        ];
    }

    /** @return array{held:int,pioneered:int,held_length_m:float} */
    public function meStats(int $claimantId): array
    {
        $held = $this->pdo->prepare(
            'SELECT COUNT(*) AS held, COALESCE(SUM(length_m),0) AS len
               FROM game_edge WHERE owner_claimant_id = ?'
        );
        $held->execute([$claimantId]);
        $h = $held->fetch(PDO::FETCH_ASSOC) ?: ['held' => 0, 'len' => 0];

        $pio = $this->pdo->prepare(
            'SELECT COUNT(*) FROM game_edge WHERE discoverer_claimant_id = ?'
        );
        $pio->execute([$claimantId]);

        return [
            'held'          => (int)$h['held'],
            'pioneered'     => (int)$pio->fetchColumn(),
            'held_length_m' => (float)$h['len'],
        ];
    }
}
```

- [ ] **Step 4: Test ausführen (muss passen)**

Run: `composer test:integration -- --filter GameRepositoryTest`
Expected: PASS (3 Tests)

- [ ] **Step 5: Commit**

```bash
git add src/Game/GameRepository.php tests/Integration/Game/GameRepositoryTest.php
git commit -m "feat(game): add GameRepository (upsert node/edge, idempotent passes, reads)"
```

---

### Task 7: EdgeRecalculator (Präsenz → Besitzer → Wert → Frische)

Diese Klasse ist der **einzige** Ort, an dem die `*_cached`-Felder gesetzt werden — sowohl live (Ingestion) als auch im vollen Recompute. Identische Logik = identisches Ergebnis (Spec §10.5).

**Files:**
- Create: `src/Game/EdgeRecalculator.php`
- Test: `tests/Integration/Game/EdgeRecalculatorTest.php`

- [ ] **Step 1: Failing test schreiben** (deckt §10.7 Hysterese mit injizierter Zeit)

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

final class EdgeRecalculatorTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private EdgeRecalculator $recalc;
    private int $edgeId;
    private int $c1;
    private int $c2;
    private int $u1;
    private int $u2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->recalc = new EdgeRecalculator($this->repo, new GameConfig($this->pdo));
        $this->u1 = $this->createUser('rider1');
        $this->u2 = $this->createUser('rider2');
        $this->c1 = $this->repo->riderClaimantId($this->u1);
        $this->c2 = $this->repo->riderClaimantId($this->u2);
        $a = $this->repo->upsertNode(10, 47.12, 9.65);
        $b = $this->repo->upsertNode(11, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $this->edgeId = $this->repo->upsertEdge(1001, $a, $b, 120.0, $geom, null, 47.12, 9.65, 47.13, 9.66);
    }

    private function pass(int $claimant, int $user, string $riddenAt): void
    {
        $on = substr($riddenAt, 0, 10);
        $this->repo->insertPassIfAbsent($this->edgeId, $claimant, $user, 1, $on, $riddenAt);
    }

    private function now(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public function testFirstClaimantBecomesOwner(): void
    {
        $this->pass($this->c1, $this->u1, '2026-06-20 08:00:00.000');
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $this->now('2026-06-20T08:00:00Z'));

        $edge = $this->repo->edgeById($this->edgeId);
        $this->assertSame($this->c1, (int)$edge['owner_claimant_id']);
        $this->assertSame(1, (int)$edge['distinct_riders_total']);
        $this->assertEqualsWithDelta(100.0, (float)$edge['value_cached'], 0.1); // pioneer(1)
        $this->assertEqualsWithDelta(1.0, (float)$edge['freshness_cached'], 0.01);
    }

    public function testHysteresisKeepsOwnerUntilExceeded(): void
    {
        // User1: 10 Fahrtage in den letzten 10 Tagen → Praesenz ~ Summe der Gewichte
        for ($d = 0; $d < 10; $d++) {
            $day = (new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC')))->modify("-{$d} days");
            $this->pass($this->c1, $this->u1, $day->format('Y-m-d') . ' 08:00:00.000');
        }
        $now = $this->now('2026-06-20T12:00:00Z');
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $now);
        $this->assertSame($this->c1, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id']);

        // User2 baut 10 Fahrtage auf — Präsenz ungefähr gleich → ≤ owner×1.15 → bleibt User1
        for ($d = 0; $d < 10; $d++) {
            $day = (new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC')))->modify("-{$d} days");
            $this->pass($this->c2, $this->u2, $day->format('Y-m-d') . ' 09:00:00.000');
        }
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $now);
        $this->assertSame($this->c1, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id'],
            'Gleichstand → Hysterese schützt Amtsinhaber');

        // User2 legt deutlich nach (20 weitere Fahrtage davor) → Präsenz > User1×1.15 → Wechsel
        for ($d = 10; $d < 35; $d++) {
            $day = (new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC')))->modify("-{$d} days");
            $this->pass($this->c2, $this->u2, $day->format('Y-m-d') . ' 09:00:00.000');
        }
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $now);
        $this->assertSame($this->c2, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id'],
            'Präsenz über Hysterese-Schwelle → Besitzwechsel');
        $this->assertNotNull($this->repo->edgeById($this->edgeId)['owner_since']);
    }
}
```

- [ ] **Step 2: Test ausführen (muss fehlschlagen)**

Run: `composer test:integration -- --filter EdgeRecalculatorTest`
Expected: FAIL — `Class "App\Game\EdgeRecalculator" not found`

- [ ] **Step 3: EdgeRecalculator implementieren**

```php
<?php
declare(strict_types=1);

namespace App\Game;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Rechnet die zwischengespeicherten Live-Werte EINER Kante aus den Pässen
 * neu (Spec §5). Genutzt vom Live-Pfad (Ingestion) UND vom vollen
 * Recompute → garantiert identische Ergebnisse (§10.5).
 *
 * Liest ausschliesslich game_edge_pass + game_edge; schreibt nur die
 * *_cached-Felder + owner/owner_since/discovery via GameRepository.
 */
final class EdgeRecalculator
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameConfig $config,
    ) {}

    public function recalculate(int $edgeId, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $edge = $this->repo->edgeById($edgeId);
        if ($edge === null) {
            return;
        }

        $windowDays = $this->config->int('presence_window_days');
        $passes = $this->repo->passesForEdge($edgeId);

        // Präsenz je Claimant (Σ Gewichte) + letzter Pass je Claimant.
        $presence = [];
        $lastPassByClaimant = [];
        $lastPassOverall = null;
        foreach ($passes as $p) {
            $cid = $p['claimant_id'];
            $ageDays = $this->ageDays($p['ridden_at'], $now);
            $presence[$cid] = ($presence[$cid] ?? 0.0) + GameMath::presenceWeight($ageDays, $windowDays);
            if (!isset($lastPassByClaimant[$cid]) || $p['ridden_at'] > $lastPassByClaimant[$cid]) {
                $lastPassByClaimant[$cid] = $p['ridden_at'];
            }
            if ($lastPassOverall === null || $p['ridden_at'] > $lastPassOverall) {
                $lastPassOverall = $p['ridden_at'];
            }
        }

        // Herausforderer = argmax Präsenz (Tie-Break: kleinste claimant_id → deterministisch).
        $challenger = null;
        $challengerPresence = 0.0;
        ksort($presence); // aufsteigende claimant_id
        foreach ($presence as $cid => $pres) {
            if ($challenger === null || $pres > $challengerPresence) {
                $challenger = (int)$cid;
                $challengerPresence = $pres;
            }
        }

        $currentOwner = $edge['owner_claimant_id'] !== null ? (int)$edge['owner_claimant_id'] : null;

        $newOwner = null;
        $ownerSince = null;
        if ($challenger !== null) {
            $currentPresence = $currentOwner !== null ? ($presence[$currentOwner] ?? 0.0) : 0.0;
            $newOwner = GameMath::decideOwner(
                $currentOwner,
                $currentPresence,
                $challenger,
                $challengerPresence,
                $this->config->float('hysteresis_factor'),
            );
            if ($newOwner !== $currentOwner) {
                $ownerSince = $now->format('Y-m-d H:i:s.v');
            }
        }

        // Wert: pioneer(n) vs popularity(n90) + curation (Stufe 1: 0).
        $n = $this->repo->distinctRidersTotal($edgeId);
        $sinceDate = $now->modify("-{$windowDays} days")->format('Y-m-d');
        $n90 = $this->repo->distinctRidersSince($edgeId, $sinceDate);
        $pioneer = GameMath::pioneer(
            $n,
            $this->config->float('pioneer_p0'),
            $this->config->float('pioneer_k'),
            $this->config->float('pioneer_s'),
        );
        $popularity = GameMath::popularity($n90, $this->config->float('popularity_c'));
        $value = GameMath::combineValue($pioneer, $popularity, 0.0);

        // Frische = Gewicht des letzten Passes des Besitzers.
        $freshness = 0.0;
        if ($newOwner !== null && isset($lastPassByClaimant[$newOwner])) {
            $freshness = GameMath::presenceWeight(
                $this->ageDays($lastPassByClaimant[$newOwner], $now),
                $windowDays,
            );
        }

        $this->repo->updateEdgeCached(
            $edgeId,
            $newOwner,
            $ownerSince,
            $value,
            $freshness,
            $lastPassOverall,
        );
    }

    private function ageDays(string $mysqlDatetime, DateTimeImmutable $now): float
    {
        $dt = new DateTimeImmutable($mysqlDatetime, new DateTimeZone('UTC'));
        $seconds = $now->getTimestamp() - $dt->getTimestamp();
        return $seconds / 86400.0;
    }
}
```

- [ ] **Step 4: Test ausführen (muss passen)**

Run: `composer test:integration -- --filter EdgeRecalculatorTest`
Expected: PASS (2 Tests, inkl. §10.7 Hysterese)

- [ ] **Step 5: Commit**

```bash
git add src/Game/EdgeRecalculator.php tests/Integration/Game/EdgeRecalculatorTest.php
git commit -m "feat(game): add EdgeRecalculator (presence, hysteresis owner, value, freshness)"
```

> **Design-Entscheidung „pending" (Spec §2/§10.9):** Stufe 1 hat keine eigene `pending`-Tabelle. „Pending" wird implizit dargestellt: Wirft der Matcher (Valhalla-Ausfall), schluckt der Upload-Hook den Fehler (Route bleibt gespeichert, kein 5xx) und legt **keine** Spieldaten an. Ein späterer `POST /game/ingest/{route_id}` holt es idempotent nach. Das erfüllt das beobachtbare Verhalten von §10.9 ohne zusätzliches Schema.

---

### Task 8: GameIngestionService (Match → Auth → Pass → Pionier → Recalc)

**Files:**
- Create: `src/Game/GameIngestionService.php`
- Test: `tests/Integration/Game/GameIngestionTest.php`

- [ ] **Step 1: Failing test schreiben** (§10.4, §10.6, §10.8, §10.9 + Idempotenz)

```php
<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameMath;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Routes\GeometryParser;
use App\Routes\ParsedRoute;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Tests\IntegrationTestCase;

final class GameIngestionTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->config = new GameConfig($this->pdo);
    }

    private function parsedRoute(): ParsedRoute
    {
        return (new GeometryParser())->parse(
            '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13],[9.67,47.14]]}'
        );
    }

    private function segment(int $way, int $a, int $b, array $geom, string $at): MatchedSegment
    {
        return new MatchedSegment(
            wayId: $way, nodeARef: $a, nodeBRef: $b, lengthM: 120.0,
            geometry: $geom, surface: 'gravel', avgSpeedKmh: 18.0, maxHaccM: 8.0,
            hasMotion: true, riddenAt: new DateTimeImmutable($at, new DateTimeZone('UTC')),
        );
    }

    private function service(array $segments, bool $throw = false): GameIngestionService
    {
        return new GameIngestionService(
            new FakeEdgeMatcher($segments, $throw),
            $this->repo,
            new EdgeRecalculator($this->repo, $this->config),
            $this->config,
            $this->pdo,
        );
    }

    private function now(string $iso = '2026-06-20T08:00:00Z'): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    // §10.4 Ingest → Besitz
    public function testIngestGivesOwnershipToFirstRider(): void
    {
        $u1 = $this->createUser('armin');
        $segs = [
            $this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]], '2026-06-20 08:00:00'),
            $this->segment(1002, 11, 12, [[9.66, 47.13], [9.67, 47.14]], '2026-06-20 08:05:00'),
        ];
        $res = $this->service($segs)->ingest(1, $u1, $this->parsedRoute(), true, $this->now());

        $this->assertSame(2, $res['matched']);
        $this->assertSame(2, $res['passes_new']);
        $c1 = $this->repo->riderClaimantId($u1);
        foreach ($this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100) as $edge) {
            $this->assertSame($c1, (int)$edge['owner_claimant_id']);
            $this->assertSame(1, (int)$edge['distinct_riders_total']);
            $this->assertSame($c1, (int)$edge['discoverer_claimant_id']);
            $this->assertEqualsWithDelta(100.0, (float)$edge['value_cached'], 0.1);
            $this->assertEqualsWithDelta(1.0, (float)$edge['freshness_cached'], 0.01);
        }
    }

    // §10.5 (Teil) Tages-Deckel + Idempotenz bei Re-Ingest
    public function testReingestSameRouteSameDayCreatesNoDuplicatePasses(): void
    {
        $u1 = $this->createUser('armin');
        $segs = [$this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]], '2026-06-20 08:00:00')];
        $svc = $this->service($segs);
        $first = $svc->ingest(1, $u1, $this->parsedRoute(), true, $this->now());
        $second = $svc->ingest(1, $u1, $this->parsedRoute(), true, $this->now('2026-06-20T09:00:00Z'));

        $this->assertSame(1, $first['passes_new']);
        $this->assertSame(0, $second['passes_new']);
        $this->assertSame(1, $second['skipped_day_cap']);
        $edge = $this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100)[0];
        $this->assertSame(1, (int)$edge['distinct_riders_total']);
    }

    // §10.6 Pionier-Abfall durch viele Fahrer
    public function testTwelveDistinctRidersDropPioneerToFifty(): void
    {
        $segGeom = [[9.65, 47.12], [9.66, 47.13]];
        for ($i = 1; $i <= 12; $i++) {
            $uid = $this->createUser('rider' . $i);
            $segs = [$this->segment(1001, 10, 11, $segGeom, '2026-06-20 08:00:00')];
            $this->service($segs)->ingest($i, $uid, $this->parsedRoute(), true, $this->now());
        }
        $edge = $this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100)[0];
        $this->assertSame(12, (int)$edge['distinct_riders_total']);
        $pioneer = GameMath::pioneer(12, 100.0, 12.0, 4.0);
        $this->assertEqualsWithDelta(50.0, $pioneer, 0.1);
        $cohort = $this->repo->firstPassPerUser((int)$edge['id'], 10);
        $this->assertCount(10, $cohort, 'Pionier-Kohorte = erste 10 Handles');
        $this->assertSame('rider1', $cohort[0]['handle']);
    }

    // §10.8 Authentizität: zu langsam / zu ungenau → verworfen mit Grund
    public function testAuthFiltersRejectSlowAndInaccuratePasses(): void
    {
        $u1 = $this->createUser('armin');
        $slow = new MatchedSegment(1001, 10, 11, 50.0, [[9.65, 47.12], [9.66, 47.13]], null,
            avgSpeedKmh: 3.0, maxHaccM: 5.0, hasMotion: true, riddenAt: $this->now());
        $inaccurate = new MatchedSegment(1002, 11, 12, 50.0, [[9.66, 47.13], [9.67, 47.14]], null,
            avgSpeedKmh: 18.0, maxHaccM: 45.0, hasMotion: true, riddenAt: $this->now());
        $res = $this->service([$slow, $inaccurate])->ingest(1, $u1, $this->parsedRoute(), true, $this->now());

        $this->assertSame(0, $res['passes_new']);
        $this->assertSame(1, $res['skipped_auth_speed']);
        $this->assertSame(1, $res['skipped_auth_hacc']);
    }

    // §10.9 Valhalla-Ausfall → Exception, keine Daten, späterer Re-Run holt nach
    public function testMatcherFailureLeavesNoDataButReingestRecovers(): void
    {
        $u1 = $this->createUser('armin');
        $segs = [$this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]], '2026-06-20 08:00:00')];

        try {
            $this->service($segs, throw: true)->ingest(1, $u1, $this->parsedRoute(), true, $this->now());
            $this->fail('Matcher-Ausfall muss eine Exception werfen.');
        } catch (RuntimeException) {
            // erwartet
        }
        $this->assertSame([], $this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100));

        $res = $this->service($segs)->ingest(1, $u1, $this->parsedRoute(), true, $this->now());
        $this->assertSame(1, $res['passes_new']);
    }
}
```

- [ ] **Step 2: Test ausführen (muss fehlschlagen)**

Run: `composer test:integration -- --filter GameIngestionTest`
Expected: FAIL — `Class "App\Game\GameIngestionService" not found`

- [ ] **Step 3: GameIngestionService implementieren**

```php
<?php
declare(strict_types=1);

namespace App\Game;

use App\Routes\ParsedRoute;
use App\Support\Clock;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Orchestriert die Spiel-Ingestion einer Route (Spec §4–§5):
 * Match → Authentizitäts-Filter → Pass (idempotent, tagesgedeckelt) →
 * Pionier-Buchung → Live-Recompute der berührten Kanten.
 *
 * Wirft der Matcher (Valhalla-Ausfall), propagiert die Exception nach
 * oben — der nicht-blockierende Upload-Hook fängt sie (Spec §10.9).
 */
final class GameIngestionService
{
    public function __construct(
        private readonly EdgeMatcher $matcher,
        private readonly GameRepository $repo,
        private readonly EdgeRecalculator $recalc,
        private readonly GameConfig $config,
        private readonly PDO $pdo,
    ) {}

    /**
     * @return array{matched:int,passes_new:int,skipped_day_cap:int,
     *               skipped_auth_speed:int,skipped_auth_hacc:int,skipped_no_motion:int}
     */
    public function ingest(
        int $routeId,
        int $userId,
        ParsedRoute $route,
        bool $sourceHasMotion,
        ?DateTimeImmutable $now = null,
    ): array {
        $now ??= Clock::nowUtc();
        $summary = [
            'matched' => 0, 'passes_new' => 0, 'skipped_day_cap' => 0,
            'skipped_auth_speed' => 0, 'skipped_auth_hacc' => 0, 'skipped_no_motion' => 0,
        ];

        $segments = $this->matcher->match($route); // kann werfen → Caller behandelt
        $summary['matched'] = count($segments);
        if ($segments === []) {
            return $summary;
        }

        $claimantId = $this->repo->riderClaimantId($userId);
        $minSpeed = $this->config->float('auth_min_speed_kmh');
        $maxHacc = $this->config->float('auth_max_hacc_m');
        $requireMotion = $this->config->bool('auth_require_motion');

        $touched = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($segments as $seg) {
                if ($requireMotion && (!$sourceHasMotion || !$seg->hasMotion)) {
                    $summary['skipped_no_motion']++;
                    continue;
                }
                if ($seg->avgSpeedKmh !== null && $seg->avgSpeedKmh < $minSpeed) {
                    $summary['skipped_auth_speed']++;
                    continue;
                }
                if ($seg->maxHaccM !== null && $seg->maxHaccM > $maxHacc) {
                    $summary['skipped_auth_hacc']++;
                    continue;
                }

                $geom = $seg->geometry;
                $first = $geom[0];
                $last = $geom[count($geom) - 1];
                $aId = $this->repo->upsertNode($seg->nodeARef, (float)$first[1], (float)$first[0]);
                $bId = $this->repo->upsertNode($seg->nodeBRef, (float)$last[1], (float)$last[0]);
                if ($aId > $bId) {
                    [$aId, $bId] = [$bId, $aId];
                    $geom = array_reverse($geom);
                }
                [$minLat, $minLon, $maxLat, $maxLon] = $this->bbox($geom);
                $geomJson = json_encode(
                    ['type' => 'LineString', 'coordinates' => $geom],
                    JSON_THROW_ON_ERROR,
                );
                $edgeId = $this->repo->upsertEdge(
                    $seg->wayId, $aId, $bId, $seg->lengthM, $geomJson, $seg->surface,
                    $minLat, $minLon, $maxLat, $maxLon,
                );

                $riddenOn = $seg->riddenAt->format('Y-m-d');
                $riddenAt = $seg->riddenAt->format('Y-m-d H:i:s.v');
                if ($this->repo->insertPassIfAbsent($edgeId, $claimantId, $userId, $routeId, $riddenOn, $riddenAt)) {
                    $summary['passes_new']++;
                } else {
                    $summary['skipped_day_cap']++;
                }
                $touched[$edgeId] = true;
            }

            foreach (array_keys($touched) as $edgeId) {
                $this->repo->refreshEdgeDiscovery($edgeId);
                $this->recalc->recalculate($edgeId, $now);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $summary;
    }

    /** @param list<array{0:float,1:float}> $geom @return array{0:float,1:float,2:float,3:float} */
    private function bbox(array $geom): array
    {
        $lons = array_map(static fn($c) => (float)$c[0], $geom);
        $lats = array_map(static fn($c) => (float)$c[1], $geom);
        return [min($lats), min($lons), max($lats), max($lons)];
    }
}
```

- [ ] **Step 4: Test ausführen (muss passen)**

Run: `composer test:integration -- --filter GameIngestionTest`
Expected: PASS (5 Tests: §10.4, §10.5-Teil, §10.6, §10.8, §10.9)

- [ ] **Step 5: Commit**

```bash
git add src/Game/GameIngestionService.php tests/Integration/Game/GameIngestionTest.php
git commit -m "feat(game): add ingestion pipeline (auth filters, idempotent passes, recompute)"
```

> **Design-Entscheidung Reproduzierbarkeit (Spec §7/§10.5):** Der volle Recompute setzt die `*_cached`-Felder zurück und rechnet jede Kante aus den Pässen neu (`refreshEdgeDiscovery` + `EdgeRecalculator::recalculate($now)`). Das ist **bit-identisch** zum Live-Pfad, solange der Besitz nicht umkämpft war (genau der §10.5-Fall „dieselbe Route A" eines Users). Eine chronologische Replay-Variante für umkämpfte Hysterese-Verläufe ist als spätere Erweiterung notiert.

---

### Task 9: GameRecomputeService + CLI `game:recompute`

**Files:**
- Create: `src/Game/GameRecomputeService.php`
- Modify: `src/Game/GameRepository.php` (Methode `resetAllEdgeCaches`)
- Modify: `src/Cli/Commands.php` (Befehl `game:recompute`)
- Test: `tests/Integration/Game/GameRecomputeTest.php`

- [ ] **Step 1: `resetAllEdgeCaches` in GameRepository ergänzen**

```php
    /** Setzt alle materialisierten Live-Werte zurück (für vollen Recompute). */
    public function resetAllEdgeCaches(): void
    {
        $this->pdo->exec(
            'UPDATE game_edge SET
                owner_claimant_id = NULL, owner_since = NULL,
                value_cached = 0, freshness_cached = 0, last_pass_at = NULL'
        );
    }
```

- [ ] **Step 2: Failing test schreiben** (§10.5 Recompute == Live)

```php
<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameRecomputeService;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Routes\GeometryParser;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class GameRecomputeTest extends IntegrationTestCase
{
    public function testFullRecomputeMatchesLivePath(): void
    {
        $repo = new GameRepository($this->pdo);
        $config = new GameConfig($this->pdo);
        $recalc = new EdgeRecalculator($repo, $config);
        $now = new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));

        $u1 = $this->createUser('armin');
        $route = (new GeometryParser())->parse(
            '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13],[9.67,47.14]]}'
        );
        $segs = [
            new MatchedSegment(1001, 10, 11, 120.0, [[9.65, 47.12], [9.66, 47.13]], 'gravel', 18.0, 8.0, true, $now),
            new MatchedSegment(1002, 11, 12, 120.0, [[9.66, 47.13], [9.67, 47.14]], 'gravel', 18.0, 8.0, true, $now),
        ];
        $ingest = new GameIngestionService(new FakeEdgeMatcher($segs), $repo, $recalc, $config, $this->pdo);
        $ingest->ingest(1, $u1, $route, true, $now);

        $live = $this->snapshot();

        (new GameRecomputeService($repo, $recalc))->recomputeAll($now);
        $recomputed = $this->snapshot();

        $this->assertSame($live, $recomputed, 'Voller Recompute muss bit-identisch zum Live-Pfad sein.');
    }

    /** @return array<int,array{owner:?int,value:string,fresh:string,n:int}> */
    private function snapshot(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, owner_claimant_id, value_cached, freshness_cached, distinct_riders_total
               FROM game_edge ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = [
                'owner' => $r['owner_claimant_id'] !== null ? (int)$r['owner_claimant_id'] : null,
                'value' => (string)$r['value_cached'],
                'fresh' => (string)$r['freshness_cached'],
                'n'     => (int)$r['distinct_riders_total'],
            ];
        }
        return $out;
    }
}
```

- [ ] **Step 3: Test ausführen (muss fehlschlagen)**

Run: `composer test:integration -- --filter GameRecomputeTest`
Expected: FAIL — `Class "App\Game\GameRecomputeService" not found`

- [ ] **Step 4: GameRecomputeService implementieren**

```php
<?php
declare(strict_types=1);

namespace App\Game;

use App\Support\Clock;
use DateTimeImmutable;

/**
 * Voller Recompute (Spec §7): liest ausschliesslich game_edge_pass und baut
 * alle *_cached-Felder neu. Bit-identisch zum Live-Pfad bei nicht
 * umkämpftem Besitz (§10.5).
 */
final class GameRecomputeService
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly EdgeRecalculator $recalc,
    ) {}

    /** @return int Anzahl neu berechneter Kanten. */
    public function recomputeAll(?DateTimeImmutable $now = null): int
    {
        $now ??= Clock::nowUtc();
        $this->repo->resetAllEdgeCaches();
        $ids = $this->repo->allEdgeIds();
        foreach ($ids as $edgeId) {
            $this->repo->refreshEdgeDiscovery($edgeId);
            $this->recalc->recalculate($edgeId, $now);
        }
        return count($ids);
    }
}
```

- [ ] **Step 5: CLI-Befehl in `src/Cli/Commands.php` ergänzen**

Konstruktor um optionale Dependency erweitern (am Ende):

```php
        private readonly ?HeatmapLinesService $heatmapLines = null,
        private readonly ?\App\Game\GameRecomputeService $gameRecompute = null,
    ) {}
```

Im `switch` von `run()`:

```php
            case 'game:recompute':
                return $this->recomputeGame();
```

Neue Methode + Hilfe-Eintrag:

```php
    private function recomputeGame(): int
    {
        if ($this->gameRecompute === null) {
            echo "GameRecomputeService nicht verfügbar.\n";
            return 1;
        }
        $n = $this->gameRecompute->recomputeAll();
        echo "Spiel neu berechnet: {$n} Kanten.\n";
        return 0;
    }
```

```php
        echo "  game:recompute      Berechnet alle Spiel-Kanten aus den Pässen neu\n";
```

- [ ] **Step 6: Test ausführen (muss passen)**

Run: `composer test:integration -- --filter GameRecomputeTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add src/Game/GameRecomputeService.php src/Game/GameRepository.php src/Cli/Commands.php tests/Integration/Game/GameRecomputeTest.php
git commit -m "feat(game): add full recompute service + game:recompute CLI"
```

---

### Task 10: GameReadService (BBox-Reads + Wert-Aufschlüsselung)

**Files:**
- Create: `src/Game/GameReadService.php`
- Modify: `src/Game/GameRepository.php` (Methode `routeForIngest`)
- Test: `tests/Integration/Game/GameReadServiceTest.php`

- [ ] **Step 1: `routeForIngest` in GameRepository ergänzen** (für den Re-Ingest-Endpunkt in Task 11)

```php
    /** @return array{user_id:int,public_id:string}|null */
    public function routeForIngest(int $routeId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT user_id, public_id FROM routes WHERE id = ?');
        $stmt->execute([$routeId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return ['user_id' => (int)$r['user_id'], 'public_id' => (string)$r['public_id']];
    }
```

- [ ] **Step 2: Failing test schreiben** (§10.10 BBox-Read + Felder + Wert-Aufschlüsselung)

```php
<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameReadService;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Routes\GeometryParser;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class GameReadServiceTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameReadService $read;
    private GameConfig $config;
    private int $u1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->config = new GameConfig($this->pdo);
        $this->read = new GameReadService($this->repo, $this->config);
        $this->u1 = $this->createUser('armin');

        $now = new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));
        $route = (new GeometryParser())->parse('{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13]]}');
        $segs = [new MatchedSegment(1001, 10, 11, 120.0, [[9.65, 47.12], [9.66, 47.13]], 'gravel', 18.0, 8.0, true, $now)];
        (new GameIngestionService(
            new FakeEdgeMatcher($segs), $this->repo,
            new EdgeRecalculator($this->repo, $this->config), $this->config, $this->pdo,
        ))->ingest(1, $this->u1, $route, true, $now);
    }

    public function testBboxReturnsEdgeInsideAndOmitsOutside(): void
    {
        $now = new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));
        $mine = $this->repo->riderClaimantId($this->u1);

        $inside = $this->read->edgesInBbox('9.6,47.1,9.7,47.2', $mine, $now, 100);
        $this->assertCount(1, $inside);
        $e = $inside[0];
        $this->assertSame('LineString', $e['geom']['type']);
        $this->assertSame('armin', $e['owner']['handle']);
        $this->assertTrue($e['owner_is_me']);
        $this->assertEqualsWithDelta(100.0, $e['value'], 0.1);
        $this->assertEqualsWithDelta(1.0, $e['freshness'], 0.01);
        $this->assertSame(1, $e['distinct_riders_total']);
        $this->assertSame('gravel', $e['surface_character']);

        $outside = $this->read->edgesInBbox('10.0,48.0,10.1,48.1', null, $now, 100);
        $this->assertSame([], $outside);
    }

    public function testEdgeDetailHasValueBreakdownAndCohort(): void
    {
        $now = new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));
        $mine = $this->repo->riderClaimantId($this->u1);
        $id = $this->read->edgesInBbox('9.6,47.1,9.7,47.2', null, $now, 100)[0]['id'];

        $detail = $this->read->edgeDetail((int)$id, $mine, $now);
        $this->assertNotNull($detail);
        $this->assertEqualsWithDelta(100.0, $detail['value']['pioneer'], 0.1);
        $this->assertGreaterThanOrEqual($detail['value']['pioneer'], $detail['value']['total']);
        $this->assertSame(0.0, $detail['value']['curation']);
        $this->assertCount(1, $detail['pioneer_cohort']);
        $this->assertSame('armin', $detail['pioneer_cohort'][0]['handle']);
        $this->assertSame(1, $detail['pioneer_cohort'][0]['rank']);
    }

    public function testFreshnessDecaysOnRead(): void
    {
        // 45 Tage später gelesen → Frische ~0.5, ohne neuen Pass/Recompute.
        $later = new DateTimeImmutable('2026-08-04T08:00:00Z', new DateTimeZone('UTC'));
        $e = $this->read->edgesInBbox('9.6,47.1,9.7,47.2', null, $later, 100)[0];
        $this->assertEqualsWithDelta(0.5, $e['freshness'], 0.02);
    }
}
```

- [ ] **Step 3: GameReadService implementieren**

```php
<?php
declare(strict_types=1);

namespace App\Game;

use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Baut die JSON-Strukturen der /game-Lesepfade (Spec §6) und rechnet die
 * Frische beim Lesen mit "jetzt" nach (Spec §7), damit lange ungenutzte
 * Kanten nicht zu frisch erscheinen.
 */
final class GameReadService
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameConfig $config,
    ) {}

    /**
     * @param string $bbox "minLon,minLat,maxLon,maxLat"
     * @return list<array<string,mixed>>
     */
    public function edgesInBbox(string $bbox, ?int $mineClaimantId, ?DateTimeImmutable $now, int $limit = 500): array
    {
        $now ??= Clock::nowUtc();
        [$minLon, $minLat, $maxLon, $maxLat] = $this->parseBbox($bbox);
        $rows = $this->repo->edgesInBbox($minLon, $minLat, $maxLon, $maxLat, null, $limit);
        $out = [];
        foreach ($rows as $row) {
            $edge = $this->formatEdge($row, $mineClaimantId, $now);
            if ($mineClaimantId !== null && $edge['owner'] !== null
                && $edge['owner']['claimant_id'] !== $mineClaimantId) {
                // &mine=1 ist über $mineClaimantId-Filter gemeint? Nein: hier
                // liefern wir alle; der Controller setzt $mineClaimantId nur
                // fuer owner_is_me. Filter "nur eigene" macht der Controller.
            }
            $out[] = $edge;
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function edgeDetail(int $edgeId, ?int $viewerClaimantId, ?DateTimeImmutable $now): ?array
    {
        $now ??= Clock::nowUtc();
        $row = $this->repo->edgeById($edgeId);
        if ($row === null) {
            return null;
        }
        $base = $this->formatEdge($row, $viewerClaimantId, $now);
        $breakdown = $this->valueBreakdown($row, $now);

        $cohort = [];
        $rank = 1;
        foreach ($this->repo->firstPassPerUser($edgeId, 10) as $c) {
            $cohort[] = [
                'rank'            => $rank++,
                'handle'          => $c['handle'],
                'first_ridden_at' => Clock::toIso8601(substr($c['first_ridden_at'], 0, 19)),
            ];
        }

        return [
            'id'                    => (int)$row['id'],
            'owner'                 => $base['owner'],
            'owner_is_me'           => $base['owner_is_me'],
            'value'                 => $breakdown,
            'distinct_riders_total' => (int)$row['distinct_riders_total'],
            'pioneer_cohort'        => $cohort,
            'freshness'             => $base['freshness'],
            'geom'                  => $base['geom'],
        ];
    }

    /** @return array<string,mixed> */
    public function me(int $claimantId): array
    {
        $s = $this->repo->meStats($claimantId);
        return [
            'held_edges'        => $s['held'],
            'pioneered_edges'   => $s['pioneered'],
            'held_length_m'     => $s['held_length_m'],
        ];
    }

    /** @return array<string,mixed> */
    private function formatEdge(array $row, ?int $viewerClaimantId, DateTimeImmutable $now): array
    {
        $ownerId = $row['owner_claimant_id'] !== null ? (int)$row['owner_claimant_id'] : null;
        $owner = null;
        if ($ownerId !== null) {
            $info = $this->repo->claimantInfo($ownerId);
            if ($info !== null) {
                $owner = $info;
            }
        }
        return [
            'id'                    => (int)$row['id'],
            'geom'                  => json_decode((string)$row['geom_geojson'], true),
            'owner'                 => $owner,
            'owner_is_me'           => $ownerId !== null && $ownerId === $viewerClaimantId,
            'value'                 => (float)$row['value_cached'],
            'freshness'             => $this->freshnessNow($row, $now),
            'distinct_riders_total' => (int)$row['distinct_riders_total'],
            'surface_character'     => $row['surface_character'] !== null ? (string)$row['surface_character'] : null,
        ];
    }

    private function freshnessNow(array $row, DateTimeImmutable $now): float
    {
        if ($row['last_pass_at'] === null) {
            return 0.0;
        }
        $dt = new DateTimeImmutable((string)$row['last_pass_at'], new DateTimeZone('UTC'));
        $ageDays = ($now->getTimestamp() - $dt->getTimestamp()) / 86400.0;
        return GameMath::presenceWeight($ageDays, $this->config->int('presence_window_days'));
    }

    /** @return array{total:float,pioneer:float,popularity:float,curation:float} */
    private function valueBreakdown(array $row, DateTimeImmutable $now): array
    {
        $edgeId = (int)$row['id'];
        $n = (int)$row['distinct_riders_total'];
        $windowDays = $this->config->int('presence_window_days');
        $sinceDate = $now->modify("-{$windowDays} days")->format('Y-m-d');
        $n90 = $this->repo->distinctRidersSince($edgeId, $sinceDate);

        $pioneer = GameMath::pioneer($n, $this->config->float('pioneer_p0'),
            $this->config->float('pioneer_k'), $this->config->float('pioneer_s'));
        $popularity = GameMath::popularity($n90, $this->config->float('popularity_c'));
        $curation = 0.0; // Stufe 1
        return [
            'total'      => GameMath::combineValue($pioneer, $popularity, $curation),
            'pioneer'    => $pioneer,
            'popularity' => $popularity,
            'curation'   => $curation,
        ];
    }

    /** @return array{0:float,1:float,2:float,3:float} [minLon,minLat,maxLon,maxLat] */
    private function parseBbox(string $bbox): array
    {
        $parts = array_map('floatval', explode(',', $bbox));
        if (count($parts) !== 4) {
            throw new \InvalidArgumentException('bbox erwartet minLon,minLat,maxLon,maxLat');
        }
        return [$parts[0], $parts[1], $parts[2], $parts[3]];
    }
}
```

- [ ] **Step 4: Test ausführen (muss passen)**

Run: `composer test:integration -- --filter GameReadServiceTest`
Expected: PASS (3 Tests, inkl. §10.10)

- [ ] **Step 5: Commit**

```bash
git add src/Game/GameReadService.php src/Game/GameRepository.php tests/Integration/Game/GameReadServiceTest.php
git commit -m "feat(game): add GameReadService (bbox reads, value breakdown, fresh-on-read)"
```

---

### Task 11: ValhallaEdgeMatcher + GameController + Upload-Hook + Verdrahtung

**Files:**
- Create: `src/Game/ValhallaEdgeMatcher.php`
- Create: `src/Controllers/Api/GameController.php`
- Create: `backend/VALHALLA_SETUP.md`
- Modify: `src/Game/GameRepository.php` (Methode `findRiderClaimantId`)
- Modify: `src/Routes/RouteService.php` (Konstruktor-Param + Hook)
- Modify: `public/index.php` (Wiring + Routen + CLI/Internal)
- Modify: `.env.example` (`VALHALLA_BASE_URL`, `GAME_ENABLED`)

- [ ] **Step 1: `findRiderClaimantId` (ohne Create) in GameRepository ergänzen**

```php
    /** Wie riderClaimantId, aber legt KEINEN Claimant an (für Lese-Pfade). */
    public function findRiderClaimantId(int $userId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM game_claimant WHERE type = "rider" AND user_id = ?'
        );
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }
```

- [ ] **Step 2: ValhallaEdgeMatcher implementieren** (echt-Adapter; Tests nutzen den Fake, dieser Pfad wird optional gegen echtes Valhalla geprüft)

```php
<?php
declare(strict_types=1);

namespace App\Game;

use App\Heatmap\ValhallaClient;
use App\Routes\ParsedRoute;
use RuntimeException;

/**
 * Echt-Adapter: nutzt den bestehenden ValhallaClient (trace_attributes) und
 * leitet daraus MatchedSegment inkl. Auth-Aggregate ab.
 *
 * Knoten-Identität: Valhalla liefert keine OSM-Node-IDs im Default-Response.
 * Wir bilden einen stabilen Integer-Knoten-Ref aus den gerundeten
 * Endkoordinaten (crc32) — zwei Kanten am selben Knoten teilen so denselben
 * Ref. Surrogat für Stufe 1; Details in backend/VALHALLA_SETUP.md.
 */
final class ValhallaEdgeMatcher implements EdgeMatcher
{
    public function __construct(private readonly ValhallaClient $client) {}

    public function match(ParsedRoute $route): array
    {
        $points = [];
        foreach ($route->points as $p) {
            $points[] = ['lat' => $p->lat, 'lon' => $p->lon];
        }
        $match = $this->client->matchTrace($points);
        if ($match === null) {
            throw new RuntimeException('Valhalla-Match fehlgeschlagen oder nicht erreichbar.');
        }

        $hasMotion = $route->startedAt !== null;
        $segments = [];
        foreach ($match->edges as $j => $edge) {
            if ($edge->wayId === null || count($edge->geometry) < 2) {
                continue;
            }
            $geom = $edge->geometry;
            $first = $geom[0];
            $last = $geom[count($geom) - 1];

            // Track-Punkte, die auf diese Kante gematcht wurden (für riddenAt/Auth).
            $idxs = [];
            foreach ($match->matchedPoints as $i => $mp) {
                if (($mp['edgeIndex'] ?? -1) === $j) {
                    $idxs[] = $i;
                }
            }

            $riddenAt = $route->startedAt ?? \App\Support\Clock::nowUtc();
            $maxHacc = null;
            $firstTs = null;
            $lastTs = null;
            foreach ($idxs as $i) {
                $pt = $route->points[$i] ?? null;
                if ($pt === null) {
                    continue;
                }
                if ($pt->timestamp !== null) {
                    $firstTs ??= $pt->timestamp;
                    $lastTs = $pt->timestamp;
                }
                if ($pt->horizontalAccuracyM !== null) {
                    $maxHacc = $maxHacc === null ? $pt->horizontalAccuracyM : max($maxHacc, $pt->horizontalAccuracyM);
                }
            }
            if ($firstTs !== null) {
                $riddenAt = $firstTs;
            }

            $avgSpeedKmh = null;
            if ($firstTs !== null && $lastTs !== null) {
                $dt = $lastTs->getTimestamp() - $firstTs->getTimestamp();
                if ($dt > 0) {
                    $avgSpeedKmh = ($edge->lengthM / $dt) * 3.6;
                }
            }

            $segments[] = new MatchedSegment(
                wayId: $edge->wayId,
                nodeARef: $this->nodeRef($first[0], $first[1]),
                nodeBRef: $this->nodeRef($last[0], $last[1]),
                lengthM: $edge->lengthM,
                geometry: $geom,
                surface: $edge->surface,
                avgSpeedKmh: $avgSpeedKmh,
                maxHaccM: $maxHacc,
                hasMotion: $hasMotion,
                riddenAt: $riddenAt,
            );
        }
        return $segments;
    }

    private function nodeRef(float $lon, float $lat): int
    {
        // ~1.1m Raster (1e-5 Grad). crc32 → positiver 32-bit Integer.
        $key = round($lat, 5) . ':' . round($lon, 5);
        return (int)sprintf('%u', crc32($key));
    }
}
```

- [ ] **Step 3: GameController implementieren**

```php
<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameReadService;
use App\Game\GameRepository;
use App\Http\Request;
use App\Http\Response;
use App\Routes\GeometryParser;
use App\Routes\RouteService;

/**
 * HTTP-Adapter für die /game-Endpunkte (Spec §6). Logik liegt in
 * GameReadService / GameIngestionService; hier nur Parsing + JSON.
 */
final class GameController
{
    public function __construct(
        private readonly GameReadService $read,
        private readonly GameRepository $repo,
        private readonly GameIngestionService $ingest,
        private readonly GameConfig $config,
        private readonly RouteService $routes,
        private readonly GeometryParser $parser,
    ) {}

    public function edges(Request $req): void
    {
        $bbox = (string)($req->query['bbox'] ?? '');
        if ($bbox === '') {
            Response::error('bad_request', 'bbox erforderlich (minLon,minLat,maxLon,maxLat).', 400);
        }
        $viewer = $this->viewerClaimant($req);
        $onlyMine = (string)($req->query['mine'] ?? '') === '1';
        try {
            $edges = $this->read->edgesInBbox($bbox, $viewer, null, 1000);
        } catch (\InvalidArgumentException $e) {
            Response::error('bad_request', $e->getMessage(), 400);
        }
        if ($onlyMine && $viewer !== null) {
            $edges = array_values(array_filter(
                $edges,
                static fn($e) => $e['owner'] !== null && $e['owner']['claimant_id'] === $viewer,
            ));
        }
        Response::json(['edges' => $edges]);
    }

    public function edge(Request $req): void
    {
        $id = (int)($req->routeParams['id'] ?? 0);
        $detail = $this->read->edgeDetail($id, $this->viewerClaimant($req), null);
        if ($detail === null) {
            Response::error('not_found', 'Kante nicht gefunden.', 404);
        }
        Response::json($detail);
    }

    public function me(Request $req): void
    {
        $uid = $this->userId($req);
        $claimant = $this->repo->riderClaimantId($uid);
        Response::json($this->read->me($claimant));
    }

    public function config(Request $req): void
    {
        $this->userId($req); // Bearer erzwungen
        Response::json(['config' => $this->config->all()]);
    }

    public function reingest(Request $req): void
    {
        $uid = $this->userId($req);
        $routeId = (int)($req->routeParams['route_id'] ?? 0);
        $route = $this->repo->routeForIngest($routeId);
        if ($route === null) {
            Response::error('not_found', 'Route nicht gefunden.', 404);
        }
        if ($route['user_id'] !== $uid) {
            Response::error('forbidden', 'Nur der Eigentümer darf re-ingestieren.', 403);
        }
        $loaded = $this->routes->loadPayloadByPublicId($route['public_id']);
        $parsed = $this->parser->parse($loaded['payload']);
        $summary = $this->ingest->ingest($routeId, $uid, $parsed, $parsed->startedAt !== null, null);
        Response::json($summary);
    }

    private function viewerClaimant(Request $req): ?int
    {
        $u = $req->user;
        if ($u === null) {
            return null;
        }
        $uid = (int)($u->internal_id ?? 0);
        return $uid > 0 ? $this->repo->findRiderClaimantId($uid) : null;
    }

    private function userId(Request $req): int
    {
        $u = $req->user;
        $uid = $u !== null ? (int)($u->internal_id ?? 0) : 0;
        if ($uid <= 0) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }
        return $uid;
    }
}
```

- [ ] **Step 4: RouteService um Hook erweitern**

Konstruktor (nach `$hints`):

```php
        private readonly ?RouteHintService $hints = null,
        // Stufe 1 Gamification: nicht-blockierender Hook nach dem Upload.
        private readonly ?\App\Game\GameIngestionService $game = null,
    ) {}
```

Nach `$pdo->commit();` (Ende des try-Blocks) und VOR `$route = $this->routes->findByPublicId(...)` einfügen:

```php
        // Stufe 1: Spiel-Ingestion best effort — darf den Upload NIE kippen
        // (Spec §2). Bei Valhalla-Ausfall bleibt die Route gespeichert; ein
        // späterer POST /game/ingest/{route_id} holt es nach.
        if ($this->game !== null) {
            try {
                $this->game->ingest($routeId, $userId, $parsed, $parsed->startedAt !== null, null);
            } catch (Throwable $e) {
                error_log('RouteService: Spiel-Ingestion fehlgeschlagen (pending): ' . $e->getMessage());
            }
        }
```

- [ ] **Step 5: Wiring in `public/index.php`**

Nach dem `$valhalla`-Block (ca. Zeile 185) das Spiel verdrahten:

```php
// Stufe 1 Gamification. Lokaler Valhalla via VALHALLA_BASE_URL (Fallback VALHALLA_URL).
use App\Game\GameConfig;
use App\Game\GameRepository;
use App\Game\EdgeRecalculator;
use App\Game\ValhallaEdgeMatcher;
use App\Game\GameIngestionService;
use App\Game\GameReadService;
use App\Game\GameRecomputeService;
use App\Controllers\Api\GameController;
use App\Database\Db;

$gameEnabled = $config->bool('GAME_ENABLED', true);
$gameConfig  = new GameConfig(Db::pdo());
$gameRepo    = new GameRepository(Db::pdo());
$gameRecalc  = new EdgeRecalculator($gameRepo, $gameConfig);
$gameValhalla = new ValhallaClient(
    (string)($config->get('VALHALLA_BASE_URL', $config->get('VALHALLA_URL', 'http://localhost:8002')) ?? 'http://localhost:8002'),
    (string)($config->get('VALHALLA_COSTING', 'bicycle') ?? 'bicycle'),
);
$gameMatcher   = new ValhallaEdgeMatcher($gameValhalla);
$gameIngest    = new GameIngestionService($gameMatcher, $gameRepo, $gameRecalc, $gameConfig, Db::pdo());
$gameRead      = new GameReadService($gameRepo, $gameConfig);
$gameRecompute = new GameRecomputeService($gameRepo, $gameRecalc);
```

> Die `use`-Imports gehören an den Kopf der Datei zu den anderen `use`-Statements; hier zur Übersicht beim Block gezeigt.

`RouteService`-Konstruktion (Zeile 172) um den Hook erweitern (nur wenn aktiviert):

```php
$routeService = new RouteService(
    $routeRepo, $routeStorage, new GeometryParser(), new GeometryStats(), $routeHints,
    $gameEnabled ? $gameIngest : null,
);
```

> **Reihenfolge-Hinweis:** Der `$gameIngest`-Block muss VOR der `$routeService`-Zeile stehen. Da `$gameConfig` etc. die DB nutzen, ggf. den `$routeService`-Aufbau nach unten ziehen oder den Game-Block vor Zeile 167 platzieren.

Controller + Routen (bei den anderen `$api*`-Controllern / Routen):

```php
$apiGame = new GameController($gameRead, $gameRepo, $gameIngest, $gameConfig, $routeService, new GeometryParser());

$router->get("{$apiBase}/game/edges",            fn($r) => $apiGame->edges($r),    [$optionalBearer]);
$router->get("{$apiBase}/game/edges/{id}",       fn($r) => $apiGame->edge($r),     [$optionalBearer]);
$router->get("{$apiBase}/game/me",               fn($r) => $apiGame->me($r),       [$requireBearer]);
$router->get("{$apiBase}/game/config",           fn($r) => $apiGame->config($r),   [$requireBearer]);
$router->post("{$apiBase}/game/ingest/{route_id}", fn($r) => $apiGame->reingest($r), [$requireBearer]);
```

CLI-Dispatch (Zeile 214) und Internal-Handler (Zeile 523) `Commands` um `$gameRecompute` erweitern:

```php
$cli = new Commands($basePath, $tokens, $routeService, $config, new NotificationService(), new HeatmapService(), $heatmapLines, $gameRecompute);
```

Optionaler Internal-Trigger:

```php
$router->get('/internal/cron/game-recompute',  fn($r) => $runInternal($r, 'game:recompute'));
$router->post('/internal/cron/game-recompute', fn($r) => $runInternal($r, 'game:recompute'));
```

(im `$runInternal`-`use(...)` zusätzlich `$gameRecompute` aufnehmen und an den dortigen `new Commands(...)` durchreichen.)

- [ ] **Step 6: `.env.example` ergänzen** (bei den Valhalla-Keys)

```bash
# Stufe 1 Gamification (Territorialspiel)
GAME_ENABLED=true
# Lokaler Valhalla fuer Map-Matching (trace_attributes). Fallback: VALHALLA_URL.
VALHALLA_BASE_URL=http://localhost:8002
```

- [ ] **Step 7: `backend/VALHALLA_SETUP.md` schreiben**

```markdown
# Valhalla-Setup für Gamification Stufe 1

## Zweck
Map-Matching hochgeladener Routen auf OSM-Kanten via `POST /trace_attributes`
(costing `bicycle`, `shape_match: map_snap`). Läuft auf einem LOKALEN Server,
nicht auf grava.world. Das Backend ruft ihn über `VALHALLA_BASE_URL` an.

## Tiles bauen (Testgebiet)
1. OSM-Extrakt der Region laden (z. B. Geofabrik, `region-latest.osm.pbf`).
2. `valhalla_build_config --mjolnir-tile-dir ./valhalla_tiles > valhalla.json`
3. `valhalla_build_tiles -c valhalla.json region-latest.osm.pbf`
4. `valhalla_service valhalla.json 1` startet den Dienst (Default Port 8002).

## Knoten-Identität (Stufe 1)
`trace_attributes` liefert im Default keine OSM-Node-IDs. `ValhallaEdgeMatcher`
bildet daher einen stabilen Integer-Knoten-Ref aus den gerundeten
Endkoordinaten der Kante (`crc32(round(lat,5):round(lon,5))`, ~1.1 m Raster).
Zwei Kanten am selben Knoten teilen denselben Ref → `game_edge`-Schlüssel
`(way_id, node_a_id, node_b_id)` bleibt stabil, solange der Tile-Stand gleich
ist. Für exakte OSM-Knoten in späteren Stufen: `trace_attributes` mit
`filters` auf `edge.end_node.*` erweitern und den Matcher anpassen.

## Fehlerfall
Ist Valhalla nicht erreichbar, wirft der Matcher; der Upload-Hook schluckt das
(Route bleibt gespeichert). Re-Run per `POST /api/v1/game/ingest/{route_id}`.
```

- [ ] **Step 8: Syntax/Smoke prüfen + bestehende Tests**

Run: `php -l public/index.php && php -l src/Routes/RouteService.php && php -l src/Controllers/Api/GameController.php && composer test`
Expected: `No syntax errors` + alle Tests grün (Unit + Integration)

- [ ] **Step 9: Commit**

```bash
git add src/Game/ValhallaEdgeMatcher.php src/Controllers/Api/GameController.php src/Game/GameRepository.php src/Routes/RouteService.php public/index.php .env.example backend/VALHALLA_SETUP.md
git commit -m "feat(game): wire endpoints, valhalla matcher, non-blocking upload hook"
```

---

### Task 12: Testbericht + API-Doku + DoD-Verifikation

**Files:**
- Create: `scripts/game_report.php`
- Create: `backend/GAME_STAGE1_TESTREPORT.md` (generiert)
- Modify: `docs/API.md` (Abschnitt `/game/*`)

- [ ] **Step 1: Report-Generator schreiben** (`scripts/game_report.php`)

```php
<?php
declare(strict_types=1);

// Erzeugt backend/GAME_STAGE1_TESTREPORT.md: Pionier-Golden-Tabelle +
// Mapping der Akzeptanzkriterien (§10) auf die zugehoerigen Tests.
require __DIR__ . '/../vendor/autoload.php';

use App\Game\GameMath;

$rows = [];
foreach ([1, 5, 10, 12, 20, 30] as $n) {
    $rows[] = sprintf('| %d | %.2f |', $n, GameMath::pioneer($n, 100.0, 12.0, 4.0));
}

$report = "# Gamification Stufe 1 — Testbericht\n\n"
    . "Generiert: " . gmdate('Y-m-d\TH:i:s\Z') . "\n\n"
    . "## Pionier-Golden-Tabelle (P0=100, k=12, s=4)\n\n"
    . "| n | pioneer(n) |\n|---|---|\n" . implode("\n", $rows) . "\n\n"
    . "## Akzeptanzkriterien → Tests\n\n"
    . "| § | Kriterium | Test |\n|---|---|---|\n"
    . "| 10.1 | Pionier-Formel | tests/Unit/Game/GameMathTest::testPioneerGoldenNumbers |\n"
    . "| 10.2 | Präsenz-Verfall | tests/Unit/Game/GameMathTest::testPresenceWeightLinearDecay |\n"
    . "| 10.3 | Wert-Verknüpfung | tests/Unit/Game/GameMathTest::testValueAt* |\n"
    . "| 10.4 | Ingest → Besitz | tests/Integration/Game/GameIngestionTest::testIngestGivesOwnershipToFirstRider |\n"
    . "| 10.5 | Tages-Deckel + Recompute | GameIngestionTest::testReingest* + GameRecomputeTest |\n"
    . "| 10.6 | Pionier-Abfall (12 Fahrer) | GameIngestionTest::testTwelveDistinctRiders* |\n"
    . "| 10.7 | Hysterese | tests/Integration/Game/EdgeRecalculatorTest::testHysteresis* |\n"
    . "| 10.8 | Authentizität | GameIngestionTest::testAuthFiltersReject* |\n"
    . "| 10.9 | Valhalla-Ausfall | GameIngestionTest::testMatcherFailure* |\n"
    . "| 10.10 | BBox-Read | tests/Integration/Game/GameReadServiceTest::testBbox* |\n";

file_put_contents(__DIR__ . '/../backend/GAME_STAGE1_TESTREPORT.md', $report);
echo "Testbericht geschrieben: backend/GAME_STAGE1_TESTREPORT.md\n";
echo implode("\n", $rows) . "\n";
```

- [ ] **Step 2: Volle Test-Suite grün + Report erzeugen**

Run: `composer test && php scripts/game_report.php`
Expected: Alle Unit- + Integrationstests PASS; Golden-Tabelle zeigt `1→100.00, 10→67.46, 12→50.00, 20→11.47, 30→2.50`; `backend/GAME_STAGE1_TESTREPORT.md` existiert.

- [ ] **Step 3: `docs/API.md` ergänzen** (kurzer Abschnitt am Ende)

```markdown
## Game (Stufe 1 — Territorialspiel)

- `GET /api/v1/game/edges?bbox=minLon,minLat,maxLon,maxLat[&mine=1]` — eingefärbte Kanten im Ausschnitt (OptionalBearer). Antwort: `{ "edges": [ { id, geom, owner, owner_is_me, value, freshness, distinct_riders_total, surface_character } ] }`.
- `GET /api/v1/game/edges/{id}` — Detail inkl. `value` (total/pioneer/popularity/curation) + `pioneer_cohort` (≤10).
- `GET /api/v1/game/me` — eigene Statistik (gehaltene Kanten, Erstbefahrungen, gehaltene Länge). Bearer.
- `GET /api/v1/game/config` — aktuelle `game_config`-Werte. Bearer.
- `POST /api/v1/game/ingest/{route_id}` — Re-Run der Ingestion (idempotent), nur Owner. Antwort: Match-/Pass-/Skip-Zähler.

Ingestion läuft automatisch nicht-blockierend nach jedem Route-Upload. Voller Recompute: `php public/index.php game:recompute`.
```

- [ ] **Step 4: DoD-Checkliste verifizieren** (Spec §11)

Manuell gegen die Spec abhaken:
- [ ] Tabellen `game_claimant/node/edge/edge_pass` + `game_config` migriert (Task 1)
- [ ] `game_ingest` am Upload-Pfad nicht-blockierend + `POST /game/ingest/{id}` (Tasks 8, 11)
- [ ] Valhalla hinter mockbarem Interface; `VALHALLA_BASE_URL`; `VALHALLA_SETUP.md` (Tasks 5, 11)
- [ ] Präsenz/Besitz/Wert/Frische live + voller `game:recompute` identisch (Tasks 7, 9)
- [ ] Endpunkte `GET /game/edges`, `/edges/{id}`, `/me`, `/config` (Tasks 10, 11)
- [ ] Alle Akzeptanztests §10 grün + Testbericht (Task 12)
- [ ] `game_config` ohne Deploy änderbar (Task 2)

- [ ] **Step 5: Commit**

```bash
git add scripts/game_report.php backend/GAME_STAGE1_TESTREPORT.md docs/API.md
git commit -m "docs(game): add test report generator, API docs, DoD verification"
```

---

## Self-Review (vom Plan-Autor durchgeführt)

**1. Spec-Abdeckung:** §3 Datenmodell → Task 1. §3.5 Config → Task 2. §4 Ingestion (Match/Auth/Pass/Pionier) → Tasks 5–8. §5 Berechnung → Tasks 3, 7. §6 Endpunkte → Tasks 10–11. §7 Recompute → Tasks 7, 9. §8 Privacy (`start_buffer_m`=0) → in `game_config` geseedet, kein Code-Effekt (bewusst). §9 Test-Strategie (Mock-Matcher, injizierbare Zeit, Fixtures) → Tasks 5, 7. §10.1–10.10 → je ein Test, siehe Report-Mapping. §11 DoD → Task 12.

**2. Platzhalter-Scan:** Keine TBD/TODO in Code-Steps; jeder Code-Step enthält vollständigen Code.

**3. Typ-Konsistenz:** `MatchedSegment`-Felder identisch in Tasks 5/8/9/10/11. `GameRepository`-Methoden (`riderClaimantId`, `findRiderClaimantId`, `upsertNode`, `upsertEdge`, `insertPassIfAbsent`, `refreshEdgeDiscovery`, `updateEdgeCached`, `resetAllEdgeCaches`, `edgesInBbox`, `edgeById`, `allEdgeIds`, `claimantInfo`, `firstPassPerUser`, `distinctRidersTotal`, `distinctRidersSince`, `meStats`, `routeForIngest`) konsistent über Tasks 6/7/9/10/11. `EdgeRecalculator::recalculate(int,?DateTimeImmutable)` und `GameIngestionService::ingest(int,int,ParsedRoute,bool,?DateTimeImmutable)` identisch in allen Aufrufern.

**Bekannte Grenzen (bewusst, dokumentiert):** (a) Voller Recompute ist bit-identisch nur bei nicht umkämpftem Besitz (§10.5-Fall abgedeckt); chronologischer Replay = spätere Erweiterung. (b) Knoten-Identität via crc32-Surrogat statt OSM-Node-ID (`VALHALLA_SETUP.md`). (c) „pending"-Status implizit statt eigener Tabelle. (d) `ValhallaEdgeMatcher` wird nur optional gegen echtes Valhalla geprüft; die deterministische CI nutzt den Fake-Matcher.

## Ausführung

Nach Freigabe dieses Plans: Umsetzung Task-für-Task. Empfohlen via **subagent-driven-development** (frischer Subagent pro Task + Review dazwischen) oder **inline** mit Checkpoints. Jeder Task endet mit grünen Tests + Commit, sodass jederzeit ein lauffähiger Zwischenstand existiert.

