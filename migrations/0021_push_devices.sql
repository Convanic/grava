-- Push-Benachrichtigungen (APNs), siehe backend/PUSH_BACKEND.md.
--
-- push_devices speichert die APNs-Device-Token je User. Ein Token ist
-- global eindeutig (UNIQUE) und kann den Besitzer wechseln (Re-Install,
-- Geräte-Weitergabe) → Upsert aktualisiert dann user_id. environment
-- entscheidet über den APNs-Host (sandbox vs. production).
CREATE TABLE push_devices (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    token       VARCHAR(200) NOT NULL,
    platform    VARCHAR(16)  NOT NULL,
    environment VARCHAR(16)  NOT NULL,
    updated_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    created_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_push_token (token),
    KEY idx_push_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Welle 2: neue Spiel-Notification-Typen. Schon jetzt im ENUM ergänzen,
-- damit der Versand-Code (und spätere Trigger) ohne weitere Migration
-- funktioniert. Bestehende Werte bleiben unverändert.
ALTER TABLE notifications
    MODIFY COLUMN type ENUM('follow','like','comment','territory_taken','crew_invite') NOT NULL;
