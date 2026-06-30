-- Challenge-Abschlüsse (RankBadges_Concept.md §5.2 „Challenger"-Familie).
-- Eine Zeile je (user, challenge, Woche), idempotent geschrieben, sobald der
-- Fortschritt das Ziel erreicht — Basis für die Challenger-Abzeichen-Familie
-- (= Anzahl jemals abgeschlossener Challenges).
--
-- Die Challenges selbst sind weiterhin zustandslos/live (ChallengeService);
-- hier wird nur der ABSCHLUSS festgehalten (analog game_player_badge: einmal
-- erreicht, bleibt). Idempotenz über den Primärschlüssel.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS game_challenge_completion (
  user_id      BIGINT UNSIGNED NOT NULL,
  challenge_id VARCHAR(64)     NOT NULL,
  period_start DATE            NOT NULL,            -- Wochen-Montag (Idempotenz-Dimension)
  completed_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id, challenge_id, period_start),
  KEY idx_ccompletion_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
