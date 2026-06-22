# GRAVA – API-Integrationsguide (iOS)

Diese Datei beschreibt die **komplette HTTP-API** des GRAVA-Backends
(Milestones 1–4) für die Anbindung der iOS-App. Sie ist als Übergabe-Dokument
gedacht: in das iOS-Repo kopieren oder dem dortigen Assistenten als Kontext geben.

Maschinenlesbare Variante: [`openapi.yaml`](../openapi.yaml) (OpenAPI 3.1).

---

## 1. Basis

| Punkt | Wert |
|-------|------|
| Base-URL (lokal) | `http://gravelexplorer.test` (MAMP-Vhost) |
| Base-URL (prod)  | `https://grava.world` |
| API-Präfix | `/api/v1` (konfigurierbar via `API_BASE_PATH`) |
| Content-Type (Request) | `application/json` (Upload zusätzlich `multipart/form-data`) |
| Content-Type (Response) | `application/json; charset=utf-8` |
| Zeichensatz | UTF-8 |
| Zeitstempel | ISO-8601 UTC, z. B. `2026-05-01T07:30:00Z` |

Alle vollständigen Pfade sind also z. B. `https://…/api/v1/auth/login`.

### Empfohlene Header

```
Content-Type: application/json
Accept: application/json
Authorization: Bearer <access_token>     # nur bei geschützten Endpunkten
X-Client: ios                            # bei /auth/login + /auth/register
```

`X-Client: ios` markiert die Session als iOS-Client (sonst `other`). Rein
informativ, aber empfohlen.

---

## 2. Authentifizierung & Token-Lebenszyklus

Die API nutzt **Bearer-Tokens** (kein Cookie für den nativen Client).

- **Access-Token**: kurzlebig, geht als `Authorization: Bearer …` an jeden
  geschützten Endpunkt. Lebensdauer in `access_expires_in` (Sekunden).
- **Refresh-Token**: langlebig, wird ausschließlich an `POST /auth/refresh`
  geschickt, um neue Tokens zu holen.

**Rotation:** Jeder `POST /auth/refresh` gibt ein **neues** Access- *und*
Refresh-Token zurück und entwertet die alten Access-Tokens der Session. Der
Client muss das neue Refresh-Token speichern und das alte verwerfen.

> Speichere `access_token` und `refresh_token` in der **Keychain**, nicht in
> `UserDefaults`.

### Kompletter Flow (Registrierung → Nutzung)

```text
1. POST /auth/register            -> 202 (generische Message, KEINE Tokens)
2. User erhält Verify-E-Mail      -> Link: <APP_URL>/verify-email?token=XXX
3. POST /auth/email/verify {token}-> 200 (E-Mail bestätigt)         [optional via App]
4. POST /auth/login               -> 200 { access_token, refresh_token, user }
5. Geschützte Calls mit Bearer    -> ...
6. Bei 401 (access abgelaufen):
   POST /auth/refresh {refresh_token} -> 200 neue Tokens; Call wiederholen
```

**Wichtig:** Registrierung loggt **nicht** automatisch ein und liefert **keine**
Tokens (Anti-Account-Enumeration). Erst nach erfolgreichem Login gibt es Tokens.
Login erfordert eine **verifizierte E-Mail** (sonst `403 email_not_verified`).

---

## 3. Fehler-Envelope

Alle Fehler (4xx/5xx) haben dasselbe Format:

```json
{
  "error": {
    "code": "validation_error",
    "message": "Bitte überprüfe deine Eingaben.",
    "fields": { "email": ["Bitte gib eine gültige E-Mail-Adresse an."] }
  }
}
```

`fields` ist optional (nur bei Feld-Validierung). Häufige `code`-Werte:

| HTTP | code | Bedeutung |
|------|------|-----------|
| 401 | `unauthorized` | Token fehlt/ungültig |
| 401 | `invalid_credentials` | Login fehlgeschlagen |
| 401 | `invalid_token` | Refresh-/Reset-Token ungültig |
| 403 | `email_not_verified` | E-Mail noch nicht bestätigt |
| 403 | `forbidden` | Aktion nicht erlaubt (z. B. fremder Kommentar) |
| 404 | `not_found` | Ressource existiert nicht / nicht sichtbar (auch bei Block) |
| 409 | `handle_taken` / `handle_locked` | Handle vergeben bzw. schon gesetzt |
| 413 | `payload_too_large` | Upload zu groß |
| 422 | `validation_error` | Eingabe ungültig (siehe `fields`) |
| 429 | `rate_limited` | Rate-Limit; Header `Retry-After` beachten |

