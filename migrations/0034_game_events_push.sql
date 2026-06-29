-- Phase A Teil 1 (Ereignis-Strom) + Phase B (Spiel-Push).
-- Siehe docs/superpowers/specs/2026-06-29-game-events-push-design.md,
-- backend/GAME_EVENTS_BACKEND.md, backend/GAME_PUSH_BACKEND.md.
--
-- 1) game_event: gemeinsamer Ereignis-Strom (eine Quelle, mehrere Abnehmer).
--    Idempotent über (type, user_id, edge_id, ridden_on) — Re-Ingest/Recompute
--    feuert nicht doppelt. NULL-edge_id (z. B. edge_new) ist im UNIQUE-Key
--    bewusst nicht dedupliziert (MySQL behandelt NULLs als verschieden).
-- 2) notifications additiv: edge_id/count (Deep-Link + Digest) und ENUM-Werte
--    für die Spiel-Typen — fixt zugleich den latenten rush_*/subject_type='rush'-
--    Bug (Inserts scheiterten bisher an der ENUM-Schranke).
-- 3) user_notification_pref additiv: drei Spiel-Push-Schalter.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS game_event (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type          ENUM('edge_new','edge_taken','edge_lost','edge_reclaimed','record_beaten','pioneer_joined') NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,   -- Empfänger / Betroffener
  actor_user_id BIGINT UNSIGNED NULL,       -- auslösender Fahrer
  edge_id       BIGINT UNSIGNED NULL,
  ride_id       BIGINT UNSIGNED NULL,       -- route_id
  crew_id       BIGINT UNSIGNED NULL,
  ridden_on     DATE            NULL,       -- Idempotenz-Dimension
  payload       JSON            NULL,
  created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  notified_at   DATETIME(3)     NULL,       -- vom Push-Dispatcher verarbeitet
  read_at       DATETIME(3)     NULL,       -- Inbox (später)
  PRIMARY KEY (id),
  UNIQUE KEY uq_game_event_dedupe (type, user_id, edge_id, ridden_on),
  KEY idx_game_event_pending (notified_at, type, user_id, created_at),
  KEY idx_game_event_user (user_id, created_at),
  CONSTRAINT fk_game_event_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notifications
  MODIFY COLUMN type ENUM('follow','like','comment','territory_taken','crew_invite',
                          'edge_taken','edge_lost','edge_reclaimed','record_beaten','pioneer_joined',
                          'rush_invite','rush_reminder','rush_result') NOT NULL,
  MODIFY COLUMN subject_type ENUM('route','user','rush','edge') NULL,
  -- actor_id nullable: Digest-Mitteilungen haben keinen einzelnen Auslöser
  -- (actor=null). FK bleibt gültig (NULL verletzt den FK nicht).
  MODIFY COLUMN actor_id BIGINT UNSIGNED NULL,
  ADD COLUMN edge_id BIGINT UNSIGNED NULL AFTER subject_id,
  ADD COLUMN `count` INT UNSIGNED NULL AFTER edge_id;

ALTER TABLE user_notification_pref
  ADD COLUMN game_takeover TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN game_record   TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN game_pioneer  TINYINT(1) NOT NULL DEFAULT 0;
