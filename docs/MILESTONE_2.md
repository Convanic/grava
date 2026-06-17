# Milestone 2 — Routen-Upload, Bibliothek & Sharing

> Stand 2026-06-17: Offene Fragen aus §11 entschieden, Implementation
> kann starten. Aus dem M1-Code-Review wandern **H5** (Web-Session-
> Architektur) und **M5** (Email-Verify-Enforcement für Upload) in den
> M2-Scope, weil Web-UI ebenfalls vollen Upload + Sharing bekommt.

## 1. Ziel

Eingeloggte Nutzer:innen können in der iOS-App aufgezeichnete oder geplante
Routen ans Backend hochladen, in einer privaten Bibliothek verwalten und mit
einem Link teilen. Das Sharing erlaubt sowohl öffentliche Read-Only-Links
(token-basiert) als auch private Freigaben an andere registrierte User.

Crowd-Aggregation und Strava-OAuth sind **nicht** in diesem Milestone — sie
docken später an das hier eingeführte Schema an.

## 2. Anforderungen

### Funktional

- Route hochladen mit GPX- oder GeoJSON-Payload (mehrere Tracks pro File ok).
- Server berechnet ableitbare Metadaten (Distanz, Höhenmeter, BBox,
  Mittelpunkt, Punkteanzahl).
- Route bearbeiten: Titel, Beschreibung, Tags, Sichtbarkeit (private /
  unlisted / public).
- Route versionieren: jeder erneute Upload eines bestehenden Tracks legt eine
  neue Version an, der "Head" wandert.
- Route löschen (Soft-Delete, GDPR-konform purgebar via Cron).
- Liste eigener Routen mit Pagination + Filter (Datum, Distanz, Tag,
  Sichtbarkeit).
- Sharing-Link erstellen, widerrufen, mit optionalem Ablaufdatum.
- Öffentliches Lesen einer Route über Sharing-Token, ohne Auth.

### Nicht-funktional

- Upload-Limit per Default 10 MB pro Datei, 200 MB pro User insgesamt
  (configurierbar in `.env`).
- Server validiert GPX/GeoJSON syntaktisch und semantisch (Lat/Lon Range,
  Punkte > 1, etc.) bevor abgespeichert wird.
- Geometrien sollen perspektivisch räumliche Queries erlauben
  (Crowd-Heatmap später) → Schema wird so gewählt, dass MySQL Spatial
  Indexes greifen.
- Idempotenz beim Upload: Client schickt einen `client_route_uuid`
  (UUIDv4), gleicher Aufruf erzeugt nicht zwei Routen.

## 3. Datenmodell

Neue Migration `0002_routes.sql`. Bestehendes Schema bleibt unberührt.

### Tabelle `routes`

| Spalte             | Typ                         | Anmerkung                         |
|--------------------|-----------------------------|-----------------------------------|
| `id`               | `BIGINT UNSIGNED PK`        |                                    |
| `public_id`        | `CHAR(36) UNIQUE`           | UUID, nach außen exponiert         |
| `user_id`          | `BIGINT UNSIGNED FK→users`  | `ON DELETE CASCADE`                |
| `client_route_uuid`| `CHAR(36) NULL`             | Unique pro user, Idempotenz-Key    |
| `title`            | `VARCHAR(140)`              |                                    |
| `description`      | `TEXT NULL`                 |                                    |
| `visibility`       | `ENUM('private','unlisted','public') DEFAULT 'private'` | |
| `source`           | `ENUM('app','import','strava','manual') DEFAULT 'app'` | |
| `head_version_id`  | `BIGINT UNSIGNED NULL FK→route_versions` | aktueller "Head"        |
| `distance_m`       | `INT UNSIGNED NULL`         | denormalisiert vom Head            |
| `elevation_gain_m` | `INT UNSIGNED NULL`         |                                    |
| `point_count`      | `INT UNSIGNED NULL`         |                                    |
| `bbox_min_lat`/`bbox_min_lon`/`bbox_max_lat`/`bbox_max_lon` | `DECIMAL(9,6)` | für räumliche Filter |
| `centroid`         | `POINT NOT NULL SRID 4326`  | spatial index                      |
| `created_at`/`updated_at`/`deleted_at` | `DATETIME`      |                                    |

