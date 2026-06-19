# Cutover-Checkliste (Go-Live)

Offene Punkte, die **vor dem echten Live-Gang** erledigt sein müssen. Kein
MVP-/Dev-Komfort, sondern harte Voraussetzungen für den Produktivbetrieb.

## 1. Secrets rotieren (HOCH)

**Kontext:** Während der Entwicklung (2026-06-19) wurde die Prod-`.env` im
Klartext in einem Chat-/Agent-Transkript exponiert. Alle darin enthaltenen
Geheimnisse gelten als kompromittiert und müssen beim Cutover **neu gesetzt**
werden.

Zu rotieren:

- [ ] **`DB_PASS`** – DB-Passwort beim Hoster (ud-webspace) ändern, dann in der
      Prod-`.env` aktualisieren.
- [ ] **`MAIL_PASSWORD`** – Mail-Account-Passwort ändern. **Wichtig:** Aktuell
      identisch mit `DB_PASS` – beim Rotieren **zwei unterschiedliche**, starke
      Passwörter vergeben (kein Shared-Secret über Dienste hinweg).
- [ ] **`INTERNAL_TOKEN`** – neuen Zufallswert setzen (`openssl rand -hex 32`).
      Schützt `/internal/migrate` und die Cron-Endpunkte.
- [ ] **`APP_KEY`** – neu erzeugen. **Achtung:** verschlüsselt via `Crypto`
      u. a. gespeicherte Strava-Tokens. Solange die Strava-Integration leer
      konfiguriert ist (`STRAVA_CLIENT_ID` leer), ist ein Wechsel unkritisch;
      sobald Strava-Tokens gespeichert werden, macht ein `APP_KEY`-Wechsel
      bestehende verschlüsselte Werte unbrauchbar (Re-Auth nötig).

Nach der Rotation: einmal Login/Upload + `/internal/migrate` mit neuem Token
gegenchecken.

## 2. Mail-Versand absichern

Siehe `docs/REVIEW_TODO.md`: Solange `MAIL_HOST` leer ist, fällt der
MailService still auf den Disk-Fallback (`storage/mail/*.eml`) zurück. Vor
Go-Live SMTP verbindlich konfigurieren und sicherstellen, dass ein leerer
`MAIL_HOST` in Production hart fehlschlägt statt stillschweigend zu schlucken.

## 3. Heatmap produktiv setzen (Cutover-Modell A)

**Grundprinzip:** Valhalla (Map-Matching) läuft **nie** in Prod — auf dem
Shared-Webspace (kein Docker/SSH) wäre das ohnehin nicht möglich. Valhalla wird
ausschließlich **lokal** zum *Berechnen* gebraucht. Die App liest zur Laufzeit
nur die fertige Tabelle `heatmap_edges`. Detail-Begründung:
`docs/PLAN_HEATMAP_MAPMATCH.md` §12 (Modell A).

**Datenfluss (vollständig, beide Richtungen):** Valhalla rechnet lokal, das
Ergebnis (`heatmap_edges`) geht nach Prod. Da die Prod-DB **nicht** von lokal
erreichbar ist, kommen die public Routen als *Manifest* (interner HTTP-Endpunkt)
+ die GPX-Dateien per *SFTP* aufs lokale System — den **Hinweg** automatisiert
`scripts/pull_prod_routes.sh`.

```
PROD (Shared-Webspace)                          LOKAL (Docker/Valhalla)
 GET /internal/heatmap/manifest ──curl───────►  build/heatmap_manifest.json
   (public_id, payload_path, format)             (welche Routen sind public)
 storage/routes/*.gpx ───────────sftp────────►  build/prod_routes/…
                                                     │
                                          heatmap:rebuild-local (Valhalla-Matching)
                                                     │  → lokal heatmap_edges
 heatmap_edges  ◄──Shadow-Import + RENAME──────  build/heatmap_edges.sql (Dump)
   │
 GET /api/v1/heatmap/lines (nur Lesen, kein Valhalla)
```

### 3a. Die beiden Heatmaps unterscheiden

