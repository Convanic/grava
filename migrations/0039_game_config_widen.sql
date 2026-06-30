-- Verbreitert game_config.config_value (VARCHAR 64 → 512), damit längere
-- JSON-Parameter im Admin-Config-Editor pflegbar sind — v. a. der Ränge-/
-- Abzeichen-Katalog (progression_catalog ~390 Zeichen) und das Gate.
-- Reine, verlustfreie Spalten-Verbreiterung (MySQL in-place). Einzel-Statement.

SET NAMES utf8mb4;

ALTER TABLE game_config MODIFY config_value VARCHAR(512) NOT NULL;
