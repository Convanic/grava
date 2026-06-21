-- Einmalige administrative Daten-Korrektur: Handle @apf -> @ap.
-- Umgeht bewusst die 3-Zeichen-Mindestlänge der App-Validierung
-- (Validator::publicHandle). Charset/Format (a-z0-9) bleiben gültig,
-- die /u/{handle}-Route ist durch das /u/-Präfix kollisionsfrei.
-- Schlägt mit Duplicate-Key fehl, falls 'ap' bereits vergeben ist
-- (uq_users_public_handle) — dann bewusst laut abbrechen.
UPDATE users SET public_handle = 'ap' WHERE public_handle = 'apf';