Bei `429` wird der HTTP-Header `Retry-After` (Sekunden) mitgesendet.

---

## 4. Standard-Objekte

### User

```json
{
  "id": "9f1c…-uuid",
  "email": "user@example.com",
  "display_name": "Armin",
  "public_handle": "armin_gravel",
  "email_verified": true,
  "created_at": "2026-06-01T10:00:00Z"
}
```

Öffentliche Profile (`/users/by-handle/…`) enthalten zusätzlich Zähler/Flags
(`follower_count`, `is_followed_by_viewer`, `is_self` etc.).

### Route

```json
{
  "id": "ceb0aabf-…-uuid",
  "client_route_uuid": null,
  "title": "Kraichgau Gravel Loop",
  "description": null,
  "visibility": "public",
  "source": "app",
  "version": 1,
  "format": "geojson",
  "stats": {
    "distance_m": 42100,
    "elevation_gain_m": 580,
    "point_count": 1240,
    "started_at": null,
    "ended_at": null,
    "bbox": { "min_lat": 49.0, "min_lon": 8.4, "max_lat": 49.2, "max_lon": 8.8 },
    "centroid": { "lat": 49.1, "lon": 8.6 }
  },
  "tags": ["gravel", "kraichgau"],
  "owner": { "handle": "armin_gravel", "display_name": "Armin" },
  "created_at": "2026-06-01T12:00:00Z",
  "updated_at": "2026-06-01T12:00:00Z",
  "deleted_at": null
}
```

`visibility` ∈ `private | unlisted | public`. `source` ∈ `app | import | strava | manual`.
`owner` erscheint auf Discovery-/Profil-Listings; bei den eigenen Routen
(`/routes`) bist du selbst der Owner.

### Pagination

Listen-Endpunkte liefern:

```json
{ "routes": [ … ], "pagination": { "limit": 20, "offset": 0, "total": 57, "has_more": true } }
```

(`total` fehlt bei einigen Listen, die stattdessen nur `has_more` setzen.)

---

## 5. Endpunkte

Legende Auth: **—** = kein Token · **Bearer** = Access-Token nötig ·
**Bearer+Verified** = zusätzlich verifizierte E-Mail · **(Bearer)** = optional
(beeinflusst nur Sichtbarkeits-/Viewer-Flags).

### 5.1 Auth & Account

| Methode | Pfad | Auth | Zweck |
|---------|------|------|-------|
| POST | `/auth/register` | — | Konto anlegen, Verify-Mail. **Immer 202.** |
| POST | `/auth/login` | — | Login → Tokens |
| POST | `/auth/refresh` | — | Tokens rotieren |
| POST | `/auth/logout` | Bearer | Aktuelle Session beenden (204) |
| POST | `/auth/logout-all` | Bearer | Alle Sessions beenden (204) |
| POST | `/auth/password/change` | Bearer | Passwort ändern (204, alle Sessions invalidiert) |
| POST | `/auth/password/forgot` | — | Reset-Mail anfordern (202) |
| POST | `/auth/password/reset` | — | Passwort per Token setzen (204) |
| POST | `/auth/email/verify` | — | E-Mail per Token bestätigen |
| POST | `/auth/email/verify/resend` | (Bearer) | Verify-Mail erneut senden (202) |
| GET | `/users/me` | Bearer | Eigenes Profil |
| PATCH | `/users/me` | Bearer | `display_name` ändern |
| DELETE | `/users/me` | Bearer | Konto löschen (Body: `password`) |
| PATCH | `/users/me/handle` | Bearer+Verified | `public_handle` **einmalig** setzen |

**Register** — Request:
```json
{ "email": "user@example.com", "password": "min-10-Zeichen", "display_name": "Armin" }
```
`password`: ≥ 10 Zeichen, nicht gleich E-Mail, keine Common-Password.
`display_name`: optional, ≤ 60 Zeichen. Antwort: `202` `{ "message": "…" }`.

