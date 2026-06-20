# Game Admin-Dashboard (Stufe 1) — Design

**Status:** Freigegeben (Brainstorming-Entscheidungen unten)
**Quelle:** `backend/GAME_STAGE1_DASHBOARD.md` + Rückwirkungs-Callout in `backend/GAME_STAGE1_BACKEND.md` §5
**Branch:** `feat/game-admin-dashboard` (baut auf `feat/game-stage1`)

## Ziel

Ein server-gerendertes Web-Admin-Dashboard (PHP, bestehender Stack) zum Betreiben, Tunen,
Überwachen und Moderieren der Gamification-Mechanik. Kein iOS-Aufwand. Voller Umfang A–F.
Erreichbar ausschließlich unter `admin.grava.world`.

## Festgelegte Entscheidungen (Brainstorming)

1. **Umfang:** Voll A–F (Health, Config-Editor, Ingest-Monitor, Kanten-Inspector, Moderation, Leaderboard) + Backend-Rückwirkungen.
2. **Subdomain:** Gleicher Docroot wie `grava.world`; Host-bewusstes Routing in `public/index.php`.
3. **Host-Aufteilung:** `admin.grava.world` trägt **alle** Admin-Seiten + Admin-Login. Hauptdomain blockt `/admin/*` (404) und behält API + normale Web-App.
4. **Admin-Identität:** Bestehendes `ADMIN_EMAILS`-Gate (keine `users`-Migration).
5. **Session:** Eigene host-gebundene Admin-Session. Das PHP-Session-Cookie `ge_session` setzt keine `Domain` → ist bereits host-gebunden; Login auf der Subdomain erzeugt automatisch eine separate Session mit eigenem CSRF.
6. **Recompute:** Synchron über die bestehende CLI-Logik (`game:recompute`, neu mit optionalem `--bbox=`), keine Job-Queue (YAGNI für Stufe 1).

## Architektur

### 1. Host-bewusstes Routing

In `public/index.php` (HTTP-Dispatch) wird ein Host-Flag bestimmt:

```
$adminHost = (string) $config->get('ADMIN_HOST', 'admin.' . ltrim(parse_url((string)$config->get('APP_URL',''), PHP_URL_HOST) ?? '', '.'));
$isAdminHost = strcasecmp($requestHost, $adminHost) === 0;   // $requestHost aus Host-Header
```

- **`$isAdminHost === true`:** Es werden NUR registriert: die Web-Auth-Seiten (`/login`, `/logout`, `/auth/web-refresh`, ggf. forgot/reset) und `/admin/*` (Game + bestehendes Referral-Admin). Jede andere Route → 404 (Catch-all bzw. Router-Default).
- **`$isAdminHost === false`:** Bestehende API + Web-App wie heute, ABER `/admin/*` wird nicht mehr registriert → 404.

Realisierung als zwei getrennte Routen-Registrierungs-Blöcke hinter dem Flag. Die Service-Verdrahtung (DI) bleibt gemeinsam.

> **Dev-Hinweis:** Lokal ist `admin.grava.world` nicht auflösbar. Über `ADMIN_HOST` (z. B. `localhost` mit Query/Override) bzw. einen `X-Admin-Host`-Testpfad lässt sich der Admin-Modus in Tests/CLI erzwingen. Tests setzen den Host direkt im Request.

### 2. Auth / Session / CSRF

- Admin-Login = bestehende Web-Auth (`AuthPagesController`), auf der Subdomain host-gebunden.
- Admin-Gate (wiederverwendbar, neu als `AdminGuard`-Helfer): Web-Session vorhanden + `ADMIN_EMAILS`-Treffer. Sonst 404 (Verschleierung, konsistent mit `AdminReferralPagesController`).
- Schreibende Aktionen: POST + CSRF (`Csrf`-Middleware), zusätzlich Audit (§4).

### 3. DB-Erweiterungen — Migration `0016_game_dashboard.sql`

- `game_ingest_log(id, route_id, user_id, status ENUM(ok,pending,failed), matched_edges, new_passes, skipped_json JSON, valhalla_error, duration_ms, created_at)`
- `game_audit(id, admin_user_id, action, target, detail_json, created_at)`
- `game_user_flag(user_id PK, banned, reason, updated_at)`
- `ALTER game_edge_pass ADD invalidated_at, invalidated_by, invalid_reason`
- `game_config`-Seeds: `auth_max_speed_kmh=80`, `mod_max_new_edges_per_min=30`, `mod_max_passes_per_day=200`

### 4. Backend-Rückwirkung (greift in getesteten Kern ein)

Invalidierte Pässe (`invalidated_at IS NOT NULL`) zählen für KEINE Berechnung mehr:

- `GameRepository::passesForEdge` → `AND invalidated_at IS NULL` (nutzt `EdgeRecalculator` für Präsenz)
- `GameRepository::distinctRidersTotal` / `distinctRidersSince` → `AND invalidated_at IS NULL`
- `GameRepository::refreshEdgeDiscovery` (Subqueries für distinct/MIN/discoverer) → `AND invalidated_at IS NULL`
- `GameRepository::firstPassPerUser` (Pionier-Kohorte) → `AND invalidated_at IS NULL`
- Neuer Lesepfad `GameRepository::allPassesForEdge` (inkl. invalidierte) NUR für den Inspector.
- `GameIngestionService`: vor der Verarbeitung Ban-Check (`game_user_flag.banned`) → gebannte User erzeugen keine Pässe; und am Ende (sowie im Fehlerpfad) Schreiben einer `game_ingest_log`-Zeile.

