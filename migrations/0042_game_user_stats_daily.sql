-- Täglicher Snapshot der eigenen Revier-Kennzahlen je Claimant (Rider oder Crew)
-- für den zeitlichen Verlaufs-Chart im iOS-Reviere-Tab (GameHistory_Backend_Spec.md).
-- Additiv, eigene Tabelle — verändert keine bestehende. Ein Cron (game:snapshot-daily)
-- schreibt je Tag genau eine Zeile pro Claimant (idempotent über den UNIQUE-Key);
-- der erste Lauf backfillt zusätzlich die Vergangenheit aus game_edge.owner_since /
-- discovered_at. Gelesen wird rein aggregierend über GET /game/me/history.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS game_user_stats_daily (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  claimant_id     BIGINT UNSIGNED NOT NULL,
  snapshot_date   DATE            NOT NULL,   -- UTC-Kalendertag
  held_edges      INT             NOT NULL,
  pioneered_edges INT             NOT NULL,
  held_length_m   DOUBLE          NOT NULL,
  created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_stats_daily (claimant_id, snapshot_date),
  KEY idx_stats_daily_claimant (claimant_id, snapshot_date),
  CONSTRAINT fk_stats_daily_claimant FOREIGN KEY (claimant_id)
    REFERENCES game_claimant(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
