# Segment-Speed (Tempo-Wertung) — Backend-Anweisung

> **Status: umgesetzt (2026-06-24).** Migration `0026_game_segment_effort`, Ingestion-Hook,
> `SegmentSpeedService` + beide Endpunkte sind gebaut; `tests/Integration/Game/SegmentSpeedTest.php`
> (9 Tests) ist grün. Dieses Dokument bleibt als Referenz/Vertrag stehen. Beleg: `BACKEND_STATUS.md`.

**Audience:** Cursor / Backend-Agent. Build-Order für eine KOM/QOM-artige **Tempo-Wertung** pro
Segment (`game_edge`): Wie schnell ist ein Fahrer eine Kante gefahren, und wer hält die Bestzeit?
Aufsetzend auf der bestehenden Spiel-Ingestion (`GameIngestionService`, Spec §4–§5) und im selben
Muster wie die Ranglisten (`PLAYER_LEADERBOARD_BACKEND.md`, `CREW_LEADERBOARD_BACKEND.md`):
Ingestion schreibt event-sourced, das Lesen aggregiert ohne Recompute.

## Konzept
- Eine **Anstrengung** (Effort) = eine durchgehende Befahrung **einer Kante** mit gemessener Dauer.
- Die **Tempo-Wertung** je Kante = die **Bestzeit pro Fahrer** (MIN `duration_s`), aufsteigend gerankt.
- Bewusst **getrennt vom Besitz-Modell**: `game_edge_pass` bleibt tagesgedeckelt (Owner/Präsenz).
  Efforts sind ein **paralleler, nicht gedeckelter** Strom — sonst ginge die zweite, schnellere
  Fahrt am selben Tag verloren (der Tages-Deckel verwirft sie als „skip").

## Datenmodell-Hinweis — Migration **additiv** (`0026_game_segment_speed.sql`)
Keine bestehende Tabelle wird verändert. Eine neue Tabelle + neue `game_config`-Keys.

```sql
-- Tempo-Wertung: jede authentische, getimte Befahrung einer Kante.
-- NICHT tagesgedeckelt (Best-of-Leaderboard) — im Gegensatz zu game_edge_pass.
CREATE TABLE IF NOT EXISTS game_segment_effort (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  edge_id       BIGINT UNSIGNED NOT NULL,
  claimant_id   BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  route_id      BIGINT UNSIGNED NOT NULL,
  ridden_at     DATETIME(3)     NOT NULL,
  duration_s    DOUBLE          NOT NULL,
  avg_speed_kmh DOUBLE          NOT NULL,
  length_m      DOUBLE          NOT NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_effort_edge_dur  (edge_id, duration_s),     -- Leaderboard je Kante
  KEY idx_effort_user_edge (user_id, edge_id, duration_s), -- Bestzeit je Fahrer
  KEY idx_effort_ridden    (ridden_at),               -- Zeitfenster-Filter
  CONSTRAINT fk_effort_edge     FOREIGN KEY (edge_id)     REFERENCES game_edge(id)     ON DELETE CASCADE,
  CONSTRAINT fk_effort_claimant FOREIGN KEY (claimant_id) REFERENCES game_claimant(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (config_key, config_value) VALUES
  ('segment_min_length_m',      '200'),
  ('segment_min_speed_kmh',     '5'),
  ('segment_max_speed_kmh',     '80'),
  ('segment_leaderboard_top_n', '100')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
```

Migration wie üblich einspielen: `php public/index.php cli:migrate` (oder `/internal/migrate?token=…`).

## Ingestion-Detail
Die Effort-Aufzeichnung hängt sich in die bestehende Segment-Schleife von
[`src/Game/GameIngestionService.php`](../src/Game/GameIngestionService.php) ein — **innerhalb derselben
Transaktion**, **nach** den Authentizitäts-Filtern (Speed/HAcc/Motion/Privatzone) und **unabhängig**
vom Day-Cap-Ergebnis des Pass-Inserts.

1. **Dauer-Quelle.** [`src/Game/MatchedSegment.php`](../src/Game/MatchedSegment.php) um ein optionales
   Feld `?float $durationS = null` erweitern (rückwärtskompatibel). Der echte Matcher
   (`ValhallaEdgeMatcher`) füllt es aus Ein-/Austritts-Zeit auf der Kante; fehlt es, wird abgeleitet:
   `duration_s = length_m / (avg_speed_kmh / 3.6)`.
2. **Effort-Gate** (pro Segment, nur wenn der Pass die Auth-Filter passiert hat):
   - `seg->hasMotion === true` **und** `seg->avgSpeedKmh !== null` (nur Live-GPS-Fahrten; vertrauens-
     würdige Importe ohne Timing erzeugen **keinen** Effort → `skipped_effort_no_timing++`).
   - `seg->lengthM >= config('segment_min_length_m')` (sonst `skipped_effort_short++`).
   - `segment_min_speed_kmh <= avg_speed_kmh <= segment_max_speed_kmh` (Plausibilität; sonst
     `skipped_effort_implausible++`).
3. **Schreiben.** Neue Repo-Methode `GameRepository::insertSegmentEffort(edgeId, claimantId, userId,
   routeId, riddenAt, durationS, avgSpeedKmh, lengthM)` → eine Zeile in `game_segment_effort`.
   **Kein** `INSERT … IF ABSENT`: jede qualifizierte Befahrung zählt (Best-of entsteht beim Lesen).
4. **Day-Cap-Entkopplung.** Der Effort wird **auch dann** geschrieben, wenn `insertPassIfAbsent`
   `false` liefert (zweite Fahrt desselben Tages). Besitz/Präsenz bleiben tagesgedeckelt, Tempo nicht.
5. **Summary/Log.** `ingest()`-Rückgabe um `efforts_new`, `skipped_effort_no_timing`,
   `skipped_effort_short`, `skipped_effort_implausible` erweitern und in `insertIngestLog`-Meta mitloggen
   (analog zu den vorhandenen `skipped_*`-Zählern).

Privatzonen wirken bereits vor diesem Punkt (in-Zone-Segmente werden gar nicht erst zu Kanten/Pässen)
— Efforts erben den Schutz automatisch.

## Endpunkte
Registrierung in [`public/index.php`](../public/index.php) bei den übrigen `…/game/*`-Routen.

### 1. `GET /api/v1/game/segments/{edgeId}/leaderboard` — OptionalBearer
Wie `/game/edges` (anonym OK). `scope=friends`, `is_me` und der eigene Rang brauchen einen Bearer.

| Param | Werte | Default | Bedeutung |
|---|---|---|---|
| `scope` | `world` \| `friends` | `world` | `friends` = Fahrer, denen der Anfragende folgt (Follow-Graph), inkl. self. Ohne Bearer → 401. |
| `window` | `week` \| `season` \| `all` | `season` | 7 Tage / `presence_window_days` (90) / gesamt — auf `ridden_at` angewendet. |

```json
{
  "segment": { "edge_id": 1234, "length_m": 540.0, "surface": "gravel" },
  "entries": [
    { "rank": 1, "handle": "armin", "duration_s": 72.4, "avg_speed_kmh": 26.8, "achieved_at": "2026-06-18T09:12:00Z", "is_me": true },
    { "rank": 2, "handle": "lea",   "duration_s": 75.1, "avg_speed_kmh": 25.9, "achieved_at": "2026-06-10T17:40:00Z", "is_me": false }
  ],
  "me": { "rank": 1, "duration_s": 72.4 }
}
```
- Eine Zeile **pro Fahrer** = dessen Bestzeit (MIN `duration_s`) im Fenster. Aufsteigend nach
  `duration_s`, fortlaufender `rank` (1-basiert). Bei Gleichstand deterministisch (frühere
  `achieved_at`, dann `user_id`). **Top-N** = `segment_leaderboard_top_n` (100).
- `avg_speed_kmh`/`achieved_at` gehören zur Best-Zeit-Befahrung des Fahrers.
- `is_me`: Zeile des Anfragenden (nur mit Bearer; sonst überall `false`).
- `me`: Rang + Bestzeit des Anfragenden **auch außerhalb der Top-N** (`null`, wenn ausgeloggt/ohne
  Effort). Nur public-Handles aktiver User werden gerendert (sonst `handle = null`).
- Existiert die Kante nicht → **404**; existiert sie ohne Efforts (im Fenster) → 200 mit `entries: []`.

### 2. `GET /api/v1/game/me/segments` — Bearer
Bestzeiten des Anfragenden über alle Segmente, mit aktuellem Rang. Für die „Meine Segmente"-Liste.

| Param | Werte | Default |
|---|---|---|
| `window` | `week` \| `season` \| `all` | `season` |
| `limit` | 1..100 | 50 |
| `offset` | >= 0 | 0 |

```json
{
  "segments": [
    { "edge_id": 1234, "length_m": 540.0, "surface": "gravel",
      "best_duration_s": 72.4, "best_avg_speed_kmh": 26.8, "achieved_at": "2026-06-18T09:12:00Z",
      "rank": 1, "total_riders": 8 }
  ],
  "pagination": { "limit": 50, "offset": 0, "total": 23, "has_more": false }
}
```
- Sortierung: zuletzt erzielte Bestzeit zuerst (`achieved_at DESC`). `rank`/`total_riders` beziehen sich
  auf das gewählte `window`.

> Service-Schnitt: ein `SegmentSpeedService` (analog `PlayerLeaderboardService`) mit
> `leaderboard(edgeId, scope, window, ?userId)` und `mySegments(userId, window, limit, offset)`; reine
> Lese-Aggregation aus `game_segment_effort` (+ `users`/`follows` für Handle/Scope). Endpunkte über den
> bestehenden `GameController` oder einen schlanken `SegmentSpeedController`.

## Config-Keys (neu, mit Defaults in `GameConfig::DEFAULTS` spiegeln)
| Key | Default | Bedeutung |
|---|---|---|
| `segment_min_length_m` | `200` | Kürzere Kanten bekommen keine Tempo-Wertung (Rausch-Filter). |
| `segment_min_speed_kmh` | `5` | Plausibilitäts-Untergrenze für einen Effort. |
| `segment_max_speed_kmh` | `80` | Plausibilitäts-Obergrenze (kann `auth_max_speed_kmh` spiegeln). |
| `segment_leaderboard_top_n` | `100` | Kappung der Leaderboard-Einträge. |

`window=season` nutzt den bestehenden `presence_window_days` (90) als Fensterlänge — kein eigener Key.

## Test-Harness
Neue Datei `tests/Integration/Game/SegmentSpeedTest.php` (Muster:
[`tests/Integration/Game/GameIngestionTest.php`](../tests/Integration/Game/GameIngestionTest.php)),
`extends Tests\IntegrationTestCase`. Setup wie dort: `GameRepository`/`GameConfig` aus `$this->pdo`,
`FakeEdgeMatcher` für deterministische Segmente, `GameIngestionService::ingest(...)` ausführen.

- Segment-Factory mit explizitem `avgSpeedKmh`/`durationS`/`lengthM` bauen (`MatchedSegment`), um
  Effort-Gates gezielt zu treffen (kurz, zu langsam/schnell, gültig).
- Assertions direkt gegen `game_segment_effort` (Row-Count/`duration_s`) und gegen
  `SegmentSpeedService::leaderboard(...)` / `::mySegments(...)`.
- DB-Isolation via `IntegrationTestCase` (TRUNCATE in `setUp`); Tests werden ohne Test-DB sauber
  übersprungen. Lauf: `vendor/bin/phpunit tests/Integration/Game/SegmentSpeedTest.php`.

## Akzeptanzkriterien
1. Migration `0026` legt `game_segment_effort` (inkl. Indizes + FKs) an und seedet die vier Config-Keys; keine bestehende Tabelle/Spalte wird verändert.
2. Eine Live-Fahrt (`hasMotion=true`, `avgSpeedKmh!=null`) über eine ausreichend lange Kante erzeugt genau **einen** Effort mit korrekter `duration_s` (aus `durationS` bzw. `length_m / (avg_speed_kmh/3.6)`); `efforts_new=1`.
3. Eine zweite, **schnellere** Fahrt desselben Fahrers am **selben Tag** erzeugt einen **zweiten** Effort (kein Day-Cap), obwohl der Pass als `skipped_day_cap` verbucht wird.
4. Segment kürzer als `segment_min_length_m` → kein Effort (`skipped_effort_short`); Effort mit Speed außerhalb `[segment_min_speed_kmh, segment_max_speed_kmh]` → `skipped_effort_implausible`; Quelle ohne Timing (Strava/Import, `avgSpeedKmh=null`) → `skipped_effort_no_timing`.
5. `GET /game/segments/{edgeId}/leaderboard` (anonym) → 200 mit `entries[]` aufsteigend nach `duration_s`, fortlaufendem `rank`, **einer Zeile pro Fahrer** (dessen Bestzeit), `is_me` überall false, `me=null`.
6. Mit Bearer → genau eine Zeile `is_me=true` (falls der Fahrer einen Effort hat) und `me` gefüllt, auch wenn er außerhalb der Top-N liegt.
7. `scope=friends` ohne Bearer → 401; mit Bearer → nur gefolgte Fahrer (+ self), sonst leer (kein Fehler). `window` grenzt Efforts korrekt über `ridden_at` ein.
8. Unbekannte `edgeId` → 404; existierende Kante ohne Efforts im Fenster → 200 mit `entries: []`, `me=null`.
9. `GET /game/me/segments` (Bearer) → Bestzeiten des Anfragenden mit korrektem `rank`/`total_riders` je Segment, `achieved_at DESC`, korrekt paginiert (`limit/offset/total/has_more`). Ohne Bearer → 401.
10. Tie-Break ist deterministisch (gleiche `duration_s` → frühere `achieved_at`, dann kleinere `user_id`); Top-N-Kappung gemäß `segment_leaderboard_top_n`; Privatzonen-Segmente erzeugen keine Efforts; reine Lese-Aggregation (recompute-neutral, später cachebar).
