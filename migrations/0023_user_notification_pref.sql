-- S9 Per-Typ-Benachrichtigungen. Siehe NOTIFICATION_PREFERENCES_BACKEND.md.
-- Steuert NUR den Push-Versand pro Typ; der In-App-Eintrag (notifications)
-- bleibt immer erhalten. Default für alles = true (fehlende Zeile = alle an).
-- Erweiterbar: künftige Typen (territory_taken/crew_invite, Welle 2) als
-- weitere Boolean-Spalten additiv ergänzbar.

CREATE TABLE IF NOT EXISTS user_notification_pref (
  user_id    BIGINT UNSIGNED NOT NULL,
  `follow`   TINYINT(1)      NOT NULL DEFAULT 1,
  `like`     TINYINT(1)      NOT NULL DEFAULT 1,
  `comment`  TINYINT(1)      NOT NULL DEFAULT 1,
  updated_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id),
  CONSTRAINT fk_notif_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
