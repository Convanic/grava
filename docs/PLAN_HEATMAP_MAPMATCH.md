# Plan: Heatmap → Streckenlinien mit Map-Matching (Valhalla)

**Status:** Entwurf zur Freigabe · **Entscheidungen:** Map-Matching (Valhalla),
Region DACH, Valhalla lokal via Docker, Aggregation = Ø Surface-Score +
Routen-Häufigkeit, Integration als umschaltbarer Layer (B) + BBox-Laden (C).

---

## 1. Ziel

Die `/heatmap`-Seite soll statt der groben Centroid-Blob-Heatmap die
**tatsächlichen Streckenverläufe auf den Straßen** zeigen. Wo mehrere public
Routen denselben Weg nutzen, werden ihre Daten zusammengefasst:

- **Farbe** des Wegstücks = **Ø Surface-Score** aller Routen, die da langführen.
- **Linienstärke/Deckkraft** = **Häufigkeit** (Anzahl Routen über dieses Stück).

## 2. Warum Map-Matching (und warum Valhalla)

Aufgezeichnete GPS-Spuren liegen leicht neben der Straße und nie exakt
deckungsgleich übereinander. Damit „gleicher Weg" überhaupt erkannt werden kann,
müssen die Spuren auf ein gemeinsames Straßennetz **gesnappt** werden.

- **Valhalla `trace_attributes`** liefert pro gematchtem Segment die OSM
  **`way_id`** und eine stabile **`edge.id`** (gerichtete Graph-Kante) sowie über
  `matched_points[].edge_index` die Zuordnung jedes Eingabepunkts zu einer Kante.
  → Damit haben wir (a) einen stabilen Aggregations-Schlüssel und (b) eine Brücke
  von unseren `<ge:surfaceScore>`-Punkten zu den Kanten.
- OSRM `/match` und gehostete Dienste liefern diese Edge-/Way-Zuordnung nicht
  sauber → für die Aggregation ungeeignet.

> **Konsequenz:** Neue Infrastruktur (Valhalla-Dienst + OSM-Routing-Tiles DACH).
> Das Matching läuft **offline im Precompute**, nie im Request-Pfad.

## 3. Architektur (Überblick)

```
                ┌─────────────────────────────────────────┐
   cron/CLI ───▶│ HeatmapLinesService.rebuild()            │
                │  je public Route:                         │
                │   1. Payload laden (RouteService)         │
                │   2. Punkte + surfaceScore extrahieren    │
                │   3. POST Valhalla /trace_attributes      │──▶ Valhalla (Docker)
                │   4. Edges + matched_points auswerten     │◀──  DACH-Tiles
                │   5. pro edge_key aggregieren (count,Ø)   │
                │   6. UPSERT heatmap_edges                 │
                └─────────────────────────────────────────┘
                                  │
                       (Tabelle heatmap_edges)
                                  │
   Browser ──GET /api/v1/heatmap/lines?bbox=…──▶ HeatmapLinesController
                                  │
                       GeoJSON FeatureCollection (LineStrings)
                                  │
                       map-heatmap-lines.js  (Layer-Toggle + Rendering)
```

## 3a. Spike-Erkenntnisse (Phase 1, verifiziert)

- **Kein Multi-PBF-Build.** Mehrere getrennte Extrakte (DE+AT+CH als drei
  `tile_urls`) lassen `valhalla_build_tiles` sofort abstürzen
  (`terminate … std::exception … Aborted`) → 0 Tiles auf Level 0/1, jeder Match
  `No suitable edges`. Geofabrik hat kein fertiges `dach-latest.osm.pbf`. Lösung:
  EIN Extrakt (z. B. `germany-latest`, deckt aktuell alle public Routen ab) oder
  vorab `osmium merge` zu einem DACH-PBF. (Build-Defekt unabhängig vom RAM.)
- **`build_admins=False` ist Pflicht.** Mit aktivierter Admin-DB bricht
  `valhalla_build_tiles` bei DACH/Germany deterministisch ab (`vector::
  _M_range_check … size 0`, nur ~16 Tiles, RAM-unabhängig — auch mit 31 GB).
  Ohne Admin-Build: 0 Fehler, vollständige Tile-Hierarchie. Für Map-Matching
  (way_id/Geometrie/Surface) sind Admins nicht nötig.
