-- Live-Aktiv-Zähler (PRESENCE_BACKEND.md): Heartbeat + TTL, Dedup über identity.
CREATE TABLE IF NOT EXISTS presence_active (
  identity   VARCHAR(80)  NOT NULL,
  last_seen  DATETIME(3)  NOT NULL,
  PRIMARY KEY (identity),
  KEY idx_presence_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
