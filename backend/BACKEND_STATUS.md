# Backend-Status — Audit (von Cursor belegt)

**Audience:** Mensch + Cursor / Backend-Agent. Antwort auf den Audit-Auftrag: pro Spec geprüft
**(1) Route registriert? (2) Schema/Migration da? (3) Tests grün?** → Einstufung **gebaut / teilweise /
offen** mit konkretem Beleg (Datei : Route/Migration/Test). Ersetzt die „unbestätigt"-Annahmen durch
Code-Belege.

**Stand:** 2026-06-24 (aktualisiert nach Umsetzung Segment-Speed). **Test-Lauf:** Game- + Discovery-Suite
grün inkl. der neuen `SegmentSpeedTest` (9 Tests). Routen-Belege aus
[`public/index.php`](../public/index.php), Schema aus [`migrations/`](../migrations/).

## Überblick

| Spec / Feature | Route | Schema | Tests | Einstufung |
|---|---|---|---|---|
| Follow-Listen | ✅ | n/a | ✅ | **gebaut** |
| Solo-/Spieler-Rangliste | ✅ | n/a (Lese-Aggregation) | ✅ | **gebaut** |
| Crew-Rangliste | ✅ | n/a (Lese-Aggregation) | ✅ | **gebaut** |
| Block/Unblock | ✅ | ✅ | ✅ | **gebaut** |
| Segment-Speed (Tempo-Wertung) | ✅ | ✅ (0026) | ✅ | **gebaut** |
| Spiel-Stufe 1 (Solo-Claim) | ✅ | ✅ | ✅ | **gebaut (live)** |
| Spiel-Stufe 2 (Crews) | ✅ | ✅ | ✅ | **gebaut (live)** |
| Spiel-Stufe 3 (Fraktionen) | ✅ | ✅ | ✅ | **gebaut (live)** |
| game_ingest / Valhalla / Chunking | ✅ | ✅ | ✅ | **gebaut (live\*)** |
| Radar-Verkehr | ✅ (Upload-Hook) | ✅ | ✅ | **gebaut** |

\* Code-Pfad + Fallback getestet; Laufzeit hängt am laufenden Valhalla-Dienst (siehe unten).

---

## Priorität A — die zuvor „offen/unbestätigt" geführten Specs

Befund (aktualisiert): **alle vier sind gebaut** und durch Tests gedeckt. 3 davon waren bereits live
(der Client lief dem dokumentierten Status voraus, nicht dem Backend); **Segment-Speed** wurde am
2026-06-24 nachgezogen.

### Follow-Listen — **gebaut**
- **Route:** `public/index.php` → `GET /api/v1/users/by-handle/{handle}/followers` und `…/following` (OptionalBearer).
- **Schema:** keine Migration — nutzt bestehende `follows`-Beziehung (`migrations/0005_m3_social.sql`).
- **Code:** `src/Discovery/ProfileService.php::getProfileFollowList()`, `src/Controllers/Api/ProfileController.php::followers()/following()`.
- **Tests:** `tests/Integration/Discovery/ProfileFollowListTest.php` (8 Tests, alle AK abgedeckt, grün).

### Solo-/Spieler-Rangliste — **gebaut**
- **Route:** `GET /api/v1/game/leaderboard` (OptionalBearer) → `PlayerLeaderboardController::index`.
- **Schema:** keine — reine Lese-Aggregation über `game_edge_pass` (`migrations/0015_game_stage1.sql`).
- **Code:** `src/Game/PlayerLeaderboardService.php`, `src/Controllers/Api/PlayerLeaderboardController.php`.
- **Tests:** `tests/Integration/Game/PlayerLeaderboardTest.php` (grün).

### Crew-Rangliste — **gebaut**
- **Route:** `GET /api/v1/game/crews/{slug}/leaderboard` (Bearer) → `CrewController::leaderboard`.
- **Schema:** keine zusätzliche — Crew-Schema aus `migrations/0017_game_crew.sql`, Aggregation über `game_edge_pass`.
- **Code:** `src/Controllers/Api/CrewController.php`, `src/Game/Crew/CrewService.php`.
- **Tests:** `tests/Integration/Game/Crew/CrewLeaderboardTest.php` (grün).

### Segment-Speed (Tempo-Wertung) — **gebaut**
- **Route:** `GET /game/segments/{id}/leaderboard` (OptionalBearer) + `GET /game/me/segments` (Bearer) → `SegmentSpeedController`.
- **Schema:** `migrations/0026_game_segment_speed.sql` (`game_segment_effort` + Config-Keys).
- **Code:** `src/Game/SegmentSpeedService.php`, `GameRepository::insertSegmentEffort()/bestEffortsForEdge()/userSegmentBests()`, Effort-Hook in `GameIngestionService::recordEffort()`, `MatchedSegment::durationS`.
- **Tests:** `tests/Integration/Game/SegmentSpeedTest.php` (9 Tests, alle 10 AK abgedeckt, grün).

---

## Priorität B — Spiel-Stufen 1–3: laufen `game/*` live oder rendert die App nur voraus?

Befund: **Alle drei Stufen laufen serverseitig live** — Routen registriert, Schema migriert, Tests grün.
Die App rendert hier **nicht** ins Leere.

