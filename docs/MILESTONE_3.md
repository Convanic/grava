# Milestone 3 — Discovery & Social

> **Stand 2026-06-17 — M3 abgeschlossen.**
> Alle 8 Phasen (0–7) auf `main` gemerged, 34/34 §10-Smoke-Schritte
> grün gegen MAMP-Vhost (Anonymous-Discovery, Profile, Follow-Cascade,
> Bidirektionaler Block-404, Handle-One-Time-Lock, BBox-/Tag-Filter,
> Web-UI-Login + Feed). Ausgangspunkt war `main` nach M2 (50/50
> Smoke grün).

## 1. Ziel

Public Routen werden öffentlich findbar (Discovery) und User bekommen
ein eigenes öffentliches Profil mit Follow-Beziehungen. Damit wird
aus dem privaten Routen-Tagebuch eine Social-Plattform — ohne dass
private/unlisted Daten leaken.

Konkret:

- Anonyme Besucher können `visibility=public` Routen browsen,
  filtern (BBox, Tags, Distanz) und einzelne Routen ansehen.
- Jeder User bekommt eine eigene `/u/{handle}`-Profil-Seite mit
  Stats und Liste seiner public Routen.
- User können sich gegenseitig folgen (asymmetrisch, ohne
  Approval). Wer einen anderen User blockt, verschwindet
  beidseitig aus Sicht und Suche.
- Eingeloggte User haben einen Activity-Feed, der nur neue public
  Routen ihrer gefolgten User chronologisch zeigt.

## 2. Anforderungen

- **Privacy-Garantien aus M2 bleiben absolut:**
  Eine `private` Route muss durch keinen Discovery-/Profile-/Feed-
  Endpoint sichtbar werden. Eine `unlisted` Route darf nur über
  ihren Share-Token, nie über Discovery, abrufbar sein.
- **Anonyme Lese-Performance:** Discovery-Endpoints sind public,
  müssen also gegen Bot-Crawling stabil bleiben. Rate-Limit pro
  IP, ähnlich `RATE_LOGIN_MAX`.
- **Block-Semantik bidirektional:**
  Wenn A B blockt, dann sieht weder A B noch umgekehrt — egal
  wer blockiert hat. Bestehende Follow-Beziehungen werden in
  beide Richtungen automatisch entfernt.
- **Idempotente Schreib-APIs:**
  `POST /follow` zweimal hintereinander = ein Follow. Ebenso
  `POST /block`.
- **Cleanup beim User-Soft-Delete:**
  M1 scrubbt Email, M2 hat Routen mit-soft-deletet. M3 ergänzt:
  alle `follows`- und `user_blocks`-Zeilen des Users werden
  hart entfernt (kein „du folgst einem deaktivierten Konto"-
  Müll).
- **Public Handle ist sichtbar, aber optional:**
  Kein automatisch generierter Handle. User wählt einen, sobald
  er sein Profil veröffentlichen will. Bestehende User behalten
  `public_handle = NULL` und tauchen erst nach explizitem Setzen
  in Discovery/Profilen auf.

## 3. Architektur-Entscheidungen (M3-Kickoff)

| # | Entscheidung |
|---|---|
| 1 | **Public Handle:** separates `public_handle`-Feld auf `users`. UNIQUE, NULL erlaubt, regex `^[a-z0-9_]{3,30}$`. Profile-URL `/u/{handle}`, API `/api/v1/users/by-handle/{handle}`. |
| 2 | **Discovery-Scope:** ausschließlich `visibility=public`. `unlisted` bleibt strikt link-only, wie in M2 §11/5 zementiert. |
| 3 | **Follow-Modell:** asymmetrisch + Block-Liste (Strava/GitHub-Stil). Kein Approval-Workflow. |
| 4 | **Anonymous Discovery:** ja. Alle GET-Endpoints unter `/discover/*` und `/users/by-handle/*` und `/u/{handle}` sind ohne Login zugänglich. |
| 5 | **Activity Feed:** nur „neue public Route eines gefolgten Users", chronologisch absteigend. Keine Follow/Like/Comment-Events. |
| 6 | **Spatial-Filter:** BBox optional in Discovery, Default = ganze Welt. Pflicht-Pagination mit `limit`/`offset` (max 50/Request). |

