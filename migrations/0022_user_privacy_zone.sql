-- S8 Privatzonen / Heimat-Schutz (§17). Siehe PRIVACY_ZONE_BACKEND.md.
-- Eine Geofence-Zone pro Nutzer schützt die Heimat davor, über Revier,
-- geteilte Tracks oder die Heatmap ableitbar zu werden. lat/lon sind
-- HOCHSENSIBEL: werden niemals an andere Nutzer ausgeliefert.
-- Datenmodell bewusst erweiterbar gehalten (PK=user_id; spätere
-- Mehrfachzonen → eigene Tabelle mit eigener id migrierbar).

CREATE TABLE IF NOT EXISTS user_privacy_zone (
  user_id    BIGINT UNSIGNED NOT NULL,
  lat        DOUBLE          NOT NULL,
  lon        DOUBLE          NOT NULL,
  radius_m   INT             NOT NULL DEFAULT 500,
  enabled    TINYINT(1)      NOT NULL DEFAULT 1,
  updated_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id),
  CONSTRAINT fk_privacy_zone_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
