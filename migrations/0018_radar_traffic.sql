-- Verkehrs-Tracking (Radar). Siehe RADAR_TRAFFIC_BACKEND.md §B.
-- Additiv & neutral: bestehende Routen/Kanten behalten Faktor 1.0.
--
-- Quelle der Wahrheit für den Verkehrs-Faktor ist game_edge_traffic
-- (pro Route+Kante eine Beobachtung); game_edge.traffic_* sind die
-- materialisierten Aggregate (wie value_cached) und werden vom
-- EdgeRecalculator neu berechnet — so reproduziert ein Voll-Recompute
-- traffic_factor_cached identisch.

ALTER TABLE routes
  ADD COLUMN traffic_passes_per_km DOUBLE NULL;

ALTER TABLE game_edge
  ADD COLUMN traffic_pass_count    INT    NOT NULL DEFAULT 0,
  ADD COLUMN traffic_observations  INT    NOT NULL DEFAULT 0,
  ADD COLUMN traffic_factor_cached DOUBLE NOT NULL DEFAULT 1.0;

-- Pro (Kante, Fahrt): wie viele Vorbeifahrten map-gematcht wurden.
-- Existenz einer Zeile = "diese Fahrt hat die Kante mit Radar befahren"
-- → traffic_observations = COUNT(*) je Kante (auch bei 0 Pässen = leise).
CREATE TABLE IF NOT EXISTS game_edge_traffic (
  edge_id    BIGINT UNSIGNED NOT NULL,
  route_id   BIGINT UNSIGNED NOT NULL,
  pass_count INT             NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (edge_id, route_id),
  KEY idx_edge_traffic_edge (edge_id),
  CONSTRAINT fk_edge_traffic_edge FOREIGN KEY (edge_id) REFERENCES game_edge(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (config_key, config_value) VALUES
  ('traffic_t0',               '5.0'),  -- neutrale Vorbeifahrten/km
  ('traffic_k',                '0.5'),  -- Empfindlichkeit
  ('traffic_f_min',            '0.7'),  -- Malus-Boden (viel Verkehr)
  ('traffic_f_max',            '1.3'),  -- Bonus-Deckel (wenig Verkehr)
  ('traffic_n_prior',          '3'),    -- Shrinkage Richtung neutral
  ('traffic_match_max_dist_m', '30'),   -- max. Punkt-zu-Kante-Distanz beim Matching
  ('radar_min_closing_kmh',    '15')    -- nur Doku; Filter passiert iOS-seitig
ON DUPLICATE KEY UPDATE config_key = config_key;
