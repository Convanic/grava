-- M6: Heatmap-Streckenlinien mit Map-Matching (siehe docs/PLAN_HEATMAP_MAPMATCH.md).
--
-- Vorberechnete Aggregation gematchter Wegstücke: Der HeatmapLinesService
-- snappt alle public Routen via Valhalla `trace_attributes` aufs OSM-Netz und
-- aggregiert pro (ungerichteter) Kante:
--   * route_count = Anzahl Routen über dieses Stück  -> Häufigkeit/Linienstärke
--   * avg_score   = Ø Crowd-Surface-Score (0..5)     -> Farbe
--
-- Wie heatmap_cells (M4f) ist das ein voller Rebuild (DELETE + INSERT) und
-- damit idempotent. Die API liest nur aus dieser Tabelle (kein Valhalla im
-- Request-Pfad). Cutover: Tabelle ist die einzige Laufzeit-Abhängigkeit
-- (Plan §12).
--
-- edge_key: stabiler, RICHTUNGS-UNABHÄNGIGER Schlüssel pro physischem Wegstück
--   = way_id + sortierte, gerundete Endpunkte. So fallen Hin-/Rückrichtung und
--   wiederholte Befahrungen derselben Kante zusammen.

CREATE TABLE heatmap_edges (
    edge_key    VARCHAR(64)   NOT NULL,         -- "<way_id>:<lat1,lon1|lat2,lon2>" (Endpunkte sortiert)
    way_id      BIGINT UNSIGNED NULL,           -- OSM way_id (Debug / spätere Gruppierung)

    geom_json   JSON          NOT NULL,         -- [[lon,lat], …] gesnappte Teil-Polyline der Kante

    -- BBox der Kante für schnelle Viewport-Filter (B-Tree, kein Spatial nötig).
    min_lat     DECIMAL(9,6)  NOT NULL,
    min_lon     DECIMAL(9,6)  NOT NULL,
    max_lat     DECIMAL(9,6)  NOT NULL,
    max_lon     DECIMAL(9,6)  NOT NULL,

    length_m    INT UNSIGNED  NOT NULL,         -- Kantenlänge in Metern

    route_count INT UNSIGNED  NOT NULL,         -- Häufigkeit (Anzahl Routen)

    -- Laufendes Mittel der Crowd-Surface-Scores: avg = score_sum / score_n.
    -- score_n kann < route_count sein (nicht jede Route hat surfaceScores).
    score_sum   DECIMAL(10,2) NULL,
    score_n     INT UNSIGNED  NOT NULL DEFAULT 0,
    avg_score   DECIMAL(4,2)  NULL,             -- denormalisiert für die Ausgabe

    -- Fallback aus Valhalla/OSM (z. B. "paved_smooth", "gravel", "unpaved"),
    -- falls eine Kante gar keine Crowd-Scores hat. Optional.
    osm_surface VARCHAR(32)   NULL,

    updated_at  DATETIME      NOT NULL,

    PRIMARY KEY (edge_key),
    KEY idx_heatmap_edges_bbox (min_lat, max_lat, min_lon, max_lon),
    KEY idx_heatmap_edges_way (way_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