## 4. Schema-Erweiterungen

### 4.1 `users.public_handle`

```sql
ALTER TABLE users
  ADD COLUMN public_handle VARCHAR(30) NULL UNIQUE
    AFTER display_name;
```

- Format: `^[a-z0-9_]{3,30}$` (Validator-seitig erzwungen, DB
  speichert wie-eingegeben).
- NULL = User hat noch keinen Handle gesetzt → erscheint nicht in
  Discovery oder unter `/u/...`. Routen dieses Users sind über
  Share-Links/Listings im Owner-UI weiterhin erreichbar.
- Reservierte Handles (im Validator gepinnt, nicht in DB):
  `admin`, `api`, `auth`, `dashboard`, `discover`, `feed`,
  `login`, `logout`, `me`, `register`, `routes`, `settings`,
  `share`, `support`, `system`, `u`, `users`. Schützt vor
  URL-Konflikten und „adminuser1234"-Squatting.
- One-Time-Set: nach erstem Speichern kann der Handle nicht mehr
  geändert werden (Phase 6 zeigt im Settings-UI nur einen
  Read-Only-Disclaimer „Handle ist endgültig"). Argument: ein
  fester Handle ist eine stabile Profile-URL — keine Link-Rot-
  Friktion. Wenn User wirklich ändern wollen, müssen sie via
  Support-Mail (manuell). Lieber konservativ starten.

### 4.2 `follows`

```sql
CREATE TABLE follows (
  follower_id BIGINT       NOT NULL,
  followee_id BIGINT       NOT NULL,
  created_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (follower_id, followee_id),
  CONSTRAINT fk_follows_follower
    FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_follows_followee
    FOREIGN KEY (followee_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_follows_followee_created (followee_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- PK `(follower_id, followee_id)` macht den Follow idempotent.
- Sekundär-Index auf `(followee_id, created_at)` ist der
  Follower-Listing-Pfad (zeig mir alle, die mir folgen).
- Self-Follow ist **DB-seitig** nicht verboten (kein CHECK
  Constraint mit Subqueries in MySQL); der Service-Layer
  weist es zurück.

### 4.3 `user_blocks`

```sql
CREATE TABLE user_blocks (
  blocker_id BIGINT      NOT NULL,
  blocked_id BIGINT      NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (blocker_id, blocked_id),
  CONSTRAINT fk_blocks_blocker
    FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_blocks_blocked
    FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE,
  KEY idx_blocks_blocked (blocked_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- Asymmetrisches DB-Modell, bidirektionales Verhalten in der
  Service-Schicht: Block-Test bei Discovery/Profile/Feed prüft
  immer beide Richtungen (`blocker_id = me OR blocked_id = me`).
- Beim Anlegen eines Blocks räumt der Service automatisch:
  - `DELETE FROM follows WHERE (follower_id=A AND followee_id=B) OR (follower_id=B AND followee_id=A)`

### 4.4 Discovery-Index auf `routes`

Der vorhandene `SPATIAL INDEX(centroid)` aus M2 deckt die BBox-
Suche ab. Zusätzlich legen wir einen kombinierten Index für die
Standard-Discovery-Query an:

```sql
CREATE INDEX idx_routes_public_discovery
  ON routes (visibility, deleted_at, created_at);
```

Damit deckt MySQL den Filter `visibility='public' AND deleted_at IS NULL ORDER BY created_at DESC` direkt aus dem Index.

## 5. API

```
# Discovery (anonym OK)
GET  /api/v1/discover/routes
GET  /api/v1/discover/users
GET  /api/v1/users/by-handle/{handle}
GET  /api/v1/users/by-handle/{handle}/routes

# Eigenes Profil-Setting (auth + verified)
PATCH /api/v1/users/me/handle      { "public_handle": "..." }

# Follow (auth)
POST   /api/v1/users/by-handle/{handle}/follow
DELETE /api/v1/users/by-handle/{handle}/follow
GET    /api/v1/users/me/follows                 # ich folge
GET    /api/v1/users/me/followers               # mir folgen

# Block (auth)
POST   /api/v1/users/by-handle/{handle}/block
DELETE /api/v1/users/by-handle/{handle}/block
GET    /api/v1/users/me/blocks

# Activity Feed (auth)
GET    /api/v1/feed
```

### 5.1 GET /discover/routes

Query-Parameter, alle optional:

- `bbox=minLat,minLon,maxLat,maxLon` (4 floats, comma-sep)
- `tag=foo` (mehrfach erlaubt: `?tag=gravel&tag=alps`, AND-verknüpft)
- `min_distance_km`, `max_distance_km` (float)
- `q=...` (case-insensitive substring auf title)
- `sort=newest|oldest|distance_asc|distance_desc` (Default `newest`)
- `limit` (1..50, Default 20), `offset` (≥0, Default 0)

Server-side enforced:
- `visibility = 'public'`, `deleted_at IS NULL`
- Wenn Viewer eingeloggt: blockierte User werden ausgefiltert
  (`r.user_id NOT IN (...)`)

Response:

```json
{
  "routes": [
    {
      "id": "...", "title": "...", "description": "...",
      "distance_meters": 23456, "elevation_gain_meters": 412,
      "bbox": {...}, "centroid": {...},
      "tags": ["gravel"], "created_at": "...",
      "owner": { "handle": "...", "display_name": "..." }
    }
  ],
  "pagination": { "limit": 20, "offset": 0, "has_more": true }
}
```

### 5.2 GET /discover/users

Query:
- `q=...` (case-insensitive auf `public_handle` oder `display_name`)
- `limit` (1..50, Default 20), `offset`

Liefert `{users: [{handle, display_name, route_count_public, ...}]}`. Nur User mit gesetztem `public_handle`.

### 5.3 GET /users/by-handle/{handle}

Liefert das Public-Profil:

```json
{
  "user": {
    "handle": "...",
    "display_name": "...",
    "joined_at": "...",
    "route_count_public": 14,
    "follower_count": 23,
    "following_count": 7,
    "is_followed_by_viewer": true,
    "is_blocked_by_viewer": false,
    "has_blocked_viewer": false
  }
}
```

`is_blocked_by_viewer` und `has_blocked_viewer`: wenn entweder
true, geben wir 404 statt der echten User-Daten zurück, **bevor**
diese Felder befüllt werden — kein Profil-Probing.

### 5.4 GET /feed

Auth-required. Liefert public Routen meiner gefolgten User, neueste
zuerst:

```sql
SELECT r.* FROM routes r
JOIN follows f ON f.followee_id = r.user_id
WHERE f.follower_id = :me
  AND r.visibility = 'public'
  AND r.deleted_at IS NULL
  AND r.user_id NOT IN (SELECT blocked_id FROM user_blocks WHERE blocker_id = :me)
  AND r.user_id NOT IN (SELECT blocker_id FROM user_blocks WHERE blocked_id = :me)
ORDER BY r.created_at DESC
LIMIT 50
```

Pagination via `?limit=&offset=`.

## 6. Web-UI

```
GET  /discover                  Public, Routen-Liste mit Filter-Form
GET  /discover/users            Public, User-Liste
GET  /u/{handle}                Public, Profil-Page (Stats + Routes)
GET  /u/{handle}/r/{routeId}    Public, Route-Detail (Read-only)
GET  /feed                      Auth, Activity-Feed
GET  /settings/handle           Auth, Handle setzen (one-time)
POST /settings/handle           Auth, CSRF
POST /u/{handle}/follow         Auth, CSRF, redirected zurück
POST /u/{handle}/unfollow       Auth, CSRF
POST /u/{handle}/block          Auth, CSRF
POST /u/{handle}/unblock        Auth, CSRF
```

Layout-Erweiterung: authed Nav bekommt zusätzlich `Discover` und
`Feed` zwischen Routen und Logout.

## 7. Phasen-Plan

| Phase | Inhalt | Aufwand | Abhängigkeiten |
|---|---|---|---|
| 0 | `users.public_handle` Migration + Validator + `PATCH /users/me/handle` API + `/settings/handle` Web-Form | 0.75 PT | M2 |
| 1 | Migration `0005_m3_social.sql` (`follows`, `user_blocks`, Discovery-Index) + `AuthService::deleteAccount`-Erweiterung | 0.25 PT | Phase 0 |
| 2 | `DiscoveryService` + `GET /discover/routes` + `GET /discover/users` (anonym OK) | 1.0 PT | Phase 1 |
| 3 | `ProfileService` + `GET /users/by-handle/{handle}` + `/{handle}/routes` (inkl. Viewer-Flags) | 0.75 PT | Phase 2 |
| 4 | `FollowService` + `BlockService` + 6 Follow-/Block-API-Endpoints (mit Cascade-Cleanup im Block) | 1.0 PT | Phase 3 |
| 5 | `FeedService` + `GET /feed` (mit Block-Filter) | 0.5 PT | Phase 4 |
| 6 | Web-UI: `/discover`, `/discover/users`, `/u/{handle}`, `/u/{handle}/r/{id}`, `/feed`, `/settings/handle`, Follow-/Block-Forms | 1.5 PT | Phase 0–5 |
| 7 | §10-Smoke (siehe unten) — 34/34 grün ✓ | 0.5 PT | alle |
| **Gesamt** | | **~6 PT** | |

## 8. Smoke-Plan (entspricht M2 §10)

Status: **34/34 grün** (22 §10-Akzeptanzkriterien plus Sub-Asserts) gegen MAMP-Vhost (`https://gravelexplorer.test:8890`):

1. Anonymous: `GET /discover/routes` → 200, leer (noch keine public)
2. User A erstellt + verifiziert + setzt handle `alice`; User B ebenso `bob`; User C ohne handle (NULL bleibt)
3. A lädt eine Route hoch, setzt sie auf `visibility=public`
4. Anonymous: `GET /discover/routes` → enthält A's Route mit `owner.handle=alice`
5. A lädt eine zweite Route hoch, lässt sie `private` → erscheint **nicht** in Discovery
6. Anonymous: `GET /u/alice` (web) → 200, zeigt A's eine public Route
7. Anonymous: `GET /u/charlie` (kein Handle gesetzt) → 404
8. B folgt A: `POST /users/by-handle/alice/follow` → 201, `is_followed_by_viewer=true` bei `GET /u/alice`
9. B's `GET /feed` → enthält A's eine public Route
10. A blockt B: `POST /users/by-handle/bob/block` → 201
11. B's `GET /feed` → leer (Block schmeißt A raus)
12. B's `GET /u/alice` → 404 (Block bidirektional sichtbar)
13. A's `GET /u/bob` → 404 (auch andersrum, obwohl A der Blocker ist)
14. `follows`-Zeile A→B und B→A (falls vorhanden) wurde durch den Block-Cascade entfernt
15. A unblockt B → A's `GET /u/bob` → 200 wieder
16. C versucht `PATCH /users/me/handle` mit `admin` → 422 (reserviertes Wort)
17. C versucht mit `Charlie123` → 422 (uppercase)
18. C setzt `charlie` → 200; danach versucht erneut `charliv` → 409 (one-time set)
19. Discovery `?bbox=49.0,8.0,50.0,9.0` filtert räumlich
20. Discovery `?tag=gravel` filtert nach Tag
21. Web `/discover` zeigt Routen-Cards, Filter-Form funktioniert ohne Login
22. Web `/feed` zeigt nach Login die Feed-Page

## 9. Was M3 NICHT macht

- **Keine Likes/Reactions/Kommentare** — Social-Layer bleibt
  read-only Discovery + Follow. Kommentar-System ist M4.
- **Keine Notifications** — kein „X folgt dir jetzt"-Inbox.
  Eine spätere Inbox kann auf `follows.created_at` aufsitzen.
- **Keine Strava-/Komoot-Imports** — separates Milestone, eigener
  OAuth-Flow.
- **Keine Crowd-Heatmap** — die Track-Punkt-Aggregation über
  alle public Routen kommt erst, wenn Discovery + Profile als
  Stabilitäts-Anker stehen.
- **Kein Bild-Upload für Profile** — Avatar-System ist klein
  genug für M4, lenkt hier vom Kern-Feature ab.
