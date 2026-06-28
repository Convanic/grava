# GRAVA — Architektur: Webseite & Admin-Bereich

> **Zweck dieses Dokuments.** Konsolidierte, aktuelle Beschreibung des Aufbaus
> der **öffentlichen Webseite** und des **Admin-Bereichs** von GRAVA. Es bündelt
> Infos, die bisher über `README.md`, `docs/API.md` und die `docs/superpowers/`-Specs
> verteilt waren, an einem Ort — als Grundlage, um die Webseite für den
> **öffentlichen Launch** auszubauen.
>
> Stand: 2026-06-25 · Quelle der Wahrheit für Routen ist immer
> `public/index.php`, für die API zusätzlich `docs/API.md` + `openapi.yaml`.

---

## 1. Überblick

GRAVA ist ein PHP/MySQL-Monolith, der drei Dinge aus **einem** Front-Controller
(`public/index.php`) bedient:

| Schicht | Pfad-Präfix | Auth | Rendering |
|---------|-------------|------|-----------|
| **JSON-API** (iOS-App) | `/api/v1/*` | Bearer-Token | JSON |
| **Web-App** (Browser) | `/`, `/routes`, `/discover`, … | Cookie-Session + CSRF | Server-gerendertes PHP |
| **Admin** | `/admin/*` (nur auf Admin-Host) | Web-Session + `ADMIN_EMAILS` | Server-gerendertes PHP |
| **Intern/CLI** | `/internal/*` bzw. CLI | `INTERNAL_TOKEN` / Shell | JSON / stdout |

### Domains

| Umgebung | Haupt-Host | Admin-Host | DB |
|----------|------------|------------|----|
| Produktion | `grava.world` | `admin.grava.world` | `gravelexplorer` |
| Lokal (MAMP) | `gravelexplorer.test:8890` | `admin.grava.test` | `gravelexplorer` (+ `gravelexplorer_test`) |

> **Stabil halten (nicht umbenennen):** DB-Name `gravelexplorer`,
> PHP-Namespace `App\`, GPX-XML-Namespace `https://gravelexplorer.benx.de/gpx/v1`.

### Stack

- PHP 8.2+ (entwickelt mit 8.4), MySQL 8.0+ (utf8mb4)
- Composer: PHPMailer, vlucas/phpdotenv, ramsey/uuid
- Frontend: server-gerendertes PHP + Vanilla-JS, Karten via **Leaflet** (vendored)
- Map-Matching/Routing: **Valhalla** (Docker-Container, nur im Precompute/Ingest, nie im Request-Pfad)
- Tests: PHPUnit (Integration braucht MySQL + Valhalla)

---

## 2. Request-Lebenszyklus

Alle Requests laufen über `public/index.php`. Ablauf (HTTP):

1. **Bootstrap:** `Config::boot()`, Fehler-/Exception-Handler, Logging nach
   `storage/logs/php.log`.
