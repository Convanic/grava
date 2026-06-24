-- Segment-Speed (Tempo-Wertung). Siehe backend/GAME_SEGMENT_SPEED_BACKEND.md.
-- KOM/QOM-artige Bestzeit-Wertung pro game_edge. Additiv: keine bestehende
-- Tabelle wird verändert. Efforts sind ein paralleler, NICHT tagesgedeckelter
-- Strom (im Gegensatz zu game_edge_pass) — jede authentische, getimte
-- Befahrung zählt; Best-of entsteht beim Lesen.

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
  KEY idx_effort_edge_dur  (edge_id, duration_s),          -- Leaderboard je Kante
  KEY idx_effort_user_edge (user_id, edge_id, duration_s), -- Bestzeit je Fahrer
  KEY idx_effort_ridden    (ridden_at),                    -- Zeitfenster-Filter
  CONSTRAINT fk_effort_edge     FOREIGN KEY (edge_id)     REFERENCES game_edge(id)     ON DELETE CASCADE,
  CONSTRAINT fk_effort_claimant FOREIGN KEY (claimant_id) REFERENCES game_claimant(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (config_key, config_value) VALUES
  ('segment_min_length_m',      '200'),
  ('segment_min_speed_kmh',     '5'),
  ('segment_max_speed_kmh',     '80'),
  ('segment_leaderboard_top_n', '100')
ON DUPLICATE KEY UPDATE config_key = config_key;
