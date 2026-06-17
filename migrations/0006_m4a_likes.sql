-- M4a: Likes/Reactions auf Routen (siehe docs/MILESTONE_4.md §3 D-A1..3).
--
-- PK auf (user_id, route_id) erzwingt Idempotenz: zweimal POST /like
-- ist ein Like, nicht zwei. Die `reaction`-Spalte ist für spätere
-- Multi-Reactions vorbereitet, die API exponiert in M4 aber nur 'like'.
--
-- Kein denormalisierter like_count auf routes — wir zählen per
-- COUNT(*) über idx_route_likes_route. Spart Counter-Drift-Bugs.
--
-- ON DELETE CASCADE räumt Likes mit, wenn User oder Route hart
-- gelöscht werden. Soft-Delete (User: status='deleted', Route:
-- deleted_at) triggert das nicht — dafür gibt es explizite
-- Cleanup-Logik in AuthService::deleteAccount.

CREATE TABLE route_likes (
    user_id    BIGINT UNSIGNED NOT NULL,
    route_id   BIGINT UNSIGNED NOT NULL,
    reaction   VARCHAR(16)     NOT NULL DEFAULT 'like',
    created_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (user_id, route_id),
    KEY idx_route_likes_route (route_id, created_at),
    CONSTRAINT fk_route_likes_user
        FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_route_likes_route
        FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
