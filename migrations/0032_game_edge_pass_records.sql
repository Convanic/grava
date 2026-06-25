-- Segment-Bestzeiten auf game_edge_pass (GAME_SEGMENT_SPEED_BACKEND.md 2026-06-24).
-- Additiv: Besitz/Präsenz unverändert; Rekord-Spalten NULL-bar für Alt-Pässe.

ALTER TABLE game_edge_pass
  ADD COLUMN duration_ms   INT UNSIGNED NULL AFTER rush_id,
  ADD COLUMN avg_speed_kmh DECIMAL(5,2) NULL AFTER duration_ms,
  ADD COLUMN bike_class    VARCHAR(16) NULL AFTER avg_speed_kmh;

CREATE INDEX idx_pass_edge_speed ON game_edge_pass (edge_id, bike_class, avg_speed_kmh);

INSERT INTO game_config (config_key, config_value) VALUES
  ('record_max_speed_kmh',     '70'),
  ('record_min_edge_length_m', '50'),
  ('record_max_hacc_m',        '20'),
  ('record_require_recording', '1'),
  ('edge_records_list_limit',  '10')
ON DUPLICATE KEY UPDATE config_key = config_key;