Bestehende Stufe-1-Tests bleiben grün (keine invalidierten Pässe in Fixtures). Neue Tests prüfen die Filter explizit.

### 5. Services / Controller / Views

- Services: `GameAdminService` (Health-Kennzahlen, Ingest-Monitor-Reads, Inspector-Aggregate, Leaderboard), `GameModerationService` (Heuristik-Queries), `GameAuditService` (Audit-Schreiben + letzte Aktionen), `GamePassAdminService` (Invalidieren/Reaktivieren + Kante-Neurechnen), `GameUserFlagService` (Ban/Unban).
- Controller (server-gerendert) unter `App\Controllers\Web\Admin\` pro Seitengruppe.
- Views unter `views/admin/game/*` im Stil der bestehenden `views/admin/referrals`.

### 6. Recompute-Jobs

- CLI `game:recompute` erweitert um optional `--bbox=minLon,minLat,maxLon,maxLat` (neue Repo-Methode `edgeIdsInBbox`).
- Admin-Buttons „Voll-Recompute" / „Region-Recompute" rufen die Logik synchron (Controller → Service/CLI-Kern), schreiben Audit.

## Die Seiten (A–F)

| Seite | Pfad | Inhalt |
|---|---|---|
| A. Health | `/admin/game` | Kennzahlen, Valhalla-Ping, Ingest-Health, letzte Audits |
| B. Config | `/admin/game/config` | Alle `game_config`-Keys editierbar (Validierung), Recompute-Buttons, Pionier-Vorschau |
| C. Ingest-Monitor | `/admin/game/ingest` | Liste `game_ingest_log`, Filter, „Erneut ingestieren" (einzeln/Sammel-pending) |
| D. Kanten-Inspector | `/admin/game/edge/{id}` (+Suche) | Besitzer, Wert-Aufschlüsselung, Kohorte, Pass-Historie (inkl. invalidiert), Pass invalidieren/reaktivieren, Kante neu rechnen |
| E. Moderation | `/admin/game/moderation` | Heuristik-Review-Queue, User-Drilldown, Pässe invalidieren, User sperren |
| F. Leaderboard | `/admin/game/players` | Ranglisten (gehaltene Kanten/Länge/Erstbefahrungen), Drilldown |

Alle schreibenden Aktionen: POST + CSRF + Audit.

## Heuristik-Parameter

`auth_max_speed_kmh` (80), `mod_max_new_edges_per_min` (30), `mod_max_passes_per_day` (200) — markieren nur (Review-Queue), invalidieren nicht automatisch.

## Tests (Spec-Dashboard §5, 1–8)

1. Zugriffsschutz: Non-Admin → 404 auf `/admin/game/*`; Host-Gating (Admin-Seiten auf Hauptdomain → 404).
2. Config-Update + Audit (before/after) + Validierung (negatives `presence_window_days` abgelehnt).
3. Recompute identisch zum Live-Pfad (verweist auf Backend §10.5) + Audit.
4. Ingest-Monitor: simulierter Valhalla-Fehler erscheint als `failed`; „Erneut ingestieren" → `ok`, `new_passes>0`, Audit.
5. Pass-Invalidierung wirkt: Kante neu gerechnet, Wert/Besitzer ohne den Pass; Reaktivieren stellt Zustand wieder her; Audit.
6. Ban-Effekt: gebannter User erzeugt bei Ingestion keine neuen Pässe.
7. Leaderboard-Aggregate korrekt für Fixture.
8. Inspector-Wertaufschlüsselung gegen Golden-Numbers.

Plus: neue Filter-Tests (invalidierte Pässe aus distinct/Präsenz/Kohorte ausgeschlossen). Erweiterung von `GAME_STAGE1_TESTREPORT.md`.

## iOS-Relevanz

**Keine API-Vertragsänderung.** Das Dashboard ist reines Admin-Web. Die `/api/v1/game/*`-Endpunkte
und ihre JSON-Strukturen bleiben identisch. Einzige fachliche Auswirkung: Spielwerte können sich
**rückwirkend** ändern (Admin invalidiert Pässe / sperrt User / löst Recompute aus), daher sollte der
iOS-Client gecachte Kanten/Werte nicht als unveränderlich behandeln (normaler Refresh genügt).

## Bewusst draußen (YAGNI)

- Echte Job-Queue / asynchrone Worker (synchroner Recompute reicht für Stufe-1-Daten).
- `users.is_admin`-Spalte (ADMIN_EMAILS reicht).
- Automatische Invalidierung durch Heuristiken (nur Markierung).
- Parent-Domain-Session-Sharing (host-gebundene Admin-Session ist sicherer).

## Definition of Done

- [ ] Host-bewusstes Routing: Admin nur auf `admin.grava.world`, `/admin/*` auf Hauptdomain → 404.
- [ ] Migration `0016_game_dashboard.sql` (3 Tabellen + Invalidierungsspalten + Heuristik-Seeds).
- [ ] Backend-Rückwirkung: alle Berechnungen ignorieren invalidierte Pässe; Ban-Check + `game_ingest_log` in `game_ingest`.
- [ ] Seiten A–F server-gerendert, alle Schreibaktionen mit CSRF + Audit.
- [ ] CLI `game:recompute --bbox=` + Admin-Recompute-Buttons.
- [ ] Akzeptanztests Dashboard-§5 (1–8) + Filter-Tests grün; Testbericht ergänzt.
