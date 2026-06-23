-- S9 Mobile-Connect: Strava-OAuth ohne Web-Session.
--
-- Bisher war der Callback an die Browser-Session gebunden (CSRF-Schutz fuer
-- den Web-Flow). Die native App hat im ASWebAuthenticationSession-Sheet aber
-- keine Web-Session, nur den API-Bearer. Daher merken wir uns pro State, ueber
-- welchen Flow er erzeugt wurde:
--   flow='web'    -> Callback verlangt weiterhin eine passende Web-Session.
--   flow='mobile' -> der single-use State IST die Bindung (per Bearer erzeugt);
--                    der Callback schliesst session-los ab und leitet per
--                    Deep-Link (return_to) in die App zurueck.

ALTER TABLE oauth_states
  ADD COLUMN flow      VARCHAR(16)  NOT NULL DEFAULT 'web' AFTER provider,
  ADD COLUMN return_to VARCHAR(255) NULL                   AFTER flow;
