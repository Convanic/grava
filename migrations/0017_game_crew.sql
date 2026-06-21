-- Stufe 2 (Crews): neutrale Gruppen. Siehe specs/2026-06-20-game-stage2-crews-design.md.
-- Eine Crew ist ein game_claimant(type='group', user_id=NULL). Besitz wandert über den
-- "effektiven Claimant" (user_id -> Crew-Group-Claimant, sonst Rider) — kein Pass-Backfill.

CREATE TABLE IF NOT EXISTS game_crew (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claimant_id   BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(40)  NOT NULL,
  slug          VARCHAR(40)  NOT NULL,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  join_code     CHAR(8)      NOT NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_crew_claimant FOREIGN KEY (claimant_id) REFERENCES game_claimant(id) ON DELETE CASCADE,
  UNIQUE KEY uq_crew_slug (slug),
  UNIQUE KEY uq_crew_joincode (join_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_crew_member (
  user_id    BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  crew_id    BIGINT UNSIGNED NOT NULL,
  role       ENUM('captain','member') NOT NULL DEFAULT 'member',
  joined_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_member_crew FOREIGN KEY (crew_id) REFERENCES game_crew(id) ON DELETE CASCADE,
  KEY idx_member_crew (crew_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO game_config (config_key, config_value) VALUES
  ('group_ride_bonus', '1.5'),
  ('group_ride_min_members', '3'),
  ('crew_max_members', '0')
ON DUPLICATE KEY UPDATE config_key = config_key;
