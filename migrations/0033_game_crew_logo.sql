-- Crew-Logo (GAME_CREW_LOGO_BACKEND.md, 2026-06-27). 1:1-Spiegel des Avatar-
-- Mechanismus, nur pro Crew statt pro User. Additiv: NULL = kein Logo.
ALTER TABLE game_crew
  ADD COLUMN logo_path       VARCHAR(255) NULL,   -- relativer Speicherpfad, NULL = kein Logo
  ADD COLUMN logo_updated_at DATETIME(3)  NULL;   -- Zeitpunkt der letzten Änderung (Cache-Buster)