Unique: `(user_id, client_route_uuid)`.
Index: `(user_id, deleted_at, created_at)`, `SPATIAL INDEX(centroid)`.

### Tabelle `route_versions`

| Spalte           | Typ                          | Anmerkung                          |
|------------------|------------------------------|------------------------------------|
| `id`             | `BIGINT UNSIGNED PK`         |                                    |
| `route_id`       | `BIGINT UNSIGNED FK→routes`  | `ON DELETE CASCADE`                |
| `version`        | `INT UNSIGNED`               | 1, 2, 3, …                         |
| `format`         | `ENUM('gpx','geojson')`      |                                    |
| `payload_path`   | `VARCHAR(255)`               | relativ zu `STORAGE_ROUTES_DIR`    |
| `payload_sha256` | `CHAR(64)`                   | Integritäts-Check, Dedup-Hilfe     |
| `payload_bytes`  | `INT UNSIGNED`               |                                    |
| `point_count`    | `INT UNSIGNED`               |                                    |
| `distance_m`     | `INT UNSIGNED`               |                                    |
| `elevation_gain_m`| `INT UNSIGNED`              |                                    |
| `started_at`     | `DATETIME NULL`              | aus GPX `<time>` falls vorhanden   |
| `ended_at`       | `DATETIME NULL`              |                                    |
| `created_at`     | `DATETIME`                   |                                    |

Unique: `(route_id, version)`. Index: `(route_id)`.

> **Storage-Entscheidung:** Routen-Payload wird **nicht** in der DB abgelegt,
> sondern als File unter `storage/routes/<user_id>/<route_public_id>/v<n>.<format>`.
> Vorteile: kleine DB, einfaches CDN-Offloading später. Nachteil:
> Backup-Strategie muss DB + Filesystem koppeln — wird in Deployment-Doku
> beschrieben.

### Tabelle `route_shares`

| Spalte         | Typ                          | Anmerkung                          |
|----------------|------------------------------|------------------------------------|
| `id`           | `BIGINT UNSIGNED PK`         |                                    |
| `route_id`     | `BIGINT UNSIGNED FK→routes`  | `ON DELETE CASCADE`                |
| `share_token_hash` | `CHAR(64) UNIQUE`        | SHA-256 vom raw token              |
| `created_by`   | `BIGINT UNSIGNED FK→users`   |                                    |
| `expires_at`   | `DATETIME NULL`              |                                    |
| `revoked_at`   | `DATETIME NULL`              |                                    |
| `view_count`   | `INT UNSIGNED DEFAULT 0`     | für Stats                          |
| `created_at`   | `DATETIME`                   |                                    |

> Wir verwenden das gleiche Token-Pattern wie bei Reset-/Verify-Tokens:
> 32 zufällige Bytes, base64url, nur Hash gespeichert.

### Tabelle `route_tags`

Klassische N:M-Tabelle, falls Tagging gewünscht. Optional in M2 oder erst M3.

## 4. API

Alle Pfade unter `API_BASE_PATH` (Default `/api/v1`). Alle bis auf
`/shared/...` brauchen `Authorization: Bearer <access_token>`.

| Methode | Pfad                          | Zweck                                |
|---------|-------------------------------|--------------------------------------|
| POST    | `/routes`                     | Route anlegen (GPX/GeoJSON Multipart oder JSON-Payload) |
| GET     | `/routes`                     | Eigene Routen, paginiert, gefiltert  |
| GET     | `/routes/{public_id}`         | Detail-View (Metadaten + Payload-URL) |
| GET     | `/routes/{public_id}/payload` | Geometrie-Download im gewünschten Format (`?format=gpx|geojson`) |
| PATCH   | `/routes/{public_id}`         | Title/Description/Visibility/Tags    |
| POST    | `/routes/{public_id}/versions`| Neue Version uploaden                |
| DELETE  | `/routes/{public_id}`         | Soft-Delete                          |
| POST    | `/routes/{public_id}/shares`  | Share-Link erzeugen                  |
| GET     | `/routes/{public_id}/shares`  | Aktive Share-Links auflisten         |
| DELETE  | `/routes/{public_id}/shares/{share_id}` | Widerrufen                |
| GET     | `/shared/{token}`             | Public Read (Metadaten)              |
| GET     | `/shared/{token}/payload`     | Public Read (Geometrie)              |

