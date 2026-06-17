# Milestone 4 — Engagement, Media & Integrations

> **Stand 2026-06-17 — M4 Kickoff.**
> Ausgangspunkt ist `main` nach M3 (34/34 §10-Smoke grün, alle 8
> M3-Phasen gemerged). M4 nimmt alle sechs in M3 §9 zurückgestellten
> Punkte und baut darauf den Interaktions-, Medien- und
> Integrations-Layer.

## 1. Ziel

Aus der read-only Discovery-/Follow-Plattform (M3) wird eine
interaktive Community-App: User können Routen liken und
kommentieren, bekommen Notifications, ein Profilbild, können Touren
aus Strava importieren, und es gibt eine Crowd-Heatmap über alle
public Routen.

Sechs Sub-Milestones, jeweils mit eigenem Branch-/Phasen-/Smoke-Zyklus
(wie M2/M3):

| Sub | Feature | Kürzel |
|---|---|---|
| A | Likes / Reactions auf Routen | M4a |
| B | Kommentare auf Routen | M4b |
| C | Notifications-Inbox | M4c |
| D | Avatar-Upload fürs Profil | M4d |
| E | Strava-Import (OAuth) | M4e |
| F | Crowd-Heatmap | M4f |

## 2. Übergreifende Anforderungen (gelten für alle Sub-Milestones)

- **M2/M3-Privacy bleibt absolut.** Likes/Comments/Notifications
  dürfen nur auf **sichtbaren** Routen entstehen: `public` für
  fremde Viewer, eigene Routen jeder Visibility für den Owner.
  `private`/`unlisted` einer fremden Route bleiben unerreichbar.
- **Block-Awareness überall.** Jede neue Interaktion respektiert
  bidirektionale Blocks (`DiscoveryService::blockedUserIds`):
  geblockte User können nicht liken/kommentieren, erzeugen keine
  Notifications und tauchen in keiner Liste auf.
- **Idempotenz.** Like ist ein Toggle/Set, kein Counter-Inkrement.
  Doppel-POST = ein Like. OAuth-State ist single-use.
- **Soft-Delete-Cleanup.** `AuthService::deleteAccount` wird in
  jedem Sub-Milestone erweitert: neue Beziehungs-/Event-Zeilen des
  Users werden hart entfernt (kein Geister-Like/-Comment/-Notif).
- **Konsistente API-Fehler.** `SocialException`-Muster aus M3
  wiederverwenden (errorCode + httpStatus). Neue Codes je Feature
  dokumentiert.
- **Web + API parallel.** Jede Aktion gibt es als JSON-API
  (Bearer) und als server-rendered Web-Form (WebSession + CSRF),
  analog M2/M3.

## 3. Architektur-Entscheidungen (Defaults, im Doc fixiert)

Diese Entscheidungen sind getroffen, damit M4 ohne weitere
Rückfragen durchläuft. Jede ist reversibel per Folge-Migration.

### M4a Likes
- **D-A1:** Einfaches Like (Boolean-Toggle), **keine** Multi-Reactions
  (kein 👍/❤️/🔥). Reactions-Typen wären eine spätere `reaction_type`-
  Spalte — die Tabelle ist dafür vorbereitet (`reaction VARCHAR(16)
  NOT NULL DEFAULT 'like'`), aber API exponiert nur `like`.
- **D-A2:** Like nur auf sichtbare Routen. Like auf eigene Route ist
  erlaubt (kein Self-Like-Verbot — anders als Follow, weil ein Like
  auf die eigene Tour harmlos ist; wir verbieten es trotzdem nicht
  per DB, sondern lassen es zu und filtern es nur aus Notifications).
- **D-A3:** Denormalisierter `like_count` wird **nicht** in `routes`
  gespeichert — wir zählen per `COUNT(*)` mit Index. Bei den
  erwarteten Volumina (< 100k Likes/Route) ist das billig und spart
  Counter-Drift-Bugs.

### M4b Comments
- **D-B1:** **Flache** Kommentare (kein Threading/Replies) in M4.
  `parent_id` wird **nicht** eingeführt — Threads sind M5, falls
  überhaupt gewünscht.