- **`trace_attributes` ohne `filters` aufrufen.** Mit `filters.action=include`
  wird `matched_points` herausgefiltert (dafür bräuchte es die `matched.*`-
  Schlüssel). Die filterlose Antwort liefert direkt alles Nötige:
  - `edges[]`: `id` (stabile Graph-Edge-ID), `way_id`, `begin_shape_index`,
    `end_shape_index`, `length`, `source_percent_along` **und** `surface`
    (OSM-Klassifikation, z. B. `paved_smooth`/`unpaved`) + `unpaved`-Flag.
  - `shape`: encoded Polyline (precision 1e6) des gesnappten Pfads.
  - `matched_points[]`: pro Eingabepunkt `edge_index`, `type`
    (`matched`/`interpolated`/`unmatched`), `distance_along_edge`, lat/lon.
- **Bonus für die Aggregation:** Valhallas `edge.surface` kann als Fallback/
  Ergänzung zu den Crowd-`<ge:surfaceScore>` dienen (z. B. wenn eine Route keine
  eigenen Scores hat → OSM-Surface auf einen 0..5-Score mappen).
- **Score→Edge-Zuordnung:** über `matched_points[i].edge_index` lässt sich jeder
  Eingabepunkt (mit seinem surfaceScore) einer Kante zuordnen → Ø-Score je Kante.
- Beispiel-Response liegt als Fixture unter
  `tests/fixtures/valhalla_trace_attributes.json` (für den `ValhallaClient`-Test).

## 4. Infrastruktur: Valhalla in Docker (DACH)

Neues Verzeichnis `docker/valhalla/` (nur Dev/Precompute, **nicht** Teil des
PHP-Deployments):

- `docker-compose.yml` mit Image `ghcr.io/gis-ops/docker-valhalla/valhalla:latest`
  (baut Tiles automatisch aus bereitgestellten PBFs, Port `8002`).
- `custom_files/` enthält die Geofabrik-Extrakte:
  `germany-latest.osm.pbf`, `austria-latest.osm.pbf`, `switzerland-latest.osm.pbf`
  (das Image akzeptiert mehrere PBFs und merged sie beim Tile-Build).
- Einmaliger Tile-Build DACH: ~15–40 min, ~3–6 GB Platte. Danach Start in Sekunden
  (Tiles werden gecacht).
- Healthcheck: `GET http://localhost:8002/status`.

`.env`-Ergänzungen (von `Config` gelesen, mit Defaults):

```
VALHALLA_URL=http://localhost:8002
VALHALLA_COSTING=bicycle          # gravel-nah; alternativ "auto"
HEATMAP_LINES_MIN_ROUTES=1        # Schwelle: Kanten mit weniger Routen verwerfen
HEATMAP_LINES_RESAMPLE_M=20       # Eingabe-Downsampling vor dem Matching
```

## 5. Datenmodell — neue Migration `0012_m6_heatmap_edges.sql`