### Request- und Response-Skizzen

**POST /routes** (Multipart oder JSON):

```json
{
  "client_route_uuid": "9f...-...",
  "title": "Kraichgau-Runde",
  "description": "Schöne 60 km mit zwei Heftigen.",
  "format": "gpx",
  "payload": "<base64 oder als file part>",
  "visibility": "private"
}
```

Response `201`:
```json
{
  "route": {
    "id": "uuid",
    "title": "Kraichgau-Runde",
    "visibility": "private",
    "distance_m": 61234,
    "elevation_gain_m": 842,
    "point_count": 4321,
    "bbox": { "min_lat": ..., "min_lon": ..., "max_lat": ..., "max_lon": ... },
    "centroid": { "lat": ..., "lon": ... },
    "head_version": { "version": 1, "started_at": "...", "ended_at": "..." },
    "created_at": "..."
  }
}
```

**GET /routes**: `?limit=50&cursor=<id>&visibility=private&min_km=20&max_km=80`.
Cursor-basierte Pagination (`X-Next-Cursor` Header oder Body-Feld).

**Sicherheit:**

- Authorization-Check: bei jedem `/routes/{public_id}` Pfad `route.user_id ==
  auth.user_id`, sonst `404` (nicht `403`, um Existenz nicht zu leaken).
- `/shared/{token}` umgeht Auth, gibt aber `410` zurück, falls
  widerrufen/abgelaufen, und `404` falls Token unbekannt.
- Rate-Limit `/routes` POST (z. B. 60 Uploads pro Stunde pro User).

## 5. Validierung & Verarbeitung

- Datei-Header sniffen (`<gpx`, `{` für GeoJSON), MIME ist nicht vertrauenswürdig.
- GPX: `php-gpx/php-gpx`-Lib oder ein eigener kleiner Parser. GeoJSON:
  reines `json_decode` + Schema-Check.
- Pflicht: ≥ 2 Punkte, alle in Lat/Lon-Range.
- Distanz: Haversine über alle Segmente.
- Elevation: nur summieren, wenn Profil Plausibilitäts-Threshold besteht
  (Smoothing mit ±1 m Hysterese, sonst Sägezahn-GPS verfälscht).
- Server schreibt **kanonische GeoJSON-Repräsentation** zusätzlich zur
  Original-Datei, damit `/payload?format=geojson` ohne Re-Parsing antworten kann.

## 6. Storage-Layout

```
storage/
  routes/
    <user_id>/
      <route_public_id>/
        v1.gpx          (original)
        v1.geojson      (kanonisch)
        v2.gpx
        v2.geojson
```

- Mode `0750` für `<user_id>`, `0640` für Files.
- `cron:cleanup` räumt Files bei Hard-Delete (siehe unten) auf.

## 7. Soft-Delete & Cleanup

- `DELETE /routes/{id}` setzt `deleted_at`. Datei bleibt zunächst.
- `cron:cleanup` (existiert bereits in M1) bekommt einen neuen Step:
  Files von Routen mit `deleted_at < now - 30 days` werden vom FS entfernt
  und die Zeilen aus DB hard-gelöscht.
- Beim User-Soft-Delete (M1) müssen die Routen ebenfalls geschrubbt werden:
  `email`-Scrub passiert schon, aber `routes.user_id` zeigt noch auf
  scrubbed user. Wir markieren in dieser Migration alle Routen des Users
  als `deleted_at = now()`.

## 8. Migrations-Plan

1. `migrations/0002_routes.sql` mit allen vier Tabellen.
2. Anpassung in `AuthService::deleteAccount()`: nach User-Soft-Delete auch
   `UPDATE routes SET deleted_at = NOW() WHERE user_id = ?`.

