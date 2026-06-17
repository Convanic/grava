-- Refresh-Token-Reuse-Detection: zusätzlich zur aktuell gültigen
-- refresh_hash speichern wir den unmittelbar vorigen Wert. Versucht
-- jemand denselben (bereits rotierten) Refresh-Token erneut einzulösen,
-- erkennen wir das, weil der Hash in previous_refresh_hash steht und
-- die Session noch aktiv ist — wir entwerten dann ALLE Sessions des
-- Users, weil typischerweise eine Token-Kompromittierung dahintersteckt.

ALTER TABLE sessions
    ADD COLUMN previous_refresh_hash CHAR(64) NULL AFTER refresh_hash,
    ADD UNIQUE KEY uq_sessions_previous_refresh (previous_refresh_hash);
