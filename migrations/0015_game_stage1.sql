-- Stufe 1 (Solo-Claim) Territorialspiel. Siehe GAME_STAGE1_BACKEND.md.
-- Event-sourced: game_edge_pass ist die Quelle der Wahrheit; game_edge.*_cached
-- ist materialisierte Sicht. claimant_id (nicht user_id) traegt den Besitz —
-- forward-compat fuer Stufe 2/3 (Gruppen/Fraktionen) ohne Schema-Migration.

CREATE TABLE IF NOT EXISTS game_claimant (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type          ENUM('rider','group','faction') NOT NULL,
  user_id       BIGINT UNSIGNED NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_claimant_rider (type, user_id),
  CONSTRAINT fk_claimant_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_node (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  osm_node_id   BIGINT          NOT NULL,
  lat           DOUBLE          NOT NULL,
  lon           DOUBLE          NOT NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_node_osm (osm_node_id),
  KEY idx_node_geo (lat, lon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_edge (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  way_id                 BIGINT          NOT NULL,
  node_a_id              BIGINT UNSIGNED NOT NULL,
  node_b_id              BIGINT UNSIGNED NOT NULL,
  length_m               DOUBLE          NOT NULL,
  geom_geojson           JSON            NOT NULL,
  surface_character      VARCHAR(16)     NULL,
  min_lat                DOUBLE          NOT NULL,
  min_lon                DOUBLE          NOT NULL,
  max_lat                DOUBLE          NOT NULL,
  max_lon                DOUBLE          NOT NULL,
  discovered_at          DATETIME(3) NULL,
  discoverer_claimant_id BIGINT UNSIGNED NULL,
  distinct_riders_total  INT       NOT NULL DEFAULT 0,
  owner_claimant_id      BIGINT UNSIGNED NULL,
  owner_since            DATETIME(3) NULL,
  value_cached           DOUBLE      NOT NULL DEFAULT 0,
  freshness_cached       DOUBLE      NOT NULL DEFAULT 0,
  last_pass_at           DATETIME(3) NULL,
  created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_edge_segment (way_id, node_a_id, node_b_id),
  KEY idx_edge_bbox (min_lat, max_lat, min_lon, max_lon),
  KEY idx_edge_owner (owner_claimant_id),
  CONSTRAINT fk_edge_node_a FOREIGN KEY (node_a_id) REFERENCES game_node(id),
  CONSTRAINT fk_edge_node_b FOREIGN KEY (node_b_id) REFERENCES game_node(id),
  CONSTRAINT fk_edge_owner  FOREIGN KEY (owner_claimant_id) REFERENCES game_claimant(id),
  CONSTRAINT fk_edge_discoverer FOREIGN KEY (discoverer_claimant_id) REFERENCES game_claimant(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_edge_pass (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  edge_id       BIGINT UNSIGNED NOT NULL,
  claimant_id   BIGINT UNSIGNED NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  route_id      BIGINT UNSIGNED NOT NULL,
  ridden_on     DATE        NOT NULL,
  ridden_at     DATETIME(3) NOT NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_pass_daycap (edge_id, user_id, ridden_on),
  KEY idx_pass_edge_user (edge_id, user_id),
  KEY idx_pass_claimant (claimant_id),
  KEY idx_pass_route (route_id),
  CONSTRAINT fk_pass_edge     FOREIGN KEY (edge_id)     REFERENCES game_edge(id) ON DELETE CASCADE,
  CONSTRAINT fk_pass_claimant FOREIGN KEY (claimant_id) REFERENCES game_claimant(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_config (
  config_key    VARCHAR(40)  NOT NULL,
  config_value  VARCHAR(64)  NOT NULL,
  PRIMARY KEY (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (config_key, config_value) VALUES
  ('presence_window_days', '90'),
  ('presence_decay',       'linear'),
  ('hysteresis_factor',    '1.15'),
  ('pioneer_p0',           '100'),
  ('pioneer_k',            '12'),
  ('pioneer_s',            '4'),
  ('popularity_c',         '30'),
  ('value_combine',        'max'),
  ('curation_per_hint',    '5'),
  ('curation_per_like',    '2'),
  ('auth_min_speed_kmh',   '5'),
  ('auth_max_hacc_m',      '30'),
  ('auth_require_motion',  '1'),
  ('start_buffer_m',       '0')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
