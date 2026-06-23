# Lokales Dev-Setup hochfahren (nach Reboot)

Was nach einem Neustart laufen muss, damit **grava** lokal vollständig funktioniert
(Backend, Tests, Game-Ingestion/Map-Matching). Reihenfolge ist unkritisch, aber
Docker/MAMP zuerst.

## Übersicht

| Komponente | Wozu | Start | Schnell-Check |
|---|---|---|---|
| **MAMP Pro** | MySQL + Apache/Hosts | MAMP-Pro-App starten (Server „Start") | `mysqladmin ping` bzw. Browser `https://gravelexplorer.test:8890` |
| **Docker Desktop** | Hostet den Valhalla-Container | `open -a Docker` (oder App starten) | `docker info` |
| **Valhalla** (`ge_valhalla`) | Map-Matching (`trace_attributes`) für Game-Ingestion | startet via `restart: unless-stopped` mit Docker automatisch | `curl -s http://localhost:8002/status` |
| **cloudflared Tunnel** | *Nur* wenn Prod-Backend den lokalen Valhalla erreichen soll | `scripts/valhalla_tunnel.sh start` | `scripts/valhalla_tunnel.sh status` |

## 1. MAMP Pro

- MAMP-Pro-App öffnen, Server starten.
- Liefert MySQL **und** die lokalen Hosts.

Eckdaten (aus `.env`):
- MySQL: Socket `/Applications/MAMP/tmp/mysql/mysql.sock`, TCP `127.0.0.1:8889`, User `root` / Pass `root`
- DBs: `gravelexplorer` (dev) + `gravelexplorer_test` (wird vom Test-Bootstrap automatisch angelegt/migriert)
- App-Host: `https://gravelexplorer.test:8890`, Admin-Host: `admin.grava.test`

Check:
```bash
/Applications/MAMP/Library/bin/mysql80/bin/mysqladmin \
  --socket=/Applications/MAMP/tmp/mysql/mysql.sock -uroot -proot ping
# -> "mysqld is alive"
```

## 2. Docker + Valhalla

Docker Desktop starten:
```bash
open -a Docker
# warten, bis Daemon oben ist:
until docker info >/dev/null 2>&1; do sleep 2; done; echo "docker ready"
```

Der Valhalla-Container `ge_valhalla` hat `restart: unless-stopped` und kommt i. d. R.
**automatisch** mit hoch. Prüfen:
```bash
docker ps --format '{{.Names}}\t{{.Status}}\t{{.Ports}}' | grep ge_valhalla
curl -s http://localhost:8002/status   # JSON mit "trace_attributes" => bereit
```

Falls er **nicht** läuft, manuell starten:
```bash
docker compose -f docker/valhalla/docker-compose.yml up -d
docker compose -f docker/valhalla/docker-compose.yml logs -f   # Build/Boot beobachten
```

Hinweis: `restart: unless-stopped` bedeutet — wurde der Container mal **manuell
gestoppt**, startet er nicht von selbst wieder; dann obiges `up -d` nutzen.

## 3. cloudflared Quick-Tunnel (optional)

Nur nötig, wenn das **Prod-Backend** (grava.world) auf den lokalen Valhalla
zugreifen soll. Für lokale Arbeit/Tests **nicht** erforderlich (die nutzen
`http://localhost:8002`).

Komfort-Skript (startet im Hintergrund, wartet auf die URL und gibt sie aus):
```bash
scripts/valhalla_tunnel.sh start    # startet + zeigt öffentliche URL
scripts/valhalla_tunnel.sh url      # aktuelle URL erneut anzeigen
scripts/valhalla_tunnel.sh status   # läuft er?
scripts/valhalla_tunnel.sh stop     # beenden
scripts/valhalla_tunnel.sh logs     # Live-Logs
```

Das Skript läuft per `nohup` im Hintergrund (Log/PID unter `$TMPDIR/ge_valhalla_tunnel/`),
prüft vorab, ob Valhalla erreichbar ist, und gibt am Ende die `VALHALLA_BASE_URL`-Zeile
für Prod aus.

Manuell ginge es auch direkt (Vordergrund):
```bash
cloudflared tunnel --url http://localhost:8002
```

Wichtig:
- Die URL `https://<zufall>.trycloudflare.com` ist **ephemer** und ändert sich bei
  jedem Neustart von cloudflared.
- Danach auf dem Prod-Server `VALHALLA_BASE_URL` auf die neue URL setzen.

## Verifikation gesamt

```bash
# 1) MySQL erreichbar?
nc -z 127.0.0.1 8889 && echo "mysql ok"
# 2) Valhalla erreichbar?
curl -s http://localhost:8002/status >/dev/null && echo "valhalla ok"
# 3) Integrationstests (brauchen MySQL + Valhalla)
vendor/bin/phpunit --no-coverage
```

Wenn die Integrationstests mit „Test-DB nicht verfügbar … No such file or directory"
skippen → MAMP/MySQL läuft nicht (Socket fehlt).