### Stufe 1 — Solo-Claim / Territorialspiel — **gebaut (live)**
- **Routes:** `GET /game/edges`, `/game/edges/{id}`, `/game/me`, `/game/config`, `POST /game/ingest/{route_id}`.
- **Schema:** `0015_game_stage1.sql` (`game_claimant`, `game_node`, `game_edge`, `game_edge_pass`, `game_config`), `0016_game_dashboard.sql`.
- **Code:** `GameIngestionService`, `GameReadService`, `EdgeRecalculator`, `GameRecomputeService`, `GameRepository`, `GameMath`.
- **Tests:** `GameIngestionTest`, `GameReadServiceTest`, `GameReadServiceOwnerNameTest`, `GameRepositoryTest`, `EdgeRecalculatorTest`, `GameRecomputeTest`, `TerritoryTakeoverTest`, `GameInvalidationTest`, `RouteGameStatusTest`, Unit: `GameMathTest`, `EdgeKeyTest` (grün).

### Stufe 2 — Crews / Gruppen — **gebaut (live)**
- **Routes:** `GET /game/crews/me`, `/game/crews/{slug}`, `POST /game/crews`, `/game/crews/join`, `/leave`, `/transfer`, `/game/crews/{slug}/leaderboard`.
- **Schema:** `0017_game_crew.sql`.
- **Code:** `src/Game/Crew/CrewService.php`, `CrewRepository.php`.
- **Tests:** `CrewServiceTest`, `CrewRepositoryTest`, `CrewLeaderboardTest`, `EdgeRecalculatorCrewTest`, `GameMeStatsCrewTest` (grün).

### Stufe 3 — Fraktionen — **gebaut (live)**
- **Routes:** `GET /game/factions/map`, `GET /game/factions`, `POST/DELETE /game/crews/{slug}/faction`.
- **Schema:** `0019_game_factions.sql` (+ Referenz-Seed `green`/`blue`, siehe `IntegrationTestCase`).
- **Code:** `src/Game/Faction/FactionService.php`, `FactionRepository.php`.
- **Tests:** `tests/Integration/Game/Faction/FactionServiceTest.php` (grün).

---

## Priorität C — game_ingest / Valhalla / Chunking (Fundament der Segment-Speed-Spec)

Befund: **gebaut und getestet.** Auf diesem Pfad setzt Segment-Speed auf — die Voraussetzungen
(Matcher-VO mit `avgSpeedKmh`, Pass-Schreibung, Recompute) sind vorhanden.

- **Code:** `GameIngestionService` (Match → Auth-Filter → Pass idempotent/tagesgedeckelt → Pionier → Live-Recompute), `EdgeMatcher` (Interface), `ValhallaEdgeMatcher`, `FakeEdgeMatcher`, `RouteChunker`, `MatchedSegment` (trägt bereits `avgSpeedKmh`, `lengthM`, `riddenAt`).
- **Schema/Config:** `0025_game_chunking.sql`; Config-Keys `game_chunk_size_m`, `game_chunk_overlap_m`, `auth_*` in `game_config` / `GameConfig::DEFAULTS`.
- **Tests:** `GameIngestionTest` (Besitz, Day-Cap, Pionier, Auth-Filter, Trusted-Source-Bypass, Chunking: single / partial-fail / all-fail, Matcher-Ausfall + Recovery), `RouteChunkerTest`, `ValhallaClientTest`, `ValhallaEdgeMatcherTest`, `GameRecomputeBboxTest` (grün).
- **Laufzeit-Hinweis (\*):** Der Code-Pfad ist live und der Engine-Ausfall ist als `MatchUnavailableException` getestet (nicht-blockierender Upload-Hook). Ob echtes Map-Matching greift, hängt am **laufenden Valhalla-Dienst** ([`docker/valhalla/`](../docker/valhalla/), Setup: [`VALHALLA_SETUP.md`](VALHALLA_SETUP.md)) — das ist eine Betriebs-/Deploy-Frage, kein Code-Gap.

---

## Nebenbefund — Radar-Verkehr — **gebaut**
- **Schema:** `0018_radar_traffic.sql` (`game_edge_traffic`), Config-Keys `traffic_*`, `radar_min_closing_kmh`.
- **Code:** `GameIngestionService::recordTraffic()`, `RadarTrafficParser`.
- **Tests:** `RadarTrafficIngestTest`, `RadarTrafficParserTest`, `GameMathTrafficTest` (grün).

## Nebenbefund — Block/Unblock — **gebaut**
- **Routes:** `POST/DELETE /users/by-handle/{handle}/block`, `GET /users/me/blocks`.
- **Schema:** `user_blocks` (`0005_m3_social.sql`).
- **Code:** `src/Discovery/BlockService.php`, `SocialController`.
- **Tests:** Block-Pfade in `ProfileFollowListTest` (404/Filter) + `DiscoveryServiceTest`.

---

## Methodik / Reproduktion
- Routen: Suche in [`public/index.php`](../public/index.php) (`$router->...("{$apiBase}/...")`).
- Schema: [`migrations/`](../migrations/) (Dateinamen tragen die Feature-Zuordnung).
- Tests: `vendor/bin/phpunit` (DB-gestützte Integrationstests; ohne Test-DB werden sie sauber übersprungen).
- Diese Datei nach jeder relevanten Änderung neu belegen (Status-Spalte in [`ROADMAP.md`](ROADMAP.md) entsprechend nachziehen).
