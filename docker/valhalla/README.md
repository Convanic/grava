# Valhalla (Map-Matching für Heatmap-Streckenlinien)

Lokaler Valhalla-Dienst, der GPS-Spuren aufs OSM-Straßennetz snappt
(`trace_attributes`). Wird **nur im Precompute** der `heatmap_edges`-Tabelle
verwendet — nicht im Request-Pfad. Hintergrund: `docs/PLAN_HEATMAP_MAPMATCH.md`.

## Start

```bash
docker compose -f docker/valhalla/docker-compose.yml up -d
docker compose -f docker/valhalla/docker-compose.yml logs -f   # Build beobachten
curl http://localhost:8002/status                              # bereit?
```

Beim **ersten** Start lädt der Container das/die PBF(s), baut die Routing-Tiles
(dauert je nach Region Minuten bis ~40 min) und legt sie unter `custom_files/`
ab. Folgestarts nutzen die Tiles direkt (Sekunden).

## Region: volles DACH (Default)

Standard ist jetzt **volles DACH** (Deutschland + Österreich + Schweiz) als EIN
gemergtes `custom_files/dach.osm.pbf`. Gebaut wird ohne Download direkt aus
diesem lokalen PBF (`tile_urls` leer, `use_tiles_ignore_pbf=False`).

> ⚠️ **Kein Multi-PBF.** Mehrere getrennte PBFs (z. B. DE + AT + CH als drei
> URLs/Dateien) lassen `valhalla_build_tiles` sofort mit `std::exception …
> Aborted` abstürzen (Valhalla warnt selbst davor). Ergebnis: 0 Tiles auf
> Level 0/1, jeder Match scheitert mit `No suitable edges`. Geofabrik bietet
> **kein** fertiges `dach-latest.osm.pbf` — daher selbst mergen. In
> `custom_files/` darf zur Bauzeit **nur ein** `*.osm.pbf` liegen.

### DACH-PBF (neu) erzeugen

```bash
brew install osmium-tool
mkdir -p docker/valhalla/_dach_build && cd docker/valhalla/_dach_build
curl -LO https://download.geofabrik.de/europe/germany-latest.osm.pbf
curl -LO https://download.geofabrik.de/europe/austria-latest.osm.pbf
curl -LO https://download.geofabrik.de/europe/switzerland-latest.osm.pbf
osmium merge germany-latest.osm.pbf austria-latest.osm.pbf \
  switzerland-latest.osm.pbf -o ../custom_files/dach.osm.pbf
cd -
# alte Tiles + andere PBFs aus custom_files/ entfernen, nur dach.osm.pbf lassen:
rm -f docker/valhalla/custom_files/{germany,austria,switzerland}-latest.osm.pbf \
      docker/valhalla/custom_files/{valhalla_tiles.tar,file_hashes.txt,duplicateways.txt,valhalla.json}
rm -rf docker/valhalla/custom_files/valhalla_tiles
docker compose -f docker/valhalla/docker-compose.yml up -d --force-recreate
docker compose -f docker/valhalla/docker-compose.yml logs -f   # Build beobachten
```

Der Erstbau für DACH dauert ~30–60 min (4 Threads, ~5,7 GB PBF). Danach werden
die Tiles wegen `force_rebuild=False` bei jedem Folgestart sofort weiterverwendet.

## Ganz Europa (optional)

Größerer Extrakt (~28–30 GB PBF, Tiles ~25–40 GB, Build-RAM 32–64 GB, Stunden
bis ~1 Tag). Siehe `DEPLOY_HETZNER.md`.

**Vorbereiten (DACH bleibt aktiv):**
```bash
docker/valhalla/prepare_europe.sh download   # ~28 GB nach _europe_build/
docker/valhalla/prepare_europe.sh status     # Fortschritt / aktueller Stand
```

**Umstellen (ersetzt DACH, startet Tile-Build):**
```bash
docker/valhalla/prepare_europe.sh activate   # interaktive Bestätigung
docker compose -f docker/valhalla/docker-compose.yml logs -f
```

In `custom_files/` darf zur Bauzeit weiterhin **nur ein** `*.osm.pbf` liegen
(`europe-latest.osm.pbf`).

### Andere/kleinere Region

Für reine Lokaltests reicht ein kleinerer Extrakt. Entweder analog ein anderes
einzelnes PBF nach `custom_files/` legen, oder per Download-URL erzwingen:

```bash
export VALHALLA_TILE_URLS="https://download.geofabrik.de/europe/germany/baden-wuerttemberg/karlsruhe-regbez-latest.osm.pbf"
rm -rf docker/valhalla/custom_files/*   # alte Tiles/PBFs verwerfen
docker compose -f docker/valhalla/docker-compose.yml up -d --force-recreate
```

## Map-Match testen

Deutschland (Kraichgau):

```bash
curl -s http://localhost:8002/trace_attributes \
  -H 'Content-Type: application/json' \
  -d '{"shape":[{"lat":49.15,"lon":8.6},{"lat":49.152,"lon":8.6035},{"lat":49.1542,"lon":8.6065}],
       "costing":"bicycle","shape_match":"map_snap",
       "filters":{"action":"include","attributes":["edge.id","edge.way_id","edge.length","edge.begin_shape_index","edge.end_shape_index","shape","matched_points"]}}'
```

Österreich (Innsbruck) bzw. Schweiz (Zürich) — sollten mit dem DACH-Build
ebenfalls matchen:

```bash
# AT
curl -s http://localhost:8002/trace_attributes -H 'Content-Type: application/json' \
  -d '{"shape":[{"lat":47.2620,"lon":11.3950},{"lat":47.2635,"lon":11.3990},{"lat":47.2650,"lon":11.4030}],"costing":"bicycle","shape_match":"map_snap","filters":{"action":"include","attributes":["edge.way_id","edge.length"]}}'
# CH
curl -s http://localhost:8002/trace_attributes -H 'Content-Type: application/json' \
  -d '{"shape":[{"lat":47.3700,"lon":8.5400},{"lat":47.3715,"lon":8.5440},{"lat":47.3730,"lon":8.5480}],"costing":"bicycle","shape_match":"map_snap","filters":{"action":"include","attributes":["edge.way_id","edge.length"]}}'
```

## Hinweise

- **Platzbedarf:** volles DACH transient ~10–15 GB. Bei wenig freiem Speicher
  zunächst beim Klein-Extrakt bleiben.
- **Sicherheit:** Valhalla nie öffentlich exponieren — nur die App/der
  Precompute-Job sprechen mit Port 8002 (in Prod im internen Netz, siehe Cutover
  §12 im Plan).
- `custom_files/` ist `.gitignore`d (Tiles/PBFs werden nicht eingecheckt).