**Login** — Request `{ "email": "...", "password": "..." }` → `200`:
```json
{
  "access_token": "…",
  "access_expires_in": 900,
  "refresh_token": "…",
  "refresh_expires_in": 2592000,
  "token_type": "Bearer",
  "user": { … User … }
}
```

**Refresh** — Request `{ "refresh_token": "…" }` → `200` (gleiche Form wie Login).

**Email verify** — Request `{ "token": "…" }` → `200 { "user": { … } }`.
Den Token liefert der Link in der Mail (`/verify-email?token=…`); die App kann
ihn aus dem Universal-Link extrahieren und hier posten, oder den User den
Web-Link öffnen lassen.

**Handle setzen** — Request `{ "public_handle": "armin_gravel" }`.
Regeln: `^[a-z0-9_]{3,30}$`, kein führender `_`, keine `__`, keine reservierten
Wörter. `409 handle_locked`, wenn bereits gesetzt; `409 handle_taken`, wenn
vergeben.

### 5.2 Routen (eigene Bibliothek)

| Methode | Pfad | Auth | Zweck |
|---------|------|------|-------|
| POST | `/routes` | Bearer+Verified | Route hochladen / neue Version |
| GET | `/routes` | Bearer | Eigene Routen (Paging) |
| GET | `/routes/{id}` | Bearer | Eigene Route |
| PATCH | `/routes/{id}` | Bearer | Metadaten ändern |
| DELETE | `/routes/{id}` | Bearer | Soft-Delete (204) |
| GET | `/routes/{id}/payload` | Bearer | Geometrie-Datei (GPX/GeoJSON), `?version=N` |
| POST | `/routes/{id}/shares` | Bearer | Share-Link erzeugen |
| GET | `/routes/{id}/shares` | Bearer | Share-Liste |
| DELETE | `/routes/{id}/shares/{shareId}` | Bearer | Share widerrufen (204) |
| GET | `/share/{token}` | — | Öffentliche Route über Share-Token |

**Upload** akzeptiert zwei Varianten:

*JSON + Base64:*
```json
{
  "title": "Kraichgau Gravel Loop",
  "visibility": "public",
  "source": "app",
  "tags": ["gravel"],
  "client_route_uuid": "optional-uuid-v4",
  "payload_base64": "<base64 von GPX oder GeoJSON>"
}
```

*Multipart:* Felder `title`, `visibility`, `source`, `tags`, `client_route_uuid`
+ Datei-Feld `payload` (GPX oder GeoJSON LineString).

Antwort `201` (neu) bzw. `200` (neue Version bei gleichem `client_route_uuid`):
```json
{ "route": { … Route … }, "version": 1, "action": "created" }
```
`version` ist die Versionsnummer (Integer); `action` ist `"created"` oder
`"added_version"`.
Idempotenz: gleicher `client_route_uuid` desselben Users ⇒ neue **Version**
statt Duplikat. Geometrie wird serverseitig geparst (Distanz/Höhenmeter/BBox);
ungültige Geometrie ⇒ `422`.

#### Route-Sync & Surface-Scores (iOS)

Für den Sync aufgezeichneter Fahrten gilt:

- **Stabiler `client_route_uuid`:** Die App vergibt pro Fahrt **einmalig** eine
  UUID v4 und schickt sie bei jedem Upload mit. Damit ist der Upload
  idempotent: erneutes Hochladen derselben Fahrt erzeugt eine neue **Version**
  (`action: "added_version"`, `version` zählt hoch), keine zweite Route. Title,
  Description und Visibility werden beim Re-Upload mit aktualisiert; ältere
  Versionen bleiben über `GET /routes/{id}/payload?version=N` abrufbar.
- **`source: "app"`** kennzeichnet in der App aufgezeichnete Routen.
- **Surface-Scores bleiben erhalten (byte-genauer Roundtrip):** Der Server
  speichert den hochgeladenen Payload **unverändert** und parst ihn nur, um
  Stats (Distanz/Höhenmeter/BBox/Centroid) abzuleiten. Die GPX-Extension
  `<ge:surfaceScore>` (Namespace `https://gravelexplorer.benx.de/gpx/v1`) wird
  nicht angetastet und kommt über `GET /routes/{id}/payload` Byte für Byte
  zurück. Die App kann ihre Score-Daten also verlustfrei zurücklesen.