Beides als ein Commit, weil der zweite Schritt die neue Tabelle voraussetzt.

## 9. Code-Struktur

```
src/
  Routes/
    RouteService.php          (Geschäftslogik + Storage)
    GeometryParser.php        (GPX/GeoJSON → kanonisches DTO)
    GeometryStats.php         (Distanz/Höhenmeter/BBox)
    RouteRepository.php       (PDO-Layer)
    ShareTokenService.php
  Controllers/Api/
    RouteController.php
    SharedRouteController.php (öffentliche /shared Routen)
```

`RouteController` analog zu `AuthController` aufgebaut, gleiche
Validator-Patterns. Storage-Pfade und Limits in `Config`.

## 10. Tests / Smoke-Plan

Sobald implementiert, gegen den Built-in-Server smoken:

1. POST GPX hochladen → 201, prüft DB + Filesystem
2. GET /routes → eigene drin
3. PATCH visibility=public, Titel ändern → 200
4. POST /shares → Token zurück; GET /shared/<token>/payload → 200
5. DELETE Route → 204, GET → 404
6. Share-Token nach Revoke → 410
7. POST /routes mit identischer `client_route_uuid` → idempotent (gleiche `route.id`)
8. Foreign-User probiert `GET /routes/{anderer-user-id}` → 404
9. Versuche, GPX-Upload mit 11 MB → 413
10. Upload mit Lat=99 → 422 mit klarem Validation-Error

## 11. Entscheidungen (vorher offene Fragen)

1. **Versionierung im API → Server-side via `client_route_uuid`.**
   Client schickt immer `POST /routes` mit dem gleichen `client_route_uuid`;
   Server merkt anhand des Unique-Keys, dass es ein Update ist, legt eine
   neue `route_versions`-Zeile an und schiebt `head_version_id`. Ein
   expliziter `POST /routes/{id}/versions` bleibt als alternative Form
   erlaubt, ist aber nicht der Default-Pfad.
2. **Upload-Format → beides, Content-Type-getrieben.**
   `Content-Type: multipart/form-data` → File-Part `payload` lesen.
   Sonst JSON mit `payload` als Base64-String. Wechsel pro Request
   möglich.
3. **MySQL Spatial → aktivieren.**
   Migration legt `SPATIAL INDEX(centroid)` an, MAMP-MySQL 8 unterstützt
   das. Sollte ein Production-Provider kein Spatial bieten, ist eine
   Folge-Migration „Drop Spatial Index, BBox-Only" trivial.
4. **GPX-Library → `sibyx/phpgpx:^2.0.0-beta.1`.**
   PHP-8.1+-Rewrite, GeoJSON-Serialisierung eingebaut (RFC 7946 via
   `JsonSerializable`), `Engine::default()` rechnet Distanz/Höhenmeter/
   Bounds in einem Pass. Beta, aber März 2026 released, MIT, aktiv
   gewartet. Wir versionspinnen genau, damit ein 2.0.0-stable-Release
   nicht versehentlich Breaking Changes einschleppt.
5. **`visibility=public` → bis Crowd kommt identisch zu `unlisted`.**
   Schema trennt die Werte, Anzeige-/Listing-Logik behandelt beide gleich
   (nur über Share-Link abrufbar). M3 ändert nur den Filter in der
   öffentlichen Suche, kein Migrations-Aufwand.
6. **PATCH ändert nur Metadaten.**
   Title, Description, Visibility, Tags. Geometrie ist immutable je
   Version; Geometrie-Update läuft ausschließlich über
   `POST /routes` (idempotent) oder explizit `POST /routes/{id}/versions`.

## 11a. Zusätzliche Entscheidungen aus dem M1-Code-Review

7. **M5 — Email-Verify-Pflicht.**
   `POST /routes` (Upload) ist gesperrt, bis `email_verified_at IS NOT NULL`.
   Listing, PATCH, Sharing bleiben offen — wenn der User schon Routen
   hat (z. B. weil Verify-Pflicht erst nachträglich eingeführt wurde),
   kann er die weiter verwalten. UI/Web zeigt ein Banner „Bitte
   verifiziere zuerst deine E-Mail-Adresse" mit Resend-Button.
