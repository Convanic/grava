-- Ränge & Abzeichen (Feature #5) — Stufe 1+2: Abzeichen-Persistenz.
-- Konzept: GravelExplorer/RankBadges_Concept.md (§5.2 Familien/Stufen, §13 Entscheidungen).
--
-- Eine Zeile pro (user, familie, stufe), geschrieben beim ERSTEN Erreichen der
-- Stufe und nie gelöscht → bildet die "Höchststand/unverlierbar"-Regel (§13.4)
-- automatisch ab, auch wenn ein Live-Wert (z. B. Revierlänge) später fällt.
--
-- AP/Ränge und der Abzeichen-Katalog (Schwellen, Gate) leben als Defaults in
-- App\Game\GameConfig: game_config.config_value ist VARCHAR(64) und zu kurz für
-- den JSON-Katalog; eine spätere Spalten-Verbreiterung macht sie DB-justierbar.
-- Idempotent: CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS game_player_badge (
  id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED  NOT NULL,
  family        VARCHAR(32)      NOT NULL,            -- erschliesser|revierhalter|kondition|stammfahrer|schnellster|…
  tier          TINYINT UNSIGNED NOT NULL,            -- 0=Bronze .. 4=Onyx
  value_at_earn DOUBLE           NOT NULL DEFAULT 0,  -- Messwert beim Erreichen (Audit/Anzeige)
  earned_at     DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_player_badge (user_id, family, tier),
  KEY idx_player_badge_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
