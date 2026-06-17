-- M3 Phase 0: public_handle für Profile-URLs (/u/{handle}).
--
-- NULL erlaubt — bestehende User bekommen keinen automatischen Handle,
-- sondern müssen ihn explizit unter /settings/handle setzen, sobald sie
-- ein öffentliches Profil wollen. Bis dahin sind sie weder in Discovery
-- noch unter /u/... sichtbar.
--
-- UNIQUE-Constraint setzen wir in derselben ALTER, weil MySQL den
-- Spaltenzusatz und das Constraint atomar verarbeitet (keine Race-
-- Condition zwischen "Spalte da" und "Unique aktiv").
--
-- Der Validator erzwingt zusätzlich:
--   - regex ^[a-z0-9_]{3,30}$
--   - reserviertes Wort-Listing (admin, api, login, ...)
-- Beides liegt auf Service-Ebene, nicht in der DB — das Schema bleibt
-- bewusst sparsam, sodass eine spätere Politik-Änderung (z. B. „4 Zeichen
-- min") keine Migration braucht.

ALTER TABLE users
  ADD COLUMN public_handle VARCHAR(30) NULL DEFAULT NULL AFTER display_name,
  ADD CONSTRAINT uq_users_public_handle UNIQUE (public_handle);
