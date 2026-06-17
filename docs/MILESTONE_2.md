# Milestone 2 — Routen-Upload, Bibliothek & Sharing

> Stand: Entwurf, offen für Diskussion. Implementation startet erst, wenn die
> offenen Fragen unten geklärt sind.

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

## 11. Offene Fragen

1. **Versionierung im API:** Soll der Client beim erneuten Upload selbst die
   Version inkrementieren oder schickt er einfach POST und der Server merkt
   anhand `client_route_uuid`, dass es ein Update ist? *Empfehlung: Server-side.*
2. **Multipart vs. Base64-JSON:** Multipart ist effizienter, JSON-only ist
   einfacher für die App-Code-Basis. Falls die App Background-Uploads via
   `URLSession` macht, ist Multipart üblich. *Empfehlung: beides
   akzeptieren — Multipart wenn `Content-Type: multipart/form-data`,
   sonst JSON mit base64.*
3. **MySQL Spatial:** MAMP-MySQL 8.0 kann Spatial — produktiv aber prüfen.
   Falls Provider kein Spatial bietet, fallen wir auf BBox-Filter zurück.
4. **GPX-Library:** Eigenen Parser schreiben (klein, kontrolliert) oder
   `php-gpx` einbinden? *Vorschlag: eigener kleiner Parser, weil GPX-Felder,
   die wir brauchen, klein sind und externe Abhängigkeiten klein bleiben sollen.*
5. **Sichtbarkeit "public" vs Crowd:** "public" listet die Route in der
   späteren öffentlichen Suche/Heatmap. Solange Crowd-Aggregation nicht da
   ist, behandeln wir "public" und "unlisted" identisch (nur über Share-Link
   abrufbar). Schema-mäßig aber jetzt schon trennen, damit M3 nichts ändern
   muss.
6. **Route-Replacement vs. Versioning:** Soll `PATCH /routes/{id}` mit
   geändertem Track erlaubt sein, oder ausschließlich
   `POST /routes/{id}/versions`? *Empfehlung: PATCH nur Metadaten,
   Geometrie ist immutable je Version.*

## 12. Aufwandsschätzung

| Block                              | Aufwand |
|------------------------------------|---------|
| Migration + Schema                 | 0.5 PT  |
| GPX-/GeoJSON-Parser + Stats        | 1.0 PT  |
| RouteService + Repository          | 1.0 PT  |
| Controller (Routes + Shares)       | 1.0 PT  |
| Public Shared-Route-Controller     | 0.5 PT  |
| Storage-Layer + Cleanup-Erweiterung| 0.5 PT  |
| Tests / Smoke + Doku               | 0.5 PT  |
| **Gesamt**                         | **5.0 PT** |

Annahmen: keine größeren Schemafragen offen, MySQL Spatial verfügbar,
keine OAuth-Komplexität.
