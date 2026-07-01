-- Ride-Aggregate aus gekoppelten BLE-Sensoren (PowerData_Backend_Spec.md).
-- Additiv & nullable: bestehende Routen bleiben NULL (kein Sensor / Alt-Upload).
-- Reine Anzeigedaten, denormalisiert auf die routes-Zeile (wie
-- traffic_passes_per_km) — fließen nicht in Scoring/Spiel ein.

ALTER TABLE routes
  ADD COLUMN avg_power_w           INT    NULL,
  ADD COLUMN max_power_w           INT    NULL,
  ADD COLUMN avg_cadence_rpm       INT    NULL,
  ADD COLUMN avg_pedal_balance_pct DOUBLE NULL,
  ADD COLUMN avg_heart_rate_bpm    INT    NULL,
  ADD COLUMN max_heart_rate_bpm    INT    NULL;