- **D-B2:** Soft-Delete (`deleted_at`). Löschen darf der
  Kommentar-Autor **oder** der Routen-Owner (Moderation auf eigener
  Route). Soft-deleted Kommentare zeigen „[gelöscht]" in Threads
  mit Folge-Kommentaren — bei flach einfach raus.
- **D-B3:** Body 1–2000 Zeichen, Plaintext (kein Markdown/HTML),
  beim Rendern escaped. Rate-Limit pro User analog `RATE_*`.

### M4c Notifications
- **D-C1:** **Pull-Modell** (Polling), keine WebSockets/Push. Client
  ruft `GET /notifications` + `GET /notifications/unread-count`.
- **D-C2:** Event-Typen: `follow`, `like`, `comment`. Generiert
  **synchron** im jeweiligen Service (kein Queue/Worker in M4).
- **D-C3:** Keine Notification an sich selbst (Self-Like/Self-Comment
  auf eigene Route erzeugt keinen Eintrag). Geblockte Beziehungen
  erzeugen nie Notifications.
- **D-C4:** `read_at`-Spalte; „mark all read" + „mark one read".
  Aufbewahrung: Cleanup-Cron entfernt gelesene Notifs > 90 Tage.

### M4d Avatars
- **D-D1:** Speicherung im Filesystem unter `STORAGE_AVATARS_DIR`
  (Default `<repo>/storage/avatars/{user_id}/avatar.<ext>`), Pfad
  in `users.avatar_path`. Nicht in DB-Blob.
- **D-D2:** Erlaubte Typen JPEG/PNG/WebP, max 5 MB, serverseitig per
  `getimagesize` validiert (kein Vertrauen auf Content-Type). Auf
  max. 512×512 herunterskaliert (GD-Extension), als WebP/JPEG
  gespeichert. Falls GD fehlt: Original speichern, kein Resize.
- **D-D3:** Serving über `GET /u/{handle}/avatar` (public, mit
  Cache-Header) und Fallback auf ein generiertes Initial-Placeholder
  (kein externes Gravatar).

### M4e Strava-Import
- **D-E1:** OAuth2 Authorization-Code-Flow. Tokens
  (`access_token`, `refresh_token`) **verschlüsselt** at-rest
  (`APP_KEY`-basiert, AES-256-GCM) in `oauth_connections`.
- **D-E2:** Import zieht Strava-Activities und legt sie als Routen
  mit `source='strava'` an (GPX/Streams → GeoJSON). Idempotent über
  `client_route_uuid = 'strava:'+activity_id`.
- **D-E3:** **Dev-Seam:** Da echte Strava-Credentials nicht im
  CI/Smoke verfügbar sind, kapselt ein `StravaClient`-Interface die
  HTTP-Calls. Ein `FakeStravaClient` (fixture-basiert) erlaubt
  vollständige Smoke-Tests des Import-Pfads ohne Netz. Der Live-Pfad
  ist hinter `STRAVA_CLIENT_ID/SECRET` konfiguriert; fehlen sie,
  ist der Connect-Button deaktiviert.

### M4f Crowd-Heatmap
- **D-F1:** **Vorberechnete Grid-Aggregation** statt On-the-fly über
  alle Track-Punkte. Cron aggregiert Centroids (M4-MVP: Centroid-
  Dichte, nicht volle Track-Linien) public Routen in ein Geohash-
  ähnliches Grid (gerundete Lat/Lon-Buckets) → `heatmap_cells`.
- **D-F2:** `GET /api/v1/heatmap?bbox=...&zoom=...` liefert GeoJSON
  FeatureCollection mit `weight` pro Zelle. Nur `visibility=public`,
  block-/privacy-neutral (aggregiert, keine User-Zuordnung).
- **D-F3:** Volle Track-Linien-Heatmap (jeder GPS-Punkt) ist
  explizit M5 — M4 liefert die Centroid-Dichte als sichtbares MVP.

## 4. Schema-Erweiterungen

Migrationen fortlaufend ab `0006`. Jede gehört zu ihrem
Sub-Milestone.

