-- Stufe 3 (Fraktionen). Siehe GAME_STAGE3_BACKEND.md.
-- Genau 2 Fraktionen (grün/blau). Crews treten einer Fraktion bei; der
-- Kantenbesitz bleibt bei der Crew — Fraktionen sind nur eine Aggregations-/
-- Meta-Ebene (Meta-Karte + Standings). Kein game_claimant vom Typ 'faction'.

CREATE TABLE IF NOT EXISTS game_faction (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  key_slug   VARCHAR(16) NOT NULL,
  name       VARCHAR(40) NOT NULL,
  color_hex  CHAR(7)     NOT NULL,
  UNIQUE KEY uq_faction_key (key_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed (idempotent): grün/blau.
INSERT INTO game_faction (key_slug, name, color_hex) VALUES
  ('green', 'Grün', '#2EA043'),
  ('blue',  'Blau', '#1F6FEB')
ON DUPLICATE KEY UPDATE key_slug = key_slug;

ALTER TABLE game_crew
  ADD COLUMN faction_id        BIGINT UNSIGNED NULL,
  ADD COLUMN faction_joined_at DATETIME(3) NULL,
  ADD CONSTRAINT fk_crew_faction FOREIGN KEY (faction_id) REFERENCES game_faction(id);

INSERT INTO game_config (config_key, config_value) VALUES
  ('faction_switch_cooldown_days', '30'),
  ('faction_map_grid',             '0.05')  -- wie Heatmap (HeatmapService::GRID)
ON DUPLICATE KEY UPDATE config_key = config_key;
