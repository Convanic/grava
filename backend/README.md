# Backend-Handoff — Übersicht & Anleitung

**Audience:** Cursor / Backend-Agent + Mensch. Dieser Ordner bündelt die Backend-Build-Orders
(`*_BACKEND.md`), Betriebs-Setups (`*_SETUP.md`), das zentrale Tracking ([`ROADMAP.md`](ROADMAP.md))
und den Audit-Beleg ([`BACKEND_STATUS.md`](BACKEND_STATUS.md)). Das eigentliche Backend ist die
PHP-Anwendung in [`../src`](../src) mit Routen in [`../public/index.php`](../public/index.php),
Schema in [`../migrations`](../migrations) und Tests in [`../tests`](../tests).

## §1 Zweck
Specs hier sind **Arbeitsaufträge an Cursor**: pro Feature ein in sich geschlossenes Dokument mit
Datenmodell/Migration, API-Spec (inkl. JSON), Datenschutz und Akzeptanzkriterien. Cursor arbeitet sie ab
und belegt den Stand (siehe §5).

## §2 Konventionen (Build-Order-Format)
Jede `*_BACKEND.md`:
- Kopf **`**Audience:** Cursor / Backend-Agent`** + 1–2 Sätze Kontext (welche Client-Seite wartet).
- Abschnitte: Datenmodell/Migration → Endpunkte (mit JSON) → Config/Detail → Datenschutz → Akzeptanzkriterien.
- Migrationen additiv und nummeriert (`migrations/NNNN_*.sql`); Config über `game_config` + `GameConfig::DEFAULTS`.
- Tests als Beleg (`tests/Integration/...`), DB-gestützt (ohne Test-DB sauber übersprungen).

## §3 Status-Übersicht
Verbindlicher, code-belegter Stand: [`BACKEND_STATUS.md`](BACKEND_STATUS.md) (zuletzt 2026-06-24,
gesamte Suite grün). Kurzfassung:

| Feature | Einstufung | Beleg |
|---|---|---|
| Follow-Listen | gebaut | [`FOLLOW_LISTS_BACKEND.md`](FOLLOW_LISTS_BACKEND.md), `ProfileFollowListTest` |
| Solo-/Spieler-Rangliste | gebaut | [`PLAYER_LEADERBOARD_BACKEND.md`](PLAYER_LEADERBOARD_BACKEND.md), `PlayerLeaderboardTest` |
| Crew-Rangliste | gebaut | [`CREW_LEADERBOARD_BACKEND.md`](CREW_LEADERBOARD_BACKEND.md), `CrewLeaderboardTest` |
| Segment-Speed (Tempo-Wertung) | gebaut | [`GAME_SEGMENT_SPEED_BACKEND.md`](GAME_SEGMENT_SPEED_BACKEND.md), `SegmentSpeedTest`, Migration `0026` |
| Block/Unblock | gebaut | `BlockService`, `user_blocks` (0005) |
| Spiel-Stufen 1–3 (Solo/Crews/Fraktionen) | gebaut (live) | `BACKEND_STATUS.md` (Prio B) |
| game_ingest / Valhalla / Chunking | gebaut (live\*) | `BACKEND_STATUS.md` (Prio C) |
| Radar-Verkehr | gebaut | `RadarTrafficIngestTest` |

\* Code-Pfad + Fallback getestet; Laufzeit hängt am laufenden Valhalla-Dienst ([`VALHALLA_SETUP.md`](VALHALLA_SETUP.md)).

> Früher standen diese Zeilen auf „unbestätigt", weil der iOS-Client teils dem Backend vorauslief. Der Audit
> (§5) hat sie auf Code-Belege gehoben. Offene Backend-Specs: derzeit **keine** (siehe [`ROADMAP.md`](ROADMAP.md)).

## §4 Dokument-Index
- **Tracking:** [`ROADMAP.md`](ROADMAP.md) (Backlog + Erledigt), [`BACKEND_STATUS.md`](BACKEND_STATUS.md) (Audit-Beleg).
- **Build-Orders:** [`FOLLOW_LISTS_BACKEND.md`](FOLLOW_LISTS_BACKEND.md),
  [`PLAYER_LEADERBOARD_BACKEND.md`](PLAYER_LEADERBOARD_BACKEND.md),
  [`CREW_LEADERBOARD_BACKEND.md`](CREW_LEADERBOARD_BACKEND.md),
  [`GAME_SEGMENT_SPEED_BACKEND.md`](GAME_SEGMENT_SPEED_BACKEND.md).
- **Setup/Betrieb:** [`VALHALLA_SETUP.md`](VALHALLA_SETUP.md), [`GAME_DASHBOARD_SETUP.md`](GAME_DASHBOARD_SETUP.md).
- **Testberichte:** [`GAME_STAGE1_TESTREPORT.md`](GAME_STAGE1_TESTREPORT.md), [`GAME_STAGE2_TESTREPORT.md`](GAME_STAGE2_TESTREPORT.md).
- **API-Referenz:** [`../docs/API.md`](../docs/API.md) (inkl. aller `game/*`-Endpunkte).

## §5 Audit-Auftrag (für Cursor)
**Erste Aufgabe in einer Bestandsaufnahme:** [`BACKEND_STATUS.md`](BACKEND_STATUS.md) erzeugen bzw.
aktualisieren. Pro Spec (und pro Spiel-Stufe) prüfen und mit konkretem Beleg einstufen:

1. **Route registriert?** — Suche in [`../public/index.php`](../public/index.php) nach dem Endpunkt-Pfad.
2. **Schema/Migration vorhanden?** — passende Datei in [`../migrations`](../migrations) (bzw. „n/a" bei reiner Lese-Aggregation).
3. **Tests grün?** — `vendor/bin/phpunit` (oder gezielt die Feature-Testdatei).

→ Einstufung **gebaut / teilweise / offen** mit Beleg im Format `Datei : Route/Migration/Test`.

**Priorität beim Audit:**
- die als „offen/unbestätigt" geführten Specs (sind die Endpunkte inzwischen da?),
- die Spiel-Stufen 1–3 (läuft `game/*` live oder rendert nur der Client voraus?),
- der `game_ingest`/Valhalla/Chunking-Pfad (Fundament u. a. der Segment-Speed-Spec).

**Regel:** Nichts als „gebaut" markieren, nur weil die App es rendert — der Client kann dem Backend
vorauslaufen. Ausschließlich Code-Belege (Route/Migration/Test) zählen. Nach jeder relevanten Änderung
`BACKEND_STATUS.md` und die Status-Spalte in `ROADMAP.md` nachziehen.