- **Soft-Delete:** Nach einem `DELETE /routes/{id}` ist derselbe
  `client_route_uuid` verbrannt — ein erneuter Upload mit dieser UUID wird
  abgelehnt (`422`). Für ein erneutes Hochladen eine **neue** UUID vergeben.
- **Upload-Limit:** `POST /routes` akzeptiert bis 25 MB (`REQUEST_MAX_UPLOAD_BYTES`);
  darüber `413 payload_too_large`. Bei JSON+Base64 zählt die dekodierte Größe.
- **Voraussetzung:** verifizierte E-Mail (sonst `403 email_not_verified`).

### 5.3 Discovery (öffentlich)

| Methode | Pfad | Auth | Query |
|---------|------|------|-------|
| GET | `/discover/routes` | (Bearer) | `limit, offset, sort, q, tag[], bbox` |
| GET | `/discover/users` | (Bearer) | `limit, offset, q` |

`sort` ∈ `newest | oldest | distance_asc | distance_desc`.
`bbox` = `minLat,minLon,maxLat,maxLon`. Nur `public`-Routen; mit Bearer werden
geblockte User herausgefiltert. Antwort: `{ "routes": [...], "pagination": {...} }`
bzw. `{ "users": [...], "pagination": {...} }`.

### 5.4 Profile (öffentlich)

| Methode | Pfad | Auth | Zweck |
|---------|------|------|-------|
| GET | `/users/by-handle/{handle}` | (Bearer) | Öffentliches Profil |
| GET | `/users/by-handle/{handle}/routes` | (Bearer) | Öffentliche Routen des Users |

`404`, wenn der Handle nicht existiert **oder** eine Block-Beziehung besteht
(kein 403 — Block ist nicht aus dem Status-Code ablesbar).

### 5.5 Follow / Block

| Methode | Pfad | Auth | Antwort |
|---------|------|------|---------|
| POST | `/users/by-handle/{handle}/follow` | Bearer | `201` neu / `200` schon gefolgt |
| DELETE | `/users/by-handle/{handle}/follow` | Bearer | `204` |
| POST | `/users/by-handle/{handle}/block` | Bearer | `201` / `200` |
| DELETE | `/users/by-handle/{handle}/block` | Bearer | `204` |
| GET | `/users/me/follows` | Bearer | `{ "users": [...] }` |
| GET | `/users/me/followers` | Bearer | `{ "users": [...] }` |
| GET | `/users/me/blocks` | Bearer | `{ "users": [...] }` |

### 5.6 Feed

| Methode | Pfad | Auth | Zweck |
|---------|------|------|-------|
| GET | `/feed` | Bearer | Öffentliche Routen gefolgter User, neueste zuerst |

`{ "routes": [...], "pagination": {...} }`. Query: `limit`, `offset`.

### 5.7 Likes (M4)

| Methode | Pfad | Auth | Zweck |
|---------|------|------|-------|
| POST | `/routes/{id}/like` | Bearer | Liken (idempotent) |
| DELETE | `/routes/{id}/like` | Bearer | Unlike |
| GET | `/routes/{id}/likes` | (Bearer) | Summary |

Summary:
```json
{ "count": 12, "liked_by_viewer": true, "recent": ["bob", "lena"] }
```
Nicht sichtbare/blockierte Route ⇒ `404`.

### 5.8 Kommentare (M4)

| Methode | Pfad | Auth | Zweck |
|---------|------|------|-------|
| GET | `/routes/{id}/comments` | (Bearer) | Liste (Paging) |
| POST | `/routes/{id}/comments` | Bearer+Verified | Anlegen (Rate-Limit 30/Fenster) |
| DELETE | `/routes/{id}/comments/{cid}` | Bearer | Löschen (Autor oder Routen-Owner) |

POST-Request `{ "body": "Schöne Runde!" }` (1–2000 Zeichen). Antwort `201`:
```json
{ "comment": { "id": 7, "body": "Schöne Runde!", "created_at": "…Z",
  "author": { "handle": "bob", "display_name": "Bob" }, "can_delete": true } }
```
Liste: `{ "comments": [ … ], "pagination": { … } }`.