2. **Service-Wiring (Poor-man's DI):** Alle Services werden in `index.php`
   instanziiert und in die Controller injiziert. Es gibt keinen DI-Container.
3. **Security-Header** werden im PHP-Layer gesetzt (hosting-portabel, nicht nur
   `.htaccess`): CSP, `X-Content-Type-Options`, `X-Frame-Options: DENY`,
   `Referrer-Policy`, `Permissions-Policy`, HSTS (nur über TLS).
4. **Routen-Registrierung:** API-, Web-, Admin- und Internal-Routen am `Router`.
5. **Host-aware Admin-Split** (siehe §5): entscheidet, ob `/admin/*` erreichbar
   ist und ob Nicht-Admin-Routen auf dem Admin-Host blockiert werden.
6. **Dispatch** über `Router::dispatch()`.

CLI-Dispatch (`php public/index.php <cmd>`) zweigt vor dem HTTP-Teil ab
(`PHP_SAPI === 'cli'`) und ruft `App\Cli\Commands` auf.

---

## 3. Verzeichnisstruktur

```
gravelexplorer/
  public/
    index.php              Front-Controller (API + Web + Admin + CLI + Internal)
    .htaccess              Rewrite + Security-Header (Apache)
    assets/
      style.css            Designsystem "Trail" (CSS-Tokens) + Komponenten
      js/                  map-core, map-route, map-discover, map-heatmap,
                           map-surface-check, map-game-admin, ga.js
      vendor/leaflet/      Leaflet + MarkerCluster + heat (vendored)
      brand/               Icons, Favicons, App-Icon
    favicon.svg
    .well-known/           apple-app-site-association (statisch, Universal Links)
  src/
    Config/                .env-Loader
    Database/              PDO-Factory (Db) + Migrator
    Http/                  Router, Request, Response, Middleware
    Auth/                  AuthService, TokenService, PasswordService,
                           RateLimiter, CookieAuth, WebSession
    Mail/                  MailService (PHPMailer + .eml-Fallback)
    Controllers/
      Api/                 JSON-API-Controller
      Web/                 Web-Seiten-Controller
      Web/Admin/           Admin-Controller (GameAdmin, GameEdgeInspector, AdminUploads)
    Routes/                Routen-Domäne (Upload, GPX, GeoJSON, Insights, Sharing)
    Discovery/             Discover, Profile, Feed, Follow, Block
    Engagement/            Likes, Comments, Notifications (+ Preferences)
    Push/                  APNs (Config, JWT, Transport, DeviceRepository)
    Heatmap/               Heatmap + Heatmap-Lines + Surface-Projektion (Valhalla)
    Integrations/Strava/   Strava-OAuth + Import (Fake-/Real-Client)
    Media/                 Avatare
    Referral/              Empfehlungssystem (M7)
    Privacy/               Privatzonen / Heimat-Schutz
    Game/                  Gamification (Territorialspiel)
      Crew/  Faction/  Rush/  Admin/
    Support/               Validator, Clock, Uuid, Ip, Crypto
    Cli/                   CLI-Befehle (migrate, cron:*, game:*)
  views/
    web/                   Web-App-Templates (siehe §4)
      admin/               Admin-Templates (siehe §5)
      partials/            route-hints, route-insights
      legal/               privacy, terms
    email/                 E-Mail-Templates (verify, reset, …)
  migrations/              0001–0030 (.sql, sequentiell)
  storage/
    logs/                  php.log, mail.log, cron.log
    mail/                  .eml-Dateien wenn kein SMTP
  docs/                    Diese Doku, API.md, Milestones, Specs/Plans
  backend/                 Übergabe-/Status-Docs (teils extern gepflegt)
  docker/valhalla/         Valhalla-Container + Deploy-Doku
```

---

## 4. Öffentliche Webseite (Browser)

Server-gerenderte PHP-Views unter `views/web/`, eingebettet in das gemeinsame
`views/web/layout.php`. Die Navigation im Header schaltet zwischen
**eingeloggtem** und **anonymem** Zustand um.

### 4.1 Seiten / Routen

| Pfad | Auth | View | Zweck |
|------|------|------|-------|
| `/` | – | (Redirect) | Leitet auf `/dashboard` |
| `/login`, `/register` | anonym | `login.php`, `register.php` | Auth-Formulare (CSRF) |
| `/forgot-password`, `/reset-password` | anonym | `forgot.php`, `reset.php` | Passwort-Reset |
| `/verify-email` | anonym | `verify.php` | E-Mail bestätigen |
| `/privacy`, `/terms` | **öffentlich** | `legal/*` | Rechtsseiten |
| `/dashboard` | Login | `dashboard.php` | Eingeloggte Startseite |
| `/features` | Login | `features.php` | Funktionen & Neuigkeiten (Nutzersprache) |
| `/routes`, `/routes/new`, `/routes/{id}`, `/routes/{id}/edit` | Login | `routes/*` | Routen-Verwaltung + Detail-Karte |
| `/discover`, `/discover/users` | anonym | `discover/*` | Routen-/Personen-Entdeckung |
| `/heatmap` | anonym | `heatmap.php` | Heatmap-Karte |
| `/surface-check` | Login | `surface-check.php` | Belag auf Fremd-Route projizieren |
| `/u/{handle}`, `/u/{handle}/r/{id}` | anonym | `profile/*` | Öffentliches Profil + Routen |
| `/u/{handle}/avatar` | öffentlich | – | Avatar-Bytes |
| `/feed`, `/notifications` | Login | `feed.php`, `notifications.php` | Feed + Mitteilungen |
| `/settings/handle`, `/settings/avatar`, `/settings/integrations` | Login | `settings/*` | Einstellungen + Strava |
| `/share/{token}` | öffentlich | `share.php` | Geteilte Route (read-only) |
| `/i/{code}` | öffentlich | `referral/landing.php` | Empfehlungs-Landingpage |

Schreibende Aktionen (Follow, Like, Comment, Upload, Settings) sind durchweg
**POST + CSRF** (`_csrf`-Token, serverseitig in PHP-Session).

### 4.2 Auth-Zustände im Header (`layout.php`)

- **Anonym:** Entdecken · Heatmap · Login · Registrieren
- **Eingeloggt:** Dashboard · Funktionen · Routen · Entdecken · Heatmap ·
  Belag prüfen · Feed · Mitteilungen (mit Unread-Badge) · @handle · Abmelden

Web-Primär-Auth ist die **WebSession** (Cookie `ge_session`). Läuft sie ab,
gibt es einen Refresh-Hop über `/auth/web-refresh` (path-scoped Cookie
`ge_refresh`), sonst Redirect auf `/login`.

### 4.3 ⚠️ Lücke für den öffentlichen Launch

**Aktuell gibt es keine öffentliche Marketing-/Landingpage.** `/` leitet direkt
auf `/dashboard`, und `/dashboard` erzwingt Login (→ `web-refresh` → `/login`).
Ein anonymer Besucher von `grava.world` landet damit faktisch auf dem Login.

Für „in die Öffentlichkeit gehen" fehlt typischerweise:

1. **Öffentliche Startseite** (`/`) mit Produktpitch, App-Store-Link, Screenshots,
   Call-to-Action (statt Redirect auf `/dashboard`).
2. **Marketing-Inhalte** (Was ist GRAVA?, Funktionen öffentlich, evtl. FAQ/Blog).
3. Klarer Einstieg für anonyme Besucher in die bereits öffentlichen Bereiche
   (`/discover`, `/heatmap`).
4. SEO-Grundlagen (Meta-Tags/OpenGraph, `sitemap.xml`, `robots.txt`),
   Cookie-/Consent-Hinweis (GA ist via gtag eingebunden).

> Dies ist der primäre Arbeitsbereich für den anstehenden Launch — siehe §10.

---

## 5. Admin-Bereich

Ausführliche Design-/Plan-Doku:
`docs/superpowers/specs/2026-06-20-game-admin-dashboard-design.md` und
`docs/superpowers/plans/2026-06-20-game-admin-dashboard.md`.
Setup: `backend/GAME_DASHBOARD_SETUP.md`.

### 5.1 Host-aware Split

In `public/index.php` (Ende) entscheidet `App\Game\Admin\AdminHost::isAdmin()`
anhand des `Host`-Headers, ob es sich um den Admin-Host handelt:

- **Admin-Host (`admin.grava.world`):** Es sind nur erlaubt: `/admin/*`,
  `/login`, `/logout`, `/auth/web-refresh`, `/healthz`, `/internal/*`,
  `/assets/*`, Favicons. `/` und `/dashboard` werden auf `/admin/game`
  umgeleitet. Alles andere → 404.
- **Haupt-Host (`grava.world`):** `/admin/*` ist **nicht** erreichbar → 404.

Die Admin-Session ist automatisch host-gebunden, weil das PHP-Session-Cookie
keine `Domain` setzt (eigene Session + eigenes CSRF auf der Subdomain).

### 5.2 Gating

- **`AdminGuard`** prüft Web-Session **und** Treffer in `ADMIN_EMAILS`.
- Kein Treffer → 404 (Verschleierung, keine Login-Aufforderung).
- Schreibende Admin-Aktionen: POST + CSRF + **Audit-Log** (`game_audit`).

### 5.3 Admin-Seiten

| Pfad | Controller | View | Zweck |
|------|------------|------|-------|
| `/admin/game` | `GameAdminController::health` | `admin/game/health.php` | Health/Übersicht |
| `/admin/game/config` (GET/POST) | `GameAdminController::config` | `admin/game/config.php` | Spiel-Konfig editieren |
| `/admin/game/recompute` (POST) | `GameAdminController::recompute` | – | Neuberechnung (sync, opt. bbox) |
| `/admin/game/ingest` (GET/POST) | `GameAdminController::ingest` | `admin/game/ingest.php` | Ingest-Monitor + Re-Ingest |
| `/admin/game/moderation` | `GameAdminController::moderation` | `admin/game/moderation.php` | Moderation |
| `/admin/game/players`, `/admin/game/player` | `GameAdminController` | `admin/game/players.php`, `player.php` | Spielerübersicht/-detail |
| `/admin/game/crews` | `GameAdminController::crews` | `admin/game/crews.php` | Crews |
| `/admin/game/map`, `/admin/game/edges.geojson` | `GameAdminController` | `admin/game/map.php` | Karten-Inspektor |
| `/admin/game/edge`, `/admin/game/edge/{id}` | `GameEdgeInspectorController` | `admin/game/edge.php` | Kanten-Inspektor |
| `/admin/game/edge/{id}/recalc` (POST) | `GameEdgeInspectorController` | – | Kante neu berechnen |
| `/admin/game/pass/{id}/invalidate|reactivate` (POST) | `GameEdgeInspectorController` | – | Pass moderieren |
| `/admin/game/user/{id}/ban` (POST) | `GameEdgeInspectorController` | – | Spieler sperren |
| `/admin/referrals`, `/admin/referrals.csv` | `AdminReferralPagesController` | `admin/referrals.php` | Empfehlungs-Auswertung (M7) |
| `/admin/uploads`, `/admin/uploads/{id}/download` | `AdminUploadsController` | `admin/uploads.php` | Upload-Übersicht/Download |

Funktionsumfang A–F: Health, Config-Editor, Ingest-Monitor, Kanten-Inspektor,
Moderation, Leaderboard.

---

## 6. JSON-API (Kurzüberblick)

Vollständige Referenz: **`docs/API.md`** (+ `openapi.yaml`). Alle Endpunkte unter
`API_BASE_PATH` (Default `/api/v1`), Bearer-Token-Auth, Fehler-Envelope
`{ "error": { "code", "message", "fields? } }`.

Funktionsbereiche: `auth/*`, `users/*` (+ Handle, Privacy-Zone), `routes/*`
(+ Shares, Payload), `share/{token}`, `discover/*`, Profile/Follow/Block, `feed`,
Likes/Comments/Notifications, Push-Devices, Avatare, `integrations/strava/*`,
`heatmap` + `heatmap/lines` + `me/heatmap`, `referrals/me`, sowie der große
`game/*`-Block (Edges, Leaderboard, Segments, Crews, Rush, Factions).

---

## 7. Datenhaltung

- **MySQL** (utf8mb4), Zugriff ausschließlich über **Prepared Statements**
  (`App\Database\Db` als PDO-Factory).
- **Migrationen:** `migrations/0001…0030.sql`, sequentiell, idempotent über
  `migrations`-Tracking-Tabelle. Ausführung via `composer migrate` /
  `php public/index.php cli:migrate` / Internal-Endpoint `/internal/migrate`.
- **Hinweis:** MySQL committet DDL **implizit** — schlägt eine Migration mit
  mehreren DDL-Statements mittendrin fehl, ist sie nur teilweise angewandt und
  wird nicht als erledigt markiert (manuell prüfen/nachziehen).

---

## 8. Frontend & Designsystem

- **Designsystem „Trail"** — Tokens in `public/assets/style.css` (`:root`):
  Farben, Radien, Schatten **immer** über Tokens, nie hartkodierte Hex-Werte
  (Voraussetzung für späteren Dark Mode). Vollständige Spec:
  `docs/superpowers/specs/2026-06-19-grava-ci-design.md`, Kurzregeln:
  `.cursor/rules/design-system.mdc`.
- **Marke:** GRAVA, Persönlichkeit rau/outdoor + community/einladend.
  Header-Lockup `.brand` (G-Tile + Wortmarke), Brand-Assets unter
  `public/assets/brand/`, Favicon `public/favicon.svg`.
- **Komponenten:** `.btn-primary` (grün), `.btn-accent` (clay, sek. CTAs),
  `.btn-secondary`, `.btn-link`, `.card`, `.level-*`, `.tag-new`.
- **Karten:** Leaflet (vendored unter `assets/vendor/leaflet/`), seiten-spezifische
  JS-Module (`map-*.js`) werden pro View über `$_pageScripts`/`$_pageStyles`
  geladen — strikt same-origin wegen CSP (keine Inline-Scripts).
- **Analytics:** Google Analytics via gtag (Loader extern, Init aus same-origin
  `/assets/js/ga.js`).

---

## 9. Sicherheit

- **CSP** strikt (`default-src 'self'`, Tile-Server + GTM/GA whitelisted,
  `frame-ancestors 'none'`, `object-src 'none'`). Keine Inline-Scripts.
- **Security-Header** zusätzlich im PHP-Layer (siehe §2).
- **Passwörter:** Argon2id (`password_hash`), Rehash bei Bedarf.
- **Tokens** (Access/Refresh/Reset/Verify): 32 Zufallsbytes, nur SHA-256-Hash
  gespeichert, Lookup über Unique-Index. Refresh wird bei jedem `/auth/refresh`
  rotiert.
- **CSRF:** alle Web-/Admin-POSTs (`Csrf`-Middleware, `hash_equals`).
- **Rate-Limiting:** fenster-basiert für login/register/forgot/verify-resend.
- **Cookies:** `HttpOnly`, `SameSite=Lax`, `Secure` bei HTTPS;
  `ge_session` (host-gebunden), `ge_refresh` (path-scoped auf `/auth/web-refresh`).
- **Admin:** Host-Split + `ADMIN_EMAILS`-Gate + Audit-Log.

---

## 10. Interne Endpoints, Cron & CLI

Für Shared-Hosting ohne SSH lassen sich CLI-Aufgaben per HTTP auslösen — geschützt
durch `INTERNAL_TOKEN` (`?token=` oder Header `X-Internal-Token`, `hash_equals`).
Ohne gesetzten Token verhalten sie sich wie 404.

| Endpoint | CLI-Befehl | Zweck |
|----------|-----------|-------|
| `/internal/migrate` | `cli:migrate` | Migrationen ausführen |
| `/internal/cron/cleanup` | `cron:cleanup` | Tokens/Sessions aufräumen |
| `/internal/cron/heatmap` | `cron:heatmap` | Heatmap aggregieren |
| `/internal/cron/heatmap-lines` | `cron:heatmap-lines` | Heatmap-Linien (Valhalla) |
| `/internal/cron/game-recompute` | `game:recompute` | Spiel neu berechnen |
| `/internal/cron/rush-tick` | `game:rush-tick` | Rush-Tick |
| `/internal/game/heal-crews` | `game:heal-crews` | Crew-Invarianten |
| `/internal/logtail` | `internal:logtail` | Log-Tail (Diagnose) |
| `/internal/push/doctor` | `internal:apns-check` | APNs-Diagnose |
| `/internal/heatmap/manifest` / `import` | – | Cutover (Heatmap-Edges) |
| `/healthz` | – | Liveness (`?check=valhalla` für Komponenten-Check) |

---

## 11. Deployment (Kurz)

- **DocumentRoot** zeigt auf `public/`. HTTPS Pflicht (HSTS aktiv über TLS).
- `.env` aus `.env.example`: `APP_ENV=production`, neuer `APP_KEY`, DB-/SMTP-Daten,
  `COOKIE_DOMAIN=grava.world`, `ADMIN_HOST=admin.grava.world`, `ADMIN_EMAILS`,
  `INTERNAL_TOKEN`, `VALHALLA_BASE_URL`.
- **Valhalla** läuft als Docker-Container (Hetzner-Deploy:
  `docker/valhalla/DEPLOY_HETZNER.md`); nur Precompute/Ingest nutzen ihn.
- **Cron** für `cron:cleanup` (+ optional Heatmap/Game) einrichten.
- **Lokales Setup:** `docs/LOCAL_DEV_STARTUP.md` (MAMP + Docker/Valhalla).

---

## 12. Launch-Checkliste „öffentliche Webseite"

Konkrete To-dos, um `grava.world` öffentlich präsentierbar zu machen
(Detail-Begründung in §4.3):

- [ ] **Öffentliche Startseite** unter `/` (statt Redirect auf `/dashboard`):
      Hero/Pitch, App-Store-CTA, Screenshots, Einstieg in `/discover`+`/heatmap`.
- [ ] Anonymer Zugang zu `/discover` und `/heatmap` aus der Startseite verlinken.
- [ ] Öffentliche **Funktions-/Über-uns-Seite** (ggf. `/features` anonym zugänglich
      machen oder eine Marketing-Variante anlegen).
- [ ] **SEO/Sharing:** `<meta>`/OpenGraph je Seite, `sitemap.xml`, `robots.txt`.
- [ ] **Consent/Cookies** für Google Analytics (gtag) rechtskonform einbinden.
- [ ] `/privacy` + `/terms` inhaltlich final prüfen (existieren bereits).
- [ ] Header/Footer-Navigation für anonyme Besucher launch-tauglich gestalten.
- [ ] Produktions-`.env` vollständig (Domains, Admin, Strava, APNs, Valhalla).

---

## 13. Verwandte Dokumente

| Dokument | Inhalt |
|----------|--------|
| `README.md` | Setup, Stack, Verzeichnisstruktur (Stand M1, teils veraltet) |
| `docs/API.md` + `openapi.yaml` | Vollständige API-Referenz (iOS) |
| `docs/LOCAL_DEV_STARTUP.md` | Lokales Hochfahren (MAMP, Docker, Valhalla) |
| `docs/CUTOVER.md` | Heatmap-Cutover-Verfahren |
| `docs/MILESTONE_2/3/4.md` | Schrittweiser Ausbau |
| `docs/superpowers/specs/2026-06-20-game-admin-dashboard-design.md` | Admin-Design |
| `docs/superpowers/plans/2026-06-20-game-admin-dashboard.md` | Admin-Umsetzungsplan |
| `docs/superpowers/specs/2026-06-19-grava-ci-design.md` | Designsystem „Trail" |
| `backend/GAME_DASHBOARD_SETUP.md` | Admin-Dashboard-Setup |
| `.cursor/rules/design-system.mdc` | Designsystem-Kurzregeln |