```sql
CREATE TABLE heatmap_edges (
    edge_key    VARCHAR(40)  NOT NULL,   -- stabiler Schlüssel (Valhalla edge.id, normiert)
    way_id      BIGINT UNSIGNED NULL,    -- OSM way_id (Debug/zukünftige Gruppierung)
    geom_json   JSON         NOT NULL,   -- [[lon,lat], …] Teil-Polyline der Kante
    min_lat     DECIMAL(9,6) NOT NULL,   -- BBox der Kante für Viewport-Filter
    min_lon     DECIMAL(9,6) NOT NULL,
    max_lat     DECIMAL(9,6) NOT NULL,
    max_lon     DECIMAL(9,6) NOT NULL,
    length_m    INT UNSIGNED NOT NULL,
    route_count INT UNSIGNED NOT NULL,   -- Häufigkeit
    score_sum   DECIMAL(10,2) NULL,      -- Summe Ø-Scores (für laufendes Mittel)
    score_n     INT UNSIGNED NOT NULL DEFAULT 0,
    avg_score   DECIMAL(4,2) NULL,       -- denormalisiert = score_sum/score_n
    updated_at  DATETIME     NOT NULL,
    PRIMARY KEY (edge_key),
    KEY idx_heatmap_edges_bbox (min_lat, max_lat, min_lon, max_lon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- **`edge_key`**: Valhalla-`edge.id` ist innerhalb eines fixen Tile-Stands stabil.
  Zur Robustheit gegen Richtung normieren wir auf die ungerichtete Kante
  (kleinere von Vorwärts-/Rückwärts-ID bzw. `way_id`+gerundete Endpunkte als
  Fallback). Voller Rebuild (TRUNCATE+INSERT) hält das konsistent — exakt das
  Muster des heutigen `HeatmapService::rebuild()`.

## 6. Backend-Komponenten

### 6.1 `src/Heatmap/ValhallaClient.php` (neu)
- `matchTrace(array $points): array` — POST `trace_attributes` mit
  `shape_match=map_snap`, `costing=$VALHALLA_COSTING`, `filters.attributes` =
  `edge.id, edge.way_id, edge.length, edge.begin_shape_index,
  edge.end_shape_index, shape, matched_points`.
- Decodiert Valhallas `shape` (encoded polyline, precision 1e6).
- Defensive: Timeouts, Nicht-200 → `null` (Route wird übersprungen, Rebuild
  läuft weiter). Kein Crash bei kaputtem Payload.
- Chunking/Resampling: lange Tracks vor dem Matching auf
  `HEATMAP_LINES_RESAMPLE_M` ausdünnen; bei Überschreiten des Valhalla-Punktelimits
  in Segmente splitten.

### 6.2 `src/Heatmap/HeatmapLinesService.php` (neu)
- `rebuild(): array` — iteriert public, nicht-gelöschte Routen
  (`visibility='public' AND deleted_at IS NULL`), lädt je Head-Version den Payload
  via `RouteService::loadPayloadByPublicId()`, holt Punkte+Scores (Reuse der
  `<ge:surfaceScore>`-Leselogik aus `SurfaceTrack`/`GeometryParser`).
  - Pro Route: `matched_points[i].edge_index` → Score je Eingabepunkt der Kante
    zuordnen → **Ø-Score der Route pro Kante**.
  - Aggregation pro `edge_key`: `route_count += 1`, `score_sum += routeEdgeAvg`,
    `score_n += 1` (nur wenn die Route für diese Kante Scores hatte), Geometrie/BBox
    beim ersten Mal setzen.
  - Abschluss: `avg_score = score_sum/score_n`; Kanten mit
    `route_count < HEATMAP_LINES_MIN_ROUTES` verwerfen.
  - In einer Transaktion: `DELETE FROM heatmap_edges` + Batch-Insert.
- `query(?bbox, limit): array` — GeoJSON-FeatureCollection von LineStrings,
  `properties = { count, avg_score, length_m }`, plus `meta.max_count` zum
  Normieren der Linienstärke im Frontend. BBox-Overlap-Filter über den Index.

### 6.3 CLI — `src/Cli/Commands.php`
- Neues Kommando `cron:heatmap-lines` (analog `cron:heatmap`), das
  `HeatmapLinesService::rebuild()` aufruft und die Kennzahlen ausgibt.
- Optional in `cron:cleanup` einhängen — aber **separat lassen**, weil das
  Matching deutlich länger läuft und Valhalla-Verfügbarkeit voraussetzt.

### 6.4 API — `src/Controllers/Api/HeatmapLinesController.php` (neu)
- `GET /api/v1/heatmap/lines?bbox=minLon,minLat,maxLon,maxLat` (public, kein Auth),
  BBox-Parsing 1:1 aus `HeatmapController` übernehmen (422 bei ungültig).
- Route-Registrierung in `public/index.php`; Service dort instanziieren und
  injizieren (Muster wie bei `HeatmapService`).

## 7. Frontend

- `views/web/heatmap.php`: zweiter Map-Container bzw. Layer-Quelle
  `data-lines-url="/api/v1/heatmap/lines"`; Leaflet-`L.control.layers`-Umschalter
  **Blob-Heatmap ↔ Streckenlinien** (Scope B).
- `public/assets/js/map-heatmap-lines.js` (neu):
  - Lädt GeoJSON für die **aktuelle Karten-BBox** und neu bei `moveend`/`zoomend`
    (Scope C, schont Payload bei vielen Kanten).
  - Farbe je `avg_score` über die bestehende `SCORE_COLORS`-Palette aus
    `map-route.js` (0 = grün/glatt … 5 = rot/grob — konsistent mit Detailseite).
  - `weight`/`opacity` skaliert über `count / meta.max_count`.
  - Tooltip: „n Routen · Ø Untergrund: <Label>" (Labels aus `SCORE_LABELS`).
- CSP: alles same-origin (Tiles bereits erlaubt), keine neue Direktive nötig.

## 8. Privacy

- Nur `visibility='public'`. Aggregation ist anonym (keine User-Zuordnung),
  identisch zur Begründung im bestehenden `HeatmapService`.
- `HEATMAP_LINES_MIN_ROUTES` erlaubt optional k-Anonymität (z. B. erst ab 2
  Routen anzeigen), falls gewünscht — Default 1, da public bereits public ist.

## 9. Tests

- `tests/Unit/ValhallaClientTest.php`: Polyline-Decoding + Response-Parsing gegen
  ein **gespeichertes Valhalla-Beispiel-JSON** (kein Live-Dienst im Test).
- `tests/Unit/HeatmapLinesAggregatorTest.php`: zwei sich überlappende Mini-Routen
  → geteilte Kante bekommt `count=2` und korrekt gemittelten `avg_score`;
  nicht-geteilte Kanten `count=1`.
- `tests/Integration/HeatmapLinesServiceTest.php`: `rebuild()` + `query(bbox)`
  gegen die Test-DB (Valhalla-Call gemockt/injiziert).
- BBox-422-Pfad im Controller analog zu `HeatmapServiceTest`.

## 10. Umsetzung in Phasen

1. **Infra-Spike:** `docker/valhalla` aufsetzen, DACH-Tiles bauen, eine Beispiel-
   Route gegen `trace_attributes` matchen, Response-Shape festhalten (Fixture).
2. **Migration** `0012_m6_heatmap_edges.sql` + `migrate`.
3. **ValhallaClient** (+ Unit-Test mit Fixture).
4. **HeatmapLinesService.rebuild()/query()** (+ Unit/Integration-Tests).
5. **CLI** `cron:heatmap-lines`, erster echter Rebuild über die public Routen.
6. **API-Controller** + Route + DI-Wiring.
7. **Frontend** Layer-Toggle + BBox-Rendering, Sichtprüfung im Browser.
8. **Doku** (`docs/API.md`, `openapi.yaml`) um den neuen Endpunkt ergänzen.

## 11. Risiken / offene Punkte

- **Tile-Build-Größe/Zeit** für DACH (einmalig). Lösung: gis-ops-Image, gecachte
  Tiles, klar dokumentiert.
- **`edge.id`-Stabilität** nur innerhalb eines Tile-Stands. Da wir per Rebuild
  voll neu aggregieren, unkritisch; bei Tile-Update einfach neu rebuilden.
- **Costing-Profil**: `bicycle` matcht Gravel meist besser als `auto`, kann aber
  bei reinen Forstwegen daneben liegen. Per `.env` umstellbar; ggf. später
  `costing=pedestrian`-Fallback für unmatchbare Segmente.
- **Rebuild-Laufzeit** bei vielen Routen (Minuten). Akzeptabel, da offline. Später
  inkrementell (nur neue/aktualisierte Routen) optimierbar.
- **Produktion**: siehe §12 — Cutover-Plan ist vorbereitet/dokumentiert.

---

## 12. Produktion / Cutover-Plan

Ziel: Dev-Stand (lokaler Valhalla + Precompute) später risikoarm live bringen.
Die **`heatmap_edges`-Tabelle ist die einzige Laufzeit-Abhängigkeit** des
Web-/API-Layers — Valhalla wird nur zum *Befüllen* gebraucht, **nicht** im
Request-Pfad. Das eröffnet zwei Cutover-Modelle:

### Modell A — „Compute lokal, nur Daten live" (empfohlen für den Start)
Valhalla läuft **nicht** in Prod. Der Rebuild passiert lokal (oder auf einem
Build-Runner); nur das **Ergebnis** (`heatmap_edges`) wird nach Prod übertragen.

- **Pro:** keine Valhalla-Infra in Prod, kein zusätzlicher RAM/Plattenbedarf am
  Server, kleinste Angriffsfläche. Geht sofort.
- **Contra:** Aktualität hängt am manuellen/zeitgesteuerten Export.
- **Sync-Mechanik:** umgesetzt in `scripts/sync_heatmap_edges.sh` (getestet):
  1. Lokal: `scripts/sync_heatmap_edges.sh export [datei.sql]` — ruft
     `cron:heatmap-lines` (gegen lokale Valhalla) auf und dumpt `heatmap_edges`
     (nur Daten) nach `datei.sql` (Default `build/heatmap_edges.sql`).
  2. `datei.sql` per scp/rsync auf den Prod-Server kopieren.
  3. Prod: `scripts/sync_heatmap_edges.sh import datei.sql` — lädt in die
     Shadow-Tabelle `heatmap_edges_new`, prüft auf >0 Zeilen und macht dann den
     atomaren `RENAME`-Swap (kein Lese-Ausfall); die alte Tabelle wird gedroppt.
  - DB-Zugang kommt aus der `.env`; `mysql`/`mysqldump` via `MYSQL_BIN`/
    `MYSQLDUMP_BIN` überschreibbar (z. B. MAMP-Pfade).

### Modell B — „Valhalla in Prod" (wenn Aktualität automatisiert sein soll)
Valhalla als eigener Dienst neben der App; `cron:heatmap-lines` läuft serverseitig.

- **Pro:** voll automatisierter, regelmäßiger Rebuild (z. B. nächtlich).
- **Contra:** Betrieb eines weiteren Dienstes (Tiles ~3–6 GB DACH, RAM für die
  Graph-Queries), Update-Routine für OSM-Tiles.
- **Setup:** dasselbe `docker/valhalla` wie in Dev, aber als dauerhafter Dienst
  hinter dem internen Netz (Valhalla **nie** öffentlich exponieren — nur die App
  spricht mit ihm). `.env` in Prod: `VALHALLA_URL=http://valhalla:8002`.