### 5.9 Notifications (M4)

| Methode | Pfad | Auth | Zweck |
|---------|------|------|-------|
| GET | `/notifications` | Bearer | Liste (Paging) |
| GET | `/notifications/unread-count` | Bearer | `{ "count": 3 }` |
| POST | `/notifications/read` | Bearer | Alle (oder `{ "ids":[…] }`) als gelesen |
| POST | `/notifications/{nid}/read` | Bearer | Eine als gelesen (204) |

Item:
```json
{
  "id": 42, "type": "like", "created_at": "…Z", "read": false,
  "actor": { "handle": "bob", "display_name": "Bob" },
  "route": { "id": "uuid", "title": "Kraichgau Loop" }
}
```
`type` ∈ `follow | like | comment`. `route` ist `null` bei `follow` (oder
gelöschter Route).

### 5.10 Avatare (M4)

| Methode | Pfad | Auth | Zweck |
|---------|------|------|-------|
| POST | `/users/me/avatar` | Bearer+Verified | Upload (`multipart`, Feld `avatar`) |
| DELETE | `/users/me/avatar` | Bearer | Entfernen (204) |
| GET | `/u/{handle}/avatar` | — | Bild ausliefern (immer 200, sonst Platzhalter-PNG) |

Upload ist **POST** (nicht PUT — PHP parst `multipart` nur bei POST). Erlaubt:
JPEG/PNG/WebP, serverseitig auf ≤ 512 px skaliert. Antwort
`{ "ok": true, "avatar_path": "…" }`. Zu groß/falscher Typ ⇒ `422`.
Das Serving liefert immer ein Bild (auch für unbekannte Handles), nutzbar
direkt als `URL` einer `AsyncImage`.

### 5.11 Strava-Integration (M4)

| Methode | Pfad | Auth | Zweck |
|---------|------|------|-------|
| GET | `/integrations/strava` | Bearer | Verbindungsstatus |
| POST | `/integrations/strava/import` | Bearer+Verified | Aktivitäten importieren |
| DELETE | `/integrations/strava` | Bearer | Verbindung trennen (204) |

Der **OAuth-Connect läuft über die Web-UI** (`/auth/strava/connect` →
Strava → Callback), weil er an die Browser-Session gebunden ist. Die native App
öffnet dazu die Web-Seite (z. B. `ASWebAuthenticationSession`).

Status: `{ "connected": true, "athlete_id": "99000001", "scope": "read,activity:read", "connected_at": "…Z" }`.
Import: `{ "imported": 3, "skipped": 1, "total": 4 }` (idempotent — re-import legt keine Duplikate an).

### 5.12 Heatmap (M4)

| Methode | Pfad | Auth | Query |
|---------|------|------|-------|
| GET | `/heatmap` | — | `bbox=minLon,minLat,maxLon,maxLat` (optional) |

Liefert eine **GeoJSON-FeatureCollection** (Punkt-Features mit `weight`):
```json
{
  "type": "FeatureCollection",
  "features": [
    { "type": "Feature",
      "geometry": { "type": "Point", "coordinates": [8.5, 49.5] },
      "properties": { "weight": 12 } }
  ],
  "meta": { "grid": 0.05, "cell_count": 1, "max_weight": 12 }
}
```
Koordinaten in GeoJSON-Reihenfolge **[lon, lat]**. Ungültige `bbox` ⇒ `422`.

### 5.12b Heatmap-Streckenlinien (M6)

| Methode | Pfad | Auth | Query |
|---------|------|------|-------|
| GET | `/heatmap/lines` | — | `bbox=minLon,minLat,maxLon,maxLat` (empfohlen) |

Aufs OSM-Straßennetz **gematchte** Wegstücke aus öffentlichen Routen
(Valhalla-Map-Matching, vorberechnet via `cron:heatmap-lines`). Pro Wegstück:
`count` (wie viele Routen es nutzen) und `avg_score` (Ø Crowd-Surface 0..5,
`null` wenn keine Scores). Aggregation ist **richtungsunabhängig** (Hin-/Rück
fallen zusammen). `bbox` filtert auf den Viewport (überlappende Kanten).

