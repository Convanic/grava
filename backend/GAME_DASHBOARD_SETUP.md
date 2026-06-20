# Game Admin-Dashboard (Stufe 1) — Setup & Betrieb

Kurzanleitung für den Betrieb des Admin-Dashboards. Es teilt sich Code, Datenbank
und Front-Controller (`public/index.php`) mit der Hauptanwendung; ein Host-Gate
schaltet die `/admin/*`-Routen ausschließlich auf der Admin-Subdomain frei.

## Subdomain

- DNS: A- bzw. CNAME-Eintrag `admin` → **gleicher Server/Docroot** wie `grava.world`.
  Beispiel: `admin.grava.world` zeigt auf denselben DocumentRoot wie die Hauptdomain.
- Apache vHost / `.htaccess`: **kein zusätzliches Rewrite nötig**. Derselbe
  Front-Controller (`public/index.php`) übernimmt das Host-Gate. Es muss nur
  sichergestellt sein, dass `admin.grava.world` auf denselben DocumentRoot zeigt und
  TLS (Let's Encrypt) den Host abdeckt (Zertifikat inkl. `admin.`-Subdomain bzw.
  Wildcard).

## ENV

| Variable | Bedeutung |
|---|---|
| `ADMIN_HOST` | Admin-Subdomain, z. B. `admin.grava.world`. Leer = wird aus `APP_URL` abgeleitet als `admin.<host>`. |
| `ADMIN_EMAILS` | Kommagetrennte Liste der Admin-E-Mails — bestimmt, wer Admin ist. |
| `APP_URL` | Bestehende Basis-URL der Hauptanwendung (Grundlage für `ADMIN_HOST`-Ableitung). |

> **Wichtig:** Mindestens **eines** von `ADMIN_HOST` oder `APP_URL` muss gesetzt sein. Sind beide leer/unparsbar, ist das Gate fail-closed — `/admin/*` liefert dann auf **jedem** Host 404 (das Dashboard ist unerreichbar, ohne Fehlermeldung).

## Session

Das `ge_session`-Cookie setzt **keine** `Domain` → das Admin-Login auf der Subdomain
ist eine **eigene, host-gebundene Session mit eigenem CSRF** (kein Sharing mit der
Hauptdomain). Folge: Admins müssen sich auf `admin.grava.world` **separat** einloggen,
auch wenn sie auf `grava.world` bereits angemeldet sind.

## Migration

Migration `0016_game_dashboard.sql` einspielen:

```
php public/index.php cli:migrate
```

Alternativ über den HTTP-Endpunkt: `/internal/migrate?token=...`.

## Recompute

```
php public/index.php game:recompute                                  # voller Recompute
php public/index.php game:recompute --bbox=minLon,minLat,maxLon,maxLat   # nur Region
```

Der Recompute ist zudem über die Config-Seite des Dashboards auslösbar (synchron).

## Seiten

| | Seite | Pfad |
|---|---|---|
| A | Übersicht / Leaderboard | `/admin/game` |
| B | Konfiguration + Recompute | `/admin/game/config` |
| C | Ingest-Monitor | `/admin/game/ingest` |
| D | Edge-Inspector | `/admin/game/edge/{id}` |
| E | Moderation | `/admin/game/moderation` |
| F | Spieler / User-Flags | `/admin/game/players` |