### Konkrete Cutover-Checkliste (gilt für beide Modelle)
1. **Migration:** `0012_m6_heatmap_edges.sql` in Prod einspielen (`cli:migrate`).
2. **Daten initial befüllen:** Modell A (Dump/Restore via Shadow-Table) **oder**
   Modell B (`cron:heatmap-lines` serverseitig einmal laufen lassen).
3. **API smoke-test:** `GET /api/v1/heatmap/lines?bbox=…` liefert Features.
   Bei leerer Tabelle fällt das Frontend sauber auf „keine Daten" zurück
   (gleiches Verhalten wie heutige Heatmap).
4. **Feature-Flag (optional):** Layer-Toggle erst nach erfolgreichem Smoke-Test
   sichtbar schalten — z. B. `.env`-Flag `HEATMAP_LINES_ENABLED`, das den Toggle
   in `heatmap.php` ein-/ausblendet. So ist der Web-Code deploybar, bevor Daten da
   sind.
5. **Cron:** in Modell B Cron-Eintrag `cron:heatmap-lines` (getrennt von
   `cron:cleanup`, da längere Laufzeit + Valhalla-Abhängigkeit).
6. **Rollback:** Toggle aus (Flag) bzw. `RENAME TABLE` zurück auf
   `heatmap_edges_old`. Der Rest der App ist unberührt (additive Änderung).

### Was wir jetzt schon „cutover-fähig" bauen (damit später nichts nachgezogen wird)
- **Reine Read-Abhängigkeit** der App von `heatmap_edges` (Valhalla strikt nur im
  Precompute) — ist im Design oben bereits so.
- **`.env`-getriebene Valhalla-URL** → Dev/Prod ohne Codeänderung umschaltbar.
- **`HEATMAP_LINES_ENABLED`-Flag** von Anfang an einbauen (Default off in Prod).
- **Shadow-Table-tauglicher Rebuild** (TRUNCATE+Insert in *einer* Tabelle, plus
  optionaler `--into`-Schalter im CLI für `heatmap_edges_new`), damit Modell A
  ohne Umbau möglich ist.
- **Doku:** dieser §12 bleibt die Referenz; beim Go-Live nur abarbeiten.
```
