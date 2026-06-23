-- Gechunktes Map-Matching langer Fahrten (GAME_INGEST_CHUNKING_BACKEND).
-- 50-km-Stücke entlang der kumulierten Distanz, ~500 m Naht-Überlappung.
-- Defaults greifen ohnehin im Code (GameConfig::DEFAULTS); hier nur als
-- sichtbarer, server-justierbarer Eintrag in game_config.

INSERT INTO game_config (config_key, config_value) VALUES
  ('game_chunk_size_m',    '50000'),
  ('game_chunk_overlap_m', '500')
ON DUPLICATE KEY UPDATE config_key = config_key;
