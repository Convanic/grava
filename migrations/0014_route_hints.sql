-- M8: Wegpunkt-Hinweise (Route Hints).
--
-- Während einer Aufzeichnung setzt der Fahrer ortsbezogene Hinweise zum Weg —
-- negativ (z. B. unrideable, mud, gate, dangerous) oder positiv (great_view,
-- water, rest). Diese reisen bereits im GPX-Payload des bestehenden
-- POST /api/v1/routes als <wpt> mit ge:-Extension mit. Das Backend parst sie
-- beim Upload und legt sie hier ab — für die Ausgabe pro Route und (Zukunft)
-- Crowd-Aggregation.
--
-- Idempotenz:
--   uq_route_hint (route_id, client_hint_uuid) → Re-Upload derselben Route
--   aktualisiert denselben Hinweis statt ihn zu duplizieren. Der
--   client_hint_uuid wird serverseitig deterministisch gebildet
--   (UUIDv5 aus route_id, reason_key, gerundete lat/lon, recorded_at),
--   da der Client aktuell keinen mitsendet.
--
-- reason_key ist bewusst frei (kein FK auf einen Katalog), weil der Client
-- auch benutzerdefinierte Gründe (custom_*) erlaubt; label trägt den lesbaren
-- Namen zum Zeitpunkt der Erfassung denormalisiert mit.

CREATE TABLE IF NOT EXISTS route_hints (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  route_id         BIGINT UNSIGNED NOT NULL,
  client_hint_uuid CHAR(36)        NOT NULL,
  reason_key       VARCHAR(40)     NOT NULL,
  sentiment        ENUM('negative','positive') NOT NULL,
  label            VARCHAR(80)     NOT NULL,
  note             VARCHAR(280)    NULL,
  lat              DOUBLE          NOT NULL,
  lon              DOUBLE          NOT NULL,
  recorded_at      DATETIME(3)     NULL,
  created_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_route_hint (route_id, client_hint_uuid),
  KEY idx_route_hints_route (route_id),
  CONSTRAINT fk_route_hints_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