```sql
-- 0006_m4a_likes.sql
CREATE TABLE route_likes (
    user_id    BIGINT UNSIGNED NOT NULL,
    route_id   BIGINT UNSIGNED NOT NULL,
    reaction   VARCHAR(16)     NOT NULL DEFAULT 'like',
    created_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (user_id, route_id),
    KEY idx_route_likes_route (route_id, created_at),
    CONSTRAINT fk_route_likes_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_route_likes_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE
);

-- 0007_m4b_comments.sql
CREATE TABLE route_comments (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    route_id   BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    body       VARCHAR(2000)   NOT NULL,
    created_at DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    deleted_at DATETIME(3)     NULL,
    PRIMARY KEY (id),
    KEY idx_comments_route (route_id, deleted_at, created_at),
    KEY idx_comments_user  (user_id),
    CONSTRAINT fk_comments_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE
);

-- 0008_m4c_notifications.sql
CREATE TABLE notifications (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,   -- Empfänger
    actor_id     BIGINT UNSIGNED NOT NULL,   -- Auslöser
    type         ENUM('follow','like','comment') NOT NULL,
    subject_type ENUM('route','user') NULL,
    subject_id   BIGINT UNSIGNED NULL,        -- route_id / comment_id
    created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    read_at      DATETIME(3)     NULL,
    PRIMARY KEY (id),
    KEY idx_notif_user_unread (user_id, read_at, created_at),
    CONSTRAINT fk_notif_user  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_notif_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 0009_m4d_avatars.sql
ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL DEFAULT NULL AFTER public_handle;

-- 0010_m4e_strava.sql
CREATE TABLE oauth_connections (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           BIGINT UNSIGNED NOT NULL,
    provider          ENUM('strava') NOT NULL,
    provider_user_id  VARCHAR(64)     NOT NULL,
    access_token_enc  VARBINARY(512)  NOT NULL,
    refresh_token_enc VARBINARY(512)  NOT NULL,
    scope             VARCHAR(255)    NULL,
    expires_at        DATETIME        NULL,
    created_at        DATETIME        NOT NULL,
    updated_at        DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oauth_user_provider (user_id, provider),
    UNIQUE KEY uq_oauth_provider_uid (provider, provider_user_id),
    CONSTRAINT fk_oauth_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE oauth_states (
    state       CHAR(64)        NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    provider    ENUM('strava')  NOT NULL,
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (state),
    CONSTRAINT fk_oauth_states_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 0011_m4f_heatmap.sql
CREATE TABLE heatmap_cells (
    cell_key   VARCHAR(32)  NOT NULL,   -- gerundete "lat:lon"-Bucket-ID
    lat        DECIMAL(9,6) NOT NULL,
    lon        DECIMAL(9,6) NOT NULL,
    weight     INT UNSIGNED NOT NULL,
    updated_at DATETIME     NOT NULL,
    PRIMARY KEY (cell_key),
    KEY idx_heatmap_latlon (lat, lon)
);
```

## 5. API-Oberfläche (neu in M4)

```
# M4a Likes
POST   /api/v1/routes/{id}/like          Bearer; 200/201 idempotent
DELETE /api/v1/routes/{id}/like          Bearer; 204
GET    /api/v1/routes/{id}/likes         Optional; {count, liked_by_viewer, recent:[handle…]}

# M4b Comments
GET    /api/v1/routes/{id}/comments      Optional; paginiert
POST   /api/v1/routes/{id}/comments      Bearer+Verified; 201
DELETE /api/v1/routes/{id}/comments/{cid} Bearer; 204 (Autor oder Routen-Owner)

# M4c Notifications
GET    /api/v1/notifications             Bearer; paginiert
GET    /api/v1/notifications/unread-count Bearer; {count}
POST   /api/v1/notifications/read        Bearer; markiert alle (oder {ids:[]})
POST   /api/v1/notifications/{nid}/read  Bearer; einen

# M4d Avatars
PUT    /api/v1/users/me/avatar           Bearer+Verified; multipart; 200
DELETE /api/v1/users/me/avatar           Bearer; 204
GET    /u/{handle}/avatar                public; image/* (oder Placeholder)

# M4e Strava
GET    /auth/strava/connect              Web/Bearer; redirect zu Strava
GET    /auth/strava/callback             Web; tauscht code→token
POST   /api/v1/integrations/strava/import Bearer+Verified; importiert Activities
DELETE /api/v1/integrations/strava       Bearer; trennt Verbindung

# M4f Heatmap
GET    /api/v1/heatmap                   public; ?bbox=&zoom=; GeoJSON
```

