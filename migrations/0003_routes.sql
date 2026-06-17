-- M2 Phase 1: Routen-Schema (siehe docs/MILESTONE_2.md §3).
--
-- Wichtige Designentscheidungen:
--
-- * `routes.centroid` ist ein POINT mit SRID 4326 (WGS84) und ein
--   SPATIAL INDEX, damit MySQL Spatial-Queries (ST_Distance_Sphere,
--   ST_Within usw.) effizient laufen. Falls ein Production-Provider
--   das nicht unterstützt, ist eine Folge-Migration "Drop Spatial,
--   B-Tree-only auf BBox-Spalten" trivial.
--
-- * `routes.head_version_id` zeigt auf die aktuell aktive Version.
--   Wir lösen den FK-Zyklus (routes <-> route_versions) durch eine
--   nachträgliche ALTER TABLE — `ON DELETE SET NULL` reicht, weil
--   die Application bei Hard-Delete einer Route alle Versions
--   sowieso mit-cascadiert.
--
-- * `client_route_uuid` ist optional, aber wenn gesetzt, eindeutig
--   pro User: damit ist der Upload idempotent. Schickt der iOS-Client
--   denselben UUID erneut, legt der Server eine neue Version an,
--   nicht eine zweite Route.
--
-- * `route_versions.payload_path` ist relativ zu STORAGE_ROUTES_DIR.
--   Die binären Geometrien leben im Filesystem, nicht in der DB —
--   das hält die DB klein und macht ein späteres CDN-Offloading
--   einfacher.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS routes (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id         CHAR(36)        NOT NULL,
  user_id           BIGINT UNSIGNED NOT NULL,
  client_route_uuid CHAR(36)        NULL,

  title             VARCHAR(140)    NOT NULL,
  description       TEXT            NULL,
  visibility        ENUM('private','unlisted','public') NOT NULL DEFAULT 'private',
  source            ENUM('app','import','strava','manual') NOT NULL DEFAULT 'app',

  -- Pointer auf den aktuell „aktiven" Track. NULL erlaubt, weil die
  -- Route in der Sekunde zwischen INSERT routes und INSERT route_versions
  -- noch keine Version hat. Anschließend setzt die Application den Wert.
  head_version_id   BIGINT UNSIGNED NULL,

  -- Denormalisierte Stats vom Head — vermeidet einen JOIN für das Listing.
  distance_m        INT UNSIGNED    NULL,
  elevation_gain_m  INT UNSIGNED    NULL,
  point_count       INT UNSIGNED    NULL,

  -- BBox erlaubt schnelle BBox-Filter (Map-Viewport-Queries) ohne Spatial.
  bbox_min_lat      DECIMAL(9,6)    NULL,
  bbox_min_lon      DECIMAL(9,6)    NULL,
  bbox_max_lat      DECIMAL(9,6)    NULL,
  bbox_max_lon      DECIMAL(9,6)    NULL,

  -- Mittelpunkt für Spatial-Queries („Routen in 50 km Umkreis").
  -- SRID 4326 = WGS84 (Lat/Lon in Grad). MySQL erlaubt SPATIAL INDEX
  -- nur auf NOT NULL-Spalten mit explizitem SRID. Bis die erste
  -- Version berechnet ist, setzt die Application einen Default-Punkt.
  centroid          POINT           SRID 4326 NOT NULL,

  created_at        DATETIME        NOT NULL,
  updated_at        DATETIME        NOT NULL,
  deleted_at        DATETIME        NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_routes_public_id (public_id),
  UNIQUE KEY uq_routes_user_client_uuid (user_id, client_route_uuid),
  KEY idx_routes_user_recent (user_id, deleted_at, created_at),
  KEY idx_routes_head_version (head_version_id),
  SPATIAL INDEX sp_routes_centroid (centroid),
  CONSTRAINT fk_routes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS route_versions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  route_id           BIGINT UNSIGNED NOT NULL,
  version            INT UNSIGNED    NOT NULL,
  format             ENUM('gpx','geojson') NOT NULL,
  payload_path       VARCHAR(255)    NOT NULL,
  payload_sha256     CHAR(64)        NOT NULL,
  payload_bytes      INT UNSIGNED    NOT NULL,

  point_count        INT UNSIGNED    NOT NULL,
  distance_m         INT UNSIGNED    NOT NULL,
  elevation_gain_m   INT UNSIGNED    NOT NULL,

  -- Optional aus der Aufzeichnung: wann die Tour gefahren wurde.
  started_at         DATETIME        NULL,
  ended_at           DATETIME        NULL,

  created_at         DATETIME        NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_route_versions_route_v (route_id, version),
  KEY idx_route_versions_route (route_id),
  CONSTRAINT fk_route_versions_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Jetzt erst, weil route_versions vorher noch nicht existierte.
-- ON DELETE SET NULL: würde theoretisch eine route_version einzeln
-- gelöscht werden, bleibt die Route bestehen — head wird auf NULL
-- gesetzt, die Application kann dann den nächsthöheren Version-Eintrag
-- suchen.
ALTER TABLE routes
  ADD CONSTRAINT fk_routes_head_version
  FOREIGN KEY (head_version_id) REFERENCES route_versions(id) ON DELETE SET NULL;


CREATE TABLE IF NOT EXISTS route_shares (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  route_id          BIGINT UNSIGNED NOT NULL,
  -- 32 zufällige Bytes, base64url. Wir speichern nur den SHA-256-Hash,
  -- exakt analog zum Reset-/Verify-Token-Pattern (Reverse-DB-Lookup
  -- ohne Klartext-Token-Kompromittierung).
  share_token_hash  CHAR(64)        NOT NULL,
  created_by        BIGINT UNSIGNED NOT NULL,
  expires_at        DATETIME        NULL,
  revoked_at        DATETIME        NULL,
  view_count        INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at        DATETIME        NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_route_shares_token (share_token_hash),
  KEY idx_route_shares_route (route_id),
  KEY idx_route_shares_creator (created_by),
  CONSTRAINT fk_route_shares_route   FOREIGN KEY (route_id)   REFERENCES routes(id) ON DELETE CASCADE,
  CONSTRAINT fk_route_shares_creator FOREIGN KEY (created_by) REFERENCES users(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS route_tags (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  route_id  BIGINT UNSIGNED NOT NULL,
  -- Tag wird lowercased + getrimmt von der Application gespeichert.
  -- 40 Zeichen reichen für sinnvolle Tags („gravel", „mountains",
  -- „kraichgau-loop"), längere lehnt der Validator ab.
  tag       VARCHAR(40)     NOT NULL,
  created_at DATETIME       NOT NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uq_route_tags_route_tag (route_id, tag),
  KEY idx_route_tags_tag (tag),
  CONSTRAINT fk_route_tags_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
