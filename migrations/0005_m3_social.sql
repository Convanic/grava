-- M3 Phase 1: Social-Layer-Tabellen (follows + user_blocks) und
-- Discovery-Index auf routes.
--
-- Beide Beziehungs-Tabellen haben den PK auf (left, right), was
-- Idempotenz für die Schreib-APIs erzwingt: zweimal POST /follow ist
-- ein Follow, nicht zwei.
--
-- Self-Reference wird DB-seitig nicht verboten — MySQL CHECK-Constraints
-- mit Subqueries sind nicht durchsetzbar, also lebt diese Regel im
-- Service-Layer (FollowService::follow()).
--
-- ON DELETE CASCADE: wenn ein User-Datensatz wirklich aus users
-- gelöscht würde, räumen wir Beziehungen mit auf. Soft-Delete (status
-- = 'deleted') triggert das nicht — dafür gibt's eine explizite
-- Cleanup-Logik in AuthService::deleteAccount.

CREATE TABLE follows (
    follower_id  BIGINT UNSIGNED NOT NULL,
    followee_id  BIGINT UNSIGNED NOT NULL,
    created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (follower_id, followee_id),
    CONSTRAINT fk_follows_follower
        FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_follows_followee
        FOREIGN KEY (followee_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_follows_followee_created (followee_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_blocks (
    blocker_id  BIGINT UNSIGNED NOT NULL,
    blocked_id  BIGINT UNSIGNED NOT NULL,
    created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (blocker_id, blocked_id),
    CONSTRAINT fk_blocks_blocker
        FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_blocks_blocked
        FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE,
    KEY idx_blocks_blocked (blocked_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Discovery-Standardpfad: filter visibility, deleted_at, sort created_at.
-- Ein einzelner kombinierter Index deckt das in einem Pass — ohne den
-- macht MySQL einen Filesort über alle public Routen.
CREATE INDEX idx_routes_public_discovery
    ON routes (visibility, deleted_at, created_at);
