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
Kraichgau-Beispielroute abdeckt. Andere Regionen via Umgebungsvariable — aber
**immer nur EIN PBF** (siehe Warnung unten):

```bash
# Deutschland (ein PBF, ~4,3 GB) — deckt aktuell alle public Routen ab:
export VALHALLA_TILE_URLS="https://download.geofabrik.de/europe/germany-latest.osm.pbf"
docker compose -f docker/valhalla/docker-compose.yml up -d --force-recreate
```

Nach einem Regionswechsel muss neu gebaut werden:

```bash
rm -rf docker/valhalla/custom_files     # alte Tiles verwerfen
docker compose -f docker/valhalla/docker-compose.yml up -d --force-recreate
```

> ⚠️ **Kein Multi-PBF.** Mehrere getrennte PBFs (z. B. DE + AT + CH als drei
> URLs) lassen `valhalla_build_tiles` sofort mit `std::exception … Aborted`
> abstürzen (Valhalla warnt selbst davor). Ergebnis: 0 Tiles auf Level 0/1,
> jeder Match scheitert mit `No suitable edges`. Geofabrik bietet **kein**
> fertiges `dach-latest.osm.pbf`.
>
> **Volles DACH** daher nur über einen vorher **gemergten** Extrakt:
> ```bash
> brew install osmium-tool   # oder dockerisiertes osmium
> osmium merge germany-latest.osm.pbf austria-latest.osm.pbf \
>   switzerland-latest.osm.pbf -o dach.osm.pbf
> # dann dach.osm.pbf in custom_files/ legen und ohne tile_urls bauen.
> ```

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