- **Dichte-Heatmap** (`heatmap_cells`, `cron:heatmap`): reine SQL-Aggregation,
  **kein Valhalla**. Läuft komplett auf Prod, kann per Webspace-Cron die URL
  `POST /internal/cron/heatmap?token=…` aufrufen. Ist bereits live-fähig.
- **Strecken-Heatmap** (`heatmap_edges`, `cron:heatmap-lines`): braucht Valhalla
  → nur lokal rechnen, Ergebnis nach Prod schieben (unten).

### 3b. Erstbefüllung / Update der Strecken-Heatmap

> **Hinweis zum DB-Zugang:** Die Prod-DB ist nur vom Webspace erreichbar (nicht
> von lokal). Deshalb läuft der **Hinweg** über Manifest (HTTP) + SFTP, und der
> **Rückweg** (Import) wird **auf dem Webspace** ausgeführt — entweder per
> phpMyAdmin-Import des Dumps oder, falls vorhanden, serverseitig. Das
> `sync_heatmap_edges.sh import` setzt einen erreichbaren `mysql`-Client mit
> Prod-DB-Zugang voraus.

1. **Migration in Prod** einspielen (einmalig):
   `0012_m6_heatmap_edges.sql` via `GET /internal/migrate?token=…` ausführen
   und prüfen, dass die Tabelle `heatmap_edges` existiert.

2. **Hinweg + lokaler Rebuild + Dump** in einem Schritt (lokale Valhalla muss
   laufen, `docker/valhalla/`). Vorher SFTP-Zugang konfigurieren — am
   einfachsten in `scripts/.env.sync` (gitignored):

   ```bash
   # scripts/.env.sync
   PROD_BASE_URL=https://grava.world
   SFTP_HOST=…ud-webspace.de
   SFTP_USER=…
   SFTP_PASS=…                 # oder SFTP_KEY=/pfad/zum/key
   SFTP_REMOTE_DIR=/…/storage/routes   # Pfad zu storage/routes auf dem Webspace
   ```

   ```bash
   scripts/pull_prod_routes.sh
   ```

   Das Skript holt das Manifest (`/internal/heatmap/manifest`), lädt die
   GPX-Dateien per SFTP nach `build/prod_routes/`, matcht sie lokal
   (`heatmap:rebuild-local`) und schreibt den Dump nach `build/heatmap_edges.sql`.

   *Reiner Dev-Lauf ohne Prod-Daten* (nur lokale DB): stattdessen
   `scripts/sync_heatmap_edges.sh export build/heatmap_edges.sql`.

3. **In die Prod-DB importieren** (auf dem Webspace / mit Prod-DB-Zugang):

   ```bash
   scripts/sync_heatmap_edges.sh import build/heatmap_edges.sql
   ```

   Lädt in die Shadow-Tabelle `heatmap_edges_new`, prüft auf >0 Zeilen und macht
   dann einen atomaren `RENAME`-Swap (kein Lese-Ausfall). **Alternativ** den
   Dump `build/heatmap_edges.sql` direkt per **phpMyAdmin** in die
   `heatmap_edges`-Tabelle importieren (vorher `TRUNCATE heatmap_edges`).

### 3c. Layer sichtbar schalten (Feature-Flag)

`HEATMAP_LINES_ENABLED` steuert, ob der „Strecken"-Layer auf `/heatmap`
erscheint. **Default `false`**, damit der Web-Code deploybar ist, *bevor*
`heatmap_edges` befüllt wurde.

1. Smoke-Test: `GET /api/v1/heatmap/lines?bbox=…` liefert Features.
2. Erst dann in der Prod-`.env` `HEATMAP_LINES_ENABLED=true` setzen.
3. **Rollback:** Flag zurück auf `false` (oder Zeile entfernen) — der Rest der
   Seite (Dichte-Heatmap) bleibt unberührt.

### 3d. Aktualität

Modell A ist **nicht** automatisch: Bei neuen public Routen lokal erneut
`export` rechnen und `import` nach Prod fahren (z. B. wöchentlich). Erst wenn
das zu mühsam wird, lohnt **Modell B** (Valhalla als eigener Dienst auf einem
VPS, nächtlicher `cron:heatmap-lines`) — geht nicht auf dem Shared-Webspace.
