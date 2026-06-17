-- M4b: Kommentare auf Routen (siehe docs/MILESTONE_4.md §3 D-B1..3).
--
-- Flach (kein parent_id / Threading in M4). Soft-Delete über
-- deleted_at: löschen darf der Autor ODER der Routen-Owner
-- (Moderation auf eigener Route) — diese Regel lebt im
-- CommentService, nicht in der DB.
--
-- body ist Plaintext 1..2000 Zeichen (Validierung im Service),
-- beim Rendern escaped. ON DELETE CASCADE räumt Kommentare mit,
-- wenn User oder Route hart gelöscht werden; Soft-Delete des Users
-- wird zusätzlich in AuthService::deleteAccount behandelt.

CREATE TABLE route_comments (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    route_id   BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    body       VARCHAR(2000)   NOT NULL,
    created_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at DATETIME(3)     NULL,
    PRIMARY KEY (id),
    KEY idx_comments_route (route_id, deleted_at, created_at),
    KEY idx_comments_user  (user_id),
    CONSTRAINT fk_comments_route
        FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_user
        FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
