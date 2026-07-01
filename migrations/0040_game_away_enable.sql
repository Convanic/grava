-- Schaltet den Auswärts-Multiplikator (Konzept §20 / GAME_AWAY_MULTIPLIER_BACKEND.md)
-- produktiv scharf: setzt game_config.away_enabled = 1. Der Code-Default ist '0'
-- (Deploy bleibt bit-identisch); diese Migration aktiviert den Bonus serverseitig.
-- Explizites VALUES() (nicht das Seed-Muster config_value=config_value), damit die
-- Aktivierung deterministisch greift, egal ob schon eine Zeile existiert.
-- Zurückschalten: away_enabled auf '0' im Admin-Config-Editor (oder Folge-Migration).
-- Einzel-Statement, idempotent. Wirkt live (Read-Feld + neue Ingests); bestehende
-- gecachte Besitz-/Präsenzwerte aktualisieren sich beim nächsten Recompute/Ingest.

SET NAMES utf8mb4;

INSERT INTO game_config (config_key, config_value) VALUES ('away_enabled', '1')
ON DUPLICATE KEY UPDATE config_value = VALUES(config_value);
