-- M4c: Notifications-Inbox (siehe docs/MILESTONE_4.md §3 D-C1..4).
--
-- Pull-Modell: Client pollt GET /notifications + /unread-count.
-- Events werden synchron im jeweiligen Service erzeugt (follow,
-- like, comment). Keine Notification an sich selbst und keine bei
-- Block-Beziehung — diese Regeln leben in NotificationService.
--
-- read_at = NULL bedeutet ungelesen. Der Index (user_id, read_at,
-- created_at) bedient sowohl die Inbox-Liste als auch den
-- Unread-Count effizient.
--
-- subject_type/subject_id zeigen auf das Bezugsobjekt (route bei
-- like/comment, user bei follow=Auslöser). FK gibt es darauf nicht,
-- weil das Subject polymorph ist; verwaiste Referenzen filtert die
-- Read-Query per JOIN aus.

CREATE TABLE notifications (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,   -- Empfänger
    actor_id     BIGINT UNSIGNED NOT NULL,   -- Auslöser
    type         ENUM('follow','like','comment') NOT NULL,
    subject_type ENUM('route','user') NULL,
    subject_id   BIGINT UNSIGNED NULL,
    created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    read_at      DATETIME(3)     NULL,
    PRIMARY KEY (id),
    KEY idx_notif_user_unread (user_id, read_at, created_at),
    CONSTRAINT fk_notif_user
        FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_actor
        FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
