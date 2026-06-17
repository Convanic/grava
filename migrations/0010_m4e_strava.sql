-- M4e: Strava-Integration (siehe docs/MILESTONE_4.md §3 D-E1..3).
--
-- oauth_connections: pro User max. eine Verbindung je Provider
-- (uq_oauth_user_provider). Tokens liegen AES-256-GCM-verschlüsselt
-- at-rest (VARBINARY) — nie im Klartext. provider_user_id ist die
-- Strava-Athlete-ID, eindeutig pro Provider (verhindert, dass zwei
-- App-Accounts denselben Strava-Account verbinden).
--
-- oauth_states: single-use CSRF-/Korrelations-Token für den
-- Authorization-Code-Flow. Der Cleanup-Cron räumt alte States
-- (älter als ein paar Minuten) ab; hier kein expires nötig, weil
-- handleCallback() den State sofort konsumiert (DELETE).

CREATE TABLE oauth_connections (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           BIGINT UNSIGNED NOT NULL,
    provider          ENUM('strava')  NOT NULL,
    provider_user_id  VARCHAR(64)     NOT NULL,
    access_token_enc  VARBINARY(512)  NOT NULL,
    refresh_token_enc VARBINARY(512)  NOT NULL,
    scope             VARCHAR(255)    NULL,
    expires_at        DATETIME        NULL,
    created_at        DATETIME        NOT NULL,
    updated_at        DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_user_provider (user_id, provider),
    UNIQUE KEY uq_oauth_provider_uid (provider, provider_user_id),
    CONSTRAINT fk_oauth_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oauth_states (
    state       CHAR(64)        NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    provider    ENUM('strava')  NOT NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (state),
    KEY idx_oauth_states_created (created_at),
    CONSTRAINT fk_oauth_states_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