```json
{
  "type": "FeatureCollection",
  "features": [
    { "type": "Feature",
      "geometry": { "type": "LineString", "coordinates": [[8.50, 49.50], [8.51, 49.51]] },
      "properties": { "count": 3, "avg_score": 2.5, "length_m": 120, "surface": "gravel" } }
  ],
  "meta": { "edge_count": 1, "max_count": 3 }
}
```

`count` eignet sich für die Linienstärke, `avg_score` (0 = glatt … 5 = grob)
für die Farbe; `surface` ist ein OSM/Valhalla-Fallback. Ungültige `bbox` ⇒ `422`.

### 5.13 Healthcheck

| Methode | Pfad | Auth | Zweck |
|---------|------|------|-------|
| GET | `/healthz` | — | Liveness (nicht unter `/api/v1`) |

### 5.14 Game (Stufe 1 — Territorialspiel)

- `GET /api/v1/game/edges?bbox=minLon,minLat,maxLon,maxLat[&mine=1]` — eingefärbte Kanten im Ausschnitt (OptionalBearer). Antwort: `{ "edges": [ { id, geom, owner, owner_is_me, value, freshness, distinct_riders_total, surface_character } ] }`.
- `GET /api/v1/game/edges/{id}` — Detail inkl. `value` (total/pioneer/popularity/curation) + `pioneer_cohort` (≤10).
- `GET /api/v1/game/me` — eigene Statistik (gehaltene Kanten, Erstbefahrungen, gehaltene Länge). Bearer.
- `GET /api/v1/game/config` — aktuelle `game_config`-Werte. Bearer.
- `GET /api/v1/game/leaderboard?scope&window&metric` — Solo-/Spieler-Rangliste (S7, **OptionalBearer**). `scope` = `world` (anonym) \| `friends` (Bearer-Pflicht → sonst `401`; Follow-Graph inkl. self). `window` = `week` (7 T) \| `season` (90 T, Default) \| `all`. `metric` = `area` (Default, gehaltene Länge in m als Top-90-Tage-Präsenz-Beitragender) \| `pioneer` (Anzahl Kanten in der Erstbefahrer-Kohorte ≤10) \| `value` (Σ Kantenwert der gehaltenen Kanten) \| `distance` (gefahrene Distanz im Fenster). Antwort `{ "entries": [ { rank, handle, value, is_me } ], "me": { rank, value } | null }`. `entries` value-absteigend, Rang fortlaufend (Top 100), Tie deterministisch nach `user_id`. `me` auch außerhalb der Top-N (null, wenn ausgeloggt/ohne Daten). `area`/`value` sind 90-Tage-rollierend (Präsenz); `all` wird dafür wie `season` behandelt. Invalidierte Pässe ausgeschlossen. Reine Lese-Aggregation. (Region-Scope folgt später als `scope=region&bbox=…`.)
- `POST /api/v1/game/ingest/{route_id}` — Re-Run der Ingestion (idempotent), nur Owner. `{route_id}` ist die **öffentliche Route-ID (UUID)**, konsistent mit `GET /routes/{id}` (intern-numerische ID wird rückwärtskompatibel ebenfalls akzeptiert). Antwort: Match-/Pass-/Skip-Zähler.

Das `owner`-Objekt der Kanten enthält `{ claimant_id, type, handle, name }`. `type` ∈ `rider | group`. Für `rider` ist `handle` = `public_handle`, `name` = Anzeigename; für `group` (Crew, Stufe 2) ist `handle` = Crew-Slug, `name` = Crew-Name. `name` ist **additiv** und kann `null` sein.

### 5.15 Game (Stufe 2 — Crews)

Crews sind neutrale Gruppen (Claimant-Typ `group`). Tritt ein Fahrer einer Crew bei, zählt seine Präsenz (im 90-Tage-Fenster) ab sofort für die Crew (effektiver Claimant) — Austritt fällt zurück auf den Solo-Fahrer. Genau eine Crew pro Fahrer. Alle Endpunkte: Bearer.

