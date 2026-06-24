-- Rush / Group-Ride-Übernahme. Siehe backend/GAME_RUSH_BACKEND.md.
-- Additiv: ein zeitlich befristeter Präsenz-Multiplikator auf server-seitig
-- auto-getaggte Pässe der Crew-Mitglieder. Ersetzt (Default) den Gruppenfahrt-
-- Bonus (§16.2) auf gerushten Kanten; Besitz/Wert/Frische bleiben unverändert
-- (Orthogonalität §9.7). Kein Sofort-Flip, kein Track-Merge (Konzept §19.2/§19.7).

CREATE TABLE IF NOT EXISTS game_rush (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  crew_id    BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,            -- muss Captain der Crew sein (§5.1)
  start_at   DATETIME(3)     NOT NULL,
  end_at     DATETIME(3)     NOT NULL,            -- = start_at + window_hours (bei INSERT berechnet)
  multiplier DECIMAL(4,2)    NOT NULL,            -- Snapshot rush_multiplier zum Anlegezeitpunkt
  meetup_lat DECIMAL(9,6)    NULL,                -- nur Anzeige, KEIN Geofence
  meetup_lon DECIMAL(9,6)    NULL,
  status     ENUM('planned','active','completed','expired','cancelled') NOT NULL DEFAULT 'planned',
  created_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_rush_crew_window (crew_id, start_at, end_at),
  KEY idx_rush_status (status),
  CONSTRAINT fk_rush_crew FOREIGN KEY (crew_id) REFERENCES game_crew(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reine Koordination (KEIN Scoring-Input, §19.6).
CREATE TABLE IF NOT EXISTS game_rush_rsvp (
  rush_id      BIGINT UNSIGNED NOT NULL,
  user_id      BIGINT UNSIGNED NOT NULL,
  state        ENUM('yes','no','maybe') NOT NULL,
  responded_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (rush_id, user_id),
  CONSTRAINT fk_rsvp_rush FOREIGN KEY (rush_id) REFERENCES game_rush(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auto-Tag beim Ingest (§3.1). NULL-bar → Alt-Pässe + Nicht-Rush-Fahrten bleiben
-- unberührt. ON DELETE SET NULL: ein gelöschter/abgebrochener Rush macht die
-- Pässe wieder zu normalen Pässen (Multiplikator entfällt, Besitz rechnet sauber).
ALTER TABLE game_edge_pass
  ADD COLUMN rush_id BIGINT UNSIGNED NULL,
  ADD KEY idx_pass_rush (rush_id),
  ADD CONSTRAINT fk_pass_rush FOREIGN KEY (rush_id) REFERENCES game_rush(id) ON DELETE SET NULL;

-- Per-Typ-Push-Schalter (§6): rush_invite/reminder/result hängen am Key `rush`.
ALTER TABLE user_notification_pref
  ADD COLUMN `rush` TINYINT(1) NOT NULL DEFAULT 1;

-- Rush-spezifische game_config-Keys (server-justierbar, Admin-Bereich §2.4).
-- Leerer Wert = NULL-Semantik (rush_max_edges_per_rush = unbegrenzt,
-- rush_hysteresis_factor = erbt STAGE1-Hysterese).
INSERT INTO game_config (config_key, config_value) VALUES
  ('rush_enabled',                 '1'),
  ('rush_multiplier',              '2.0'),
  ('rush_stacks_with_group_bonus', '0'),
  ('rush_min_crew_size',           '3'),
  ('rush_window_hours',            '4'),
  ('rush_window_hours_max',        '12'),
  ('rush_max_edges_per_rush',      ''),
  ('rush_cooldown_days',           '7'),
  ('rush_requires_announcement',   '1'),
  ('rush_require_colocation',      '0'),
  ('rush_colocation_radius_m',     '100'),
  ('rush_hysteresis_factor',       '')
ON DUPLICATE KEY UPDATE config_value = config_value;
