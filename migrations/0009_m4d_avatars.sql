-- M4d: Avatar-Bild fürs Profil (siehe docs/MILESTONE_4.md §3 D-D1..3).
--
-- avatar_path ist relativ zu STORAGE_AVATARS_DIR (analog
-- route_versions.payload_path). NULL = kein Avatar gesetzt, dann
-- liefert das Serving einen generierten Initial-Placeholder.

ALTER TABLE users
    ADD COLUMN avatar_path VARCHAR(255) NULL DEFAULT NULL AFTER public_handle;
