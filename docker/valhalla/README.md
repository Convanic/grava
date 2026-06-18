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

## Region wechseln

Standard ist ein kleiner Spike-Extrakt (**Regierungsbezirk Karlsruhe**), der die
Kraichgau-Beispielroute abdeckt. Andere Regionen via Umgebungsvariable:

```bash
# Volles DACH (DE + AT + CH) — braucht ~5–6 GB PBF + ~3–6 GB Tiles:
export VALHALLA_TILE_URLS="https://download.geofabrik.de/europe/germany-latest.osm.pbf https://download.geofabrik.de/europe/austria-latest.osm.pbf https://download.geofabrik.de/europe/switzerland-latest.osm.pbf"
docker compose -f docker/valhalla/docker-compose.yml up -d --force-recreate
```

Nach einem Regionswechsel muss neu gebaut werden:

```bash
rm -rf docker/valhalla/custom_files     # alte Tiles verwerfen
docker compose -f docker/valhalla/docker-compose.yml up -d --force-recreate
```

## Map-Match testen

```bash
curl -s http://localhost:8002/trace_attributes \
  -H 'Content-Type: application/json' \
  -d '{"shape":[{"lat":49.15,"lon":8.6},{"lat":49.152,"lon":8.6035},{"lat":49.1542,"lon":8.6065}],
       "costing":"bicycle","shape_match":"map_snap",
       "filters":{"action":"include","attributes":["edge.id","edge.way_id","edge.length","edge.begin_shape_index","edge.end_shape_index","shape","matched_points"]}}'
```

## Hinweise

- **Platzbedarf:** volles DACH transient ~10–15 GB. Bei wenig freiem Speicher
  zunächst beim Klein-Extrakt bleiben.
- **Sicherheit:** Valhalla nie öffentlich exponieren — nur die App/der
  Precompute-Job sprechen mit Port 8002 (in Prod im internen Netz, siehe Cutover
  §12 im Plan).
- `custom_files/` ist `.gitignore`d (Tiles/PBFs werden nicht eingecheckt).
