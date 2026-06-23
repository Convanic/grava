# Valhalla dauerhaft hosten (Hetzner) — feste URL statt Quick-Tunnel

Ziel: `https://valhalla.grava.world` ist **immer** erreichbar, der Prod-Backend
ruft es darüber an. Kein wechselnder `trycloudflare`-Link mehr, kein Laptop nötig.

Valhalla ist **nicht** im Live-Request-Pfad — nur das Precompute (Game-Ingestion,
Heatmap-Linien-Rebuild) nutzt es. Latenz ist also egal, nur Verfügbarkeit zählt.

---

## 0. Wichtig vorab: Europa ist groß

| Phase | Bedarf |
|---|---|
| PBF-Download (`europe-latest.osm.pbf`) | ~28–30 GB |
| Tile-**Build** (RAM) | **viel** — realistisch 32–64 GB RAM, sonst sehr langsam/OOM |
| Gebaute Tiles (Disk) | ~25–40 GB |
| Disk gesamt (PBF + Tiles + Scratch) | **≥ 250 GB** einplanen |
| Build-Zeit | mehrere Stunden bis ~1 Tag |
| **Serve** (nach Build) | genügsam — Tiles werden gemappt, RAM v. a. als Cache |

Der **Build** ist der teure Teil, das **Servieren** danach läuft auch auf
kleineren Maschinen gut.

### Server-Empfehlung (Hetzner)
- **Bestes Preis/Leistung für Europa:** Hetzner **Dedicated/Auction** (Server-Börse)
  mit ≥ 64 GB RAM und 2× SSD (≥ 500 GB). Oft ~€35–45/Monat.
- **Cloud-Konsole, bequemer:** `CCX43` (16 vCPU dediziert, 64 GB, 360 GB) für
  Build + Serve. Optional nach dem Build auf eine kleinere Instanz wechseln und
  das Tile-Volume mitnehmen.
- **Sparsam (nur Deutschland statt Europa):** `CPX41` (8 vCPU, 16 GB, 240 GB)
  reicht für `germany-latest.osm.pbf`. Dann in der compose
  `VALHALLA_TILE_URLS=https://download.geofabrik.de/europe/germany-latest.osm.pbf`.

> Tipp: Erst mit **Deutschland** starten (klein, schnell), später auf Europa
> hochziehen — nur `VALHALLA_TILE_URLS` ändern + `force_rebuild=True` einmalig.

---

## 1. DNS-Record (bei united-domains, KEIN Cloudflare nötig)
Lege im DNS von `grava.world` an:

```
Typ A    Name valhalla    Wert <SERVER-IPv4>
Typ AAAA Name valhalla    Wert <SERVER-IPv6>   (optional)
```

→ `valhalla.grava.world` zeigt auf den neuen Server. (Propagation abwarten:
`dig +short valhalla.grava.world` muss die Server-IP liefern, bevor Caddy ein
TLS-Zertifikat holen kann.)

---

## 2. Server vorbereiten
```bash
# Als root auf dem frischen Server (Ubuntu 24.04):
apt-get update && apt-get install -y docker.io docker-compose-plugin git
systemctl enable --now docker

# Firewall: nur 22/80/443 offen, Valhalla-Port 8002 NICHT öffnen.
ufw allow 22 && ufw allow 80 && ufw allow 443 && ufw --force enable
```

## 3. Projektdateien holen
Nur dieser Ordner wird gebraucht:
```bash
git clone https://github.com/Convanic/grava.git
cd grava/docker/valhalla
cp .env.prod.example .env.prod
```
In `.env.prod` setzen:
```bash
VALHALLA_DOMAIN=valhalla.grava.world
VALHALLA_PROXY_SECRET=$(openssl rand -hex 24)   # Wert notieren!
```

## 4. Starten (Erstbau läuft automatisch los)
```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d
docker compose -f docker-compose.prod.yml logs -f valhalla
```
Der Container lädt das PBF und baut die Tiles (**dauert**, s. o.). Fertig, sobald
`/status` antwortet:
```bash
SECRET=$(grep VALHALLA_PROXY_SECRET .env.prod | cut -d= -f2)
curl -s https://valhalla.grava.world/s/$SECRET/status
```

---

## 5. Prod-Backend umstellen (einmalig)
In der **`.env` auf grava.world**:
```bash
VALHALLA_BASE_URL=https://valhalla.grava.world/s/<DEIN_SECRET>
# (Fallback-Variable konsistent halten:)
VALHALLA_URL=https://valhalla.grava.world/s/<DEIN_SECRET>
```
Kein Code-Deploy nötig — der `ValhallaClient` hängt `/trace_attributes` selbst an.
Danach Smoke-Test über einen Heatmap-/Ingestion-Rebuild.

---

## 6. Dauerhaftigkeit / Betrieb
- `restart: unless-stopped` + Docker-Autostart (`systemctl enable docker`) →
  übersteht Reboots. Tiles liegen im Named Volume `valhalla_tiles`.
- **Update Valhalla:** `docker compose -f docker-compose.prod.yml pull && … up -d`
  (Tiles bleiben erhalten, da `use_tiles_ignore_pbf=True`).
- **Tiles neu bauen (z. B. frischere OSM-Daten / andere Region):**
  `force_rebuild=True` setzen, einmal `up -d`, danach wieder auf `False`.

## 7. Sicherheit
- Zugriff nur über den geheimen Pfad `/s/<SECRET>/…`; alles andere → 404.
- Port 8002 ist **nicht** nach außen gemappt (nur Caddy:443 ist offen).
- Secret rotieren = `.env.prod` ändern, `caddy` neu starten, Prod-`.env`
  nachziehen.
- Alternative/zusätzlich: in der `Caddyfile` eine IP-Allowlist
  (`@allow remote_ip <PROD-IP>`) ergänzen, falls die Prod-Ausgangs-IP stabil ist.
