-- M4f: Crowd-Heatmap (siehe docs/MILESTONE_4.md §3 D-F1..3).
--
-- Vorberechnete Grid-Aggregation: der HeatmapAggregator bucketet die
-- Centroids aller public Routen in ein gerundetes Lat/Lon-Grid und
-- schreibt pro Zelle ein Gewicht (Anzahl Routen). Die API liest nur
-- noch aus dieser Tabelle (kein Scan über alle Track-Punkte).
--
-- cell_key = "<blat>:<blon>" (gerundete Bucket-Koordinaten als String)
-- ist der PK und macht den Rebuild idempotent. lat/lon sind die
-- Bucket-Mittel (für GeoJSON-Ausgabe + bbox-Filter über idx_latlon).
--
-- M4-MVP: Centroid-Dichte. Volle Track-Linien-Heatmap ist M5.

CREATE TABLE heatmap_cells (
    cell_key   VARCHAR(32)  NOT NULL,
    lat        DECIMAL(9,6) NOT NULL,
    lon        DECIMAL(9,6) NOT NULL,
    weight     INT UNSIGNED NOT NULL,
    updated_at DATETIME     NOT NULL,
    PRIMARY KEY (cell_key),
    KEY idx_heatmap_latlon (lat, lon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