Web-UI ergänzt: Like-/Comment-Controls auf `/u/{handle}/r/{id}`,
`/notifications`-Inbox, Avatar-Form unter `/settings/avatar`,
Strava-Connect unter `/settings/integrations`, Heatmap-Page
`/heatmap`.

## 6. Sub-Milestone-Phasenpläne

Jeder Sub-Milestone läuft als eigener Feature-Branch
(`m4a/...`, `m4b/...`, …), mit `--no-ff`-Merge nach `main` und
§10-Smoke vor dem Merge.

| Sub | Phasen | Aufwand |
|---|---|---|
| M4a | Migration → LikeService → API → Web-Controls → Smoke | 1.0 PT |
| M4b | Migration → CommentService → API → Web → Smoke | 1.5 PT |
| M4c | Migration → NotificationService + Event-Hooks in Follow/Like/Comment → API → Web-Inbox → Cleanup-Cron → Smoke | 1.5 PT |
| M4d | Migration → AvatarService (Validierung/Resize) → API + Serving → Web-Form → Smoke | 1.5 PT |
| M4e | Migration → Crypto-Helper + StravaClient-Interface + Fake → OAuth-Flow → Import → Web → Smoke | 3.0 PT |
| M4f | Migration → HeatmapAggregator (Cron) → API (GeoJSON) → Web-Page → Smoke | 1.5 PT |
| **Σ** | | **~10 PT** |

## 7. Smoke-Plan (je Sub-Milestone, §10-Stil)

Detail-Schritte werden pro Sub-Milestone beim Bau finalisiert; das
Grundgerüst:

- **M4a:** like → count=1, idempotenter Doppel-Like, unlike →
  count=0, Like auf private fremde Route → 404, Block verhindert
  Like, `liked_by_viewer`-Flag korrekt.
- **M4b:** Kommentar anlegen → erscheint in Liste, Autor löscht,
  Owner löscht fremden Kommentar, Nicht-Owner/Nicht-Autor darf
  nicht löschen → 403, Kommentar auf private fremde Route → 404,
  Block verhindert Kommentar, Body-Längen-Validierung 422.
- **M4c:** Follow erzeugt Notif beim Followee, Like erzeugt Notif
  beim Owner, Comment ebenso, Self-Aktion erzeugt keine Notif,
  geblockt → keine Notif, unread-count, mark-read setzt read_at,
  Cleanup entfernt alte gelesene.
- **M4d:** Upload JPEG → avatar_path gesetzt, GET liefert image/*,
  zu groß → 422, falscher Typ → 422, DELETE → Placeholder, Resize
  auf ≤512px.
- **M4e:** (mit FakeStravaClient) connect→callback speichert
  verschlüsselte Tokens, import legt N Routen mit source=strava
  an, Re-Import idempotent (keine Duplikate), disconnect entfernt
  Connection, Token-Verschlüsselung round-trip.
- **M4f:** Aggregator füllt heatmap_cells aus public Routen,
  private/unlisted zählen nicht, GET /heatmap?bbox liefert
  GeoJSON mit weight, bbox-Filter greift, leere Region → leere
  FeatureCollection.

## 8. Was M4 NICHT macht

- **Kein Threading bei Kommentaren** (flach; Replies sind M5).
- **Keine Push-Notifications / WebSockets** (Pull-Polling).
- **Keine Multi-Reactions** (nur Like).
- **Keine volle Track-Linien-Heatmap** (nur Centroid-Dichte).
- **Kein Komoot-Import** (nur Strava in M4e; Komoot analog später).
- **Keine Bild-Galerie pro Route** (nur Profil-Avatar).
- **Kein Realtime-Feed-Update** (Feed bleibt Polling wie M3).
