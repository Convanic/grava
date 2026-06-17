SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id         CHAR(36)        NOT NULL,
  email             VARCHAR(254)    NOT NULL,
  email_verified_at DATETIME        NULL,
  password_hash     VARCHAR(255)    NOT NULL,
  display_name      VARCHAR(60)     NULL,
  status            ENUM('active','disabled','deleted') NOT NULL DEFAULT 'active',
  created_at        DATETIME        NOT NULL,
  updated_at        DATETIME        NOT NULL,
  deleted_at        DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_public_id (public_id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  refresh_hash  CHAR(64)        NOT NULL,
  client        ENUM('ios','web','other') NOT NULL DEFAULT 'other',
  user_agent    VARCHAR(255)    NULL,
  ip            VARBINARY(16)   NULL,
  created_at    DATETIME        NOT NULL,
  last_used_at  DATETIME        NOT NULL,
  expires_at    DATETIME        NOT NULL,
  revoked_at    DATETIME        NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_refresh (refresh_hash),
  KEY idx_sessions_user (user_id),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_tokens (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id  BIGINT UNSIGNED NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64)        NOT NULL,
  created_at  DATETIME        NOT NULL,
  expires_at  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_access_hash (token_hash),
  KEY idx_access_session (session_id),
  CONSTRAINT fk_access_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64)        NOT NULL,
  expires_at  DATETIME        NOT NULL,
  consumed_at DATETIME        NULL,
  created_at  DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_emailverify_hash (token_hash),
  KEY idx_emailverify_user (user_id),
  CONSTRAINT fk_emailverify_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64)        NOT NULL,
  expires_at  DATETIME        NOT NULL,
  consumed_at DATETIME        NULL,
  created_at  DATETIME        NOT NULL,
  request_ip  VARBINARY(16)   NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pwreset_hash (token_hash),
  KEY idx_pwreset_user (user_id),
  CONSTRAINT fk_pwreset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  action       VARCHAR(40)     NOT NULL,
  identifier   VARCHAR(254)    NOT NULL,
  window_start DATETIME        NOT NULL,
  count        INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rl (action, identifier, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- M13: Die `migrations`-Tabelle wird zentral vom Migrator
-- (App\Database\Migrator::ensureMigrationsTable) angelegt, damit es
-- nur eine Quelle für deren Schema gibt. Der frühere CREATE TABLE
-- IF NOT EXISTS migrations(...)-Block hier war redundant.