- `POST /api/v1/game/crews` — `{ "name": "..." }` → legt Crew an, Ersteller wird `captain`. Eine evtl. bestehende Mitgliedschaft wird zuvor verlassen (Captain-Regel gilt). Antwort `201`: Crew-Objekt inkl. `join_code`.
- `GET /api/v1/game/crews/{slug}` — öffentliches Crew-Profil (Name, Slug, `member_count`, gehaltene Kanten/Länge, Captain) ohne `join_code`.
- `POST /api/v1/game/crews/join` — `{ "join_code": "ABCD2345" }` → beitreten (verlässt alte Crew zuerst). `crew_max_members` (0 = unbegrenzt) wird geprüft → `409 crew_full`.
- `POST /api/v1/game/crews/leave` — aktuelle Crew verlassen. Antwort `{ left, dissolved }`. Captain mit verbleibenden Mitgliedern → `409 captain_must_transfer`. Captain als letztes Mitglied → Crew wird aufgelöst.
- `POST /api/v1/game/crews/transfer` — `{ "user_id": 123 }` → Captain überträgt die Captain-Rolle an ein Mitglied (nur Captain). Kein Recompute.
- `GET /api/v1/game/crews/me` — `{ "crew": {…}|null }`. Eigene Crew inkl. Mitglieder; `join_code` nur, wenn man Captain ist.
- `GET /api/v1/game/crews/{slug}/leaderboard` — Rangliste, **nur Crew-Mitglieder** (sonst `403`). Antwort `{ "members": [ { handle, role, presence_contribution, held_edges, held_length_m, activity_distance_m, activity_rides } ] }`. `presence_contribution` = Σ 90-Tage-Präsenz des Mitglieds auf crew-eigenen Kanten; `held_*` = Kanten, auf denen das Mitglied größter Präsenz-Beitragender ist (deterministischer Tie-Break); `activity_*` = Fahrten/Distanz im 90-Tage-Fenster (besitzunabhängig). Invalidierte Pässe ausgeschlossen. Reine Lese-Aggregation.

Mitgliedschaftsänderungen (create/join/leave) rechnen die betroffenen Fenster-Kanten des Users synchron neu. Gruppenfahrt-Bonus: fahren ≥ `group_ride_min_members` verschiedene Mitglieder dieselbe Kante am selben Tag, wird der Crew-Tagesbeitrag mit `group_ride_bonus` multipliziert.

Ingestion läuft automatisch nicht-blockierend nach jedem Route-Upload. Voller Recompute: `php public/index.php game:recompute`.

**Admin-Dashboard:** `/admin/*` ist NUR unter `admin.grava.world` erreichbar (auf der Hauptdomain → 404); die `/api/v1/game/*`-Endpunkte sind davon unverändert. Hinweis: Spielwerte (Besitzer/Wert/Frische) können sich durch Admin-Aktionen (Pass-Invalidierung, Ban, Recompute) **rückwirkend** ändern — Clients sollten gecachte Kanten nicht als unveränderlich behandeln. Setup: siehe `backend/GAME_DASHBOARD_SETUP.md`.

---

## 6. iOS-Praxis

- **Bearer-Header** bei jedem geschützten Call setzen; bei `401` einmal
  `POST /auth/refresh` versuchen, neue Tokens speichern, Call wiederholen.
  Schlägt der Refresh fehl ⇒ ausloggen (Token-Status zurücksetzen).
- **Tokens in der Keychain** ablegen.
- **`429`**: `Retry-After`-Header respektieren (Backoff).
- **Geometrie**: Upload als GPX oder GeoJSON-LineString; Download über
  `/routes/{id}/payload` (Content-Type `application/gpx+xml` bzw.
  `application/geo+json`).
- **Decoding**: alle Zeitstempel sind ISO-8601 UTC → `ISO8601DateFormatter`
  mit `.withInternetDateTime`. `id`-Felder sind UUID-Strings.
- **Universal Links** für `verify-email` / `reset-password` einrichten, damit
  Mail-Links die App öffnen können (alternativ Web-Seiten nutzen).

---

## 7. Stand

- Implementiert & getestet: Milestones 1–4 (Auth, Routen, Discovery, Social,
  Feed, Likes, Kommentare, Notifications, Avatare, Strava-Import, Heatmap).
- Test-Suite: `composer test` (PHPUnit). Lokales Setup: siehe `README.md`.
- Diese Datei spiegelt das Routing in `public/index.php` wider; bei Abweichungen
  gilt der Code.