8. **H5 — Web-Session-Architektur.**
   Web-UI bekommt vollen Upload + Sharing. Damit wandert die saubere
   Web-Auth in M2-Scope. Konkrete Festlegung:

   - **Primär-Auth für Web:** Server-side `$_SESSION['user_id']` +
     `$_SESSION['expires_at']` (30 Minuten Sliding — jede authentifizierte
     Aktion erneuert die TTL).
   - **`ge_refresh` Cookie** wird auf `path=/auth/web-refresh` (Web) bzw.
     `path=/api/v1/auth/refresh` (API) gescoped, geht nicht mehr bei
     jedem Page-Load mit.
   - **`ge_access` Cookie** bleibt für JS-fetch-Calls aus dem Browser
     verfügbar (path=/), kurze TTL, wird beim Web-Refresh mit-rotiert.
   - **Silent Refresh über `/auth/web-refresh`:** Wenn die PHP-Session
     abgelaufen aber `ge_refresh` noch gültig, redirecten wir
     einmalig auf `/auth/web-refresh?next=<original>`, das rotiert das
     Refresh-Token, baut die Session neu und leitet zurück. Keine
     Refresh-Token-Rotation mehr bei jedem Page-Load.
   - **`CookieAuth::resolve()`** liest primär aus `$_SESSION`,
     `$_SESSION` zerstören wir bei Logout und bei
     `Csrf::rotateForAuthState()` (das war's vorher schon).

## 12. Aufwandsschätzung

| Block                              | Aufwand |
|------------------------------------|---------|
| Phase 0 — H5 Web-Session-Architektur | 0.5 PT |
| Migration + Schema                 | 0.5 PT  |
| Parser (`sibyx/phpgpx` integriert) + Stats | 0.8 PT |
| RouteService + Repository          | 1.0 PT  |
| API-Controller (Routes + Shares)   | 1.0 PT  |
| Public Shared-Route-Controller     | 0.5 PT  |
| M5 — `RequireVerified`-Middleware  | 0.1 PT  |
| Web-UI für Upload + Listing + Sharing | 1.5 PT |
| Storage-Layer + Cleanup-Erweiterung| 0.5 PT  |
| Tests / Smoke + Doku               | 0.5 PT  |
| **Gesamt**                         | **~7 PT** |

Annahmen: MySQL Spatial verfügbar (MAMP-MySQL 8 bestätigt), keine
OAuth-Komplexität, Web-Multipart-Upload ohne Drag-&-Drop-UI (klassisches
`<input type=file>` reicht). Ausgangspunkt ist `main` nach Code-Review-
Fixes (Critical + High + Medium + Polish + Quick-Wins) — keine
Vor-Refactor-Schulden mehr.

## 13. Ausführungs-Reihenfolge

| Phase | Schritt | Abhängigkeiten |
|---|---|---|
| 0 | H5-Web-Session-Refactor | keine — kommt **zuerst**, weil Routen-Web-UI darauf aufbaut |
| 1 | Migration `0003_routes.sql` + `AuthService::deleteAccount`-Erweiterung | Phase 0 |
| 2 | `composer require sibyx/phpgpx` + `GeometryParser`/`Stats` | Phase 1 |
| 3 | `RouteRepository`, `RouteService`, `ShareTokenService` | Phase 2 |
| 4 | `RouteController` + `SharedRouteController` + Routen-Mapping in `public/index.php` | Phase 3 |
| 5 | `RequireVerified`-Middleware + Binding an `POST /routes` (M5) | Phase 4 |
| 6 | Web-Controller `RoutePagesController` + Views (Upload-Form, Listing, Detail, Share-Verwaltung) | Phase 0 + 4 |
| 7 | `Commands::cleanup()` für Routen-FS-Cleanup | Phase 1, 4 |
| 8 | Smoke-Plan §10 vollständig durchspielen | alle |

Jede Phase landet auf einem eigenen Feature-Branch und wird per
`--no-ff` in `main` gemerged — analog zur Tranche-Struktur aus dem
Code-Review-Cleanup.
