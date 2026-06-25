-- Strava-Share: verknüpfte Aktivität pro Route (Idempotenz, STRAVA_SHARE_BACKEND.md §3.2).
ALTER TABLE routes
  ADD COLUMN strava_activity_id BIGINT UNSIGNED NULL AFTER source,
  ADD KEY idx_routes_strava_activity (strava_activity_id);
