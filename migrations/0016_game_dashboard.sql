-- 0016 Game Dashboard: Ingest-Log, Audit, User-Flags, Pass-Invalidierung, Heuristik-Config.

CREATE TABLE IF NOT EXISTS game_ingest_log (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  route_id        BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  status          ENUM('ok','pending','failed') NOT NULL,
  matched_edges   INT NOT NULL DEFAULT 0,
  new_passes      INT NOT NULL DEFAULT 0,
  skipped_json    JSON NULL,
  valhalla_error  VARCHAR(255) NULL,
  duration_ms     INT NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_ingest_status (status),
  KEY idx_ingest_route (route_id),
  KEY idx_ingest_created (created_at)
);

CREATE TABLE IF NOT EXISTS game_audit (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin_user_id BIGINT UNSIGNED NOT NULL,
  action        VARCHAR(40) NOT NULL,
  target        VARCHAR(80) NULL,
  detail_json   JSON NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY idx_audit_admin (admin_user_id),
  KEY idx_audit_action (action),
  KEY idx_audit_created (created_at)
);

CREATE TABLE IF NOT EXISTS game_user_flag (
  user_id     BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  banned      TINYINT(1) NOT NULL DEFAULT 0,
  reason      VARCHAR(160) NULL,
  updated_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
);

ALTER TABLE game_edge_pass
  ADD COLUMN invalidated_at DATETIME(3) NULL,
  ADD COLUMN invalidated_by BIGINT UNSIGNED NULL,
  ADD COLUMN invalid_reason VARCHAR(120) NULL;

INSERT INTO game_config (config_key, config_value) VALUES
  ('auth_max_speed_kmh', '80'),
  ('mod_max_new_edges_per_min', '30'),
  ('mod_max_passes_per_day', '200')
ON DUPLICATE KEY UPDATE config_key = config_key;
