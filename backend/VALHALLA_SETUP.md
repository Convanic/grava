# Valhalla-Setup für Gamification Stufe 1

## Zweck
Map-Matching hochgeladener Routen auf OSM-Kanten via `POST /trace_attributes`
(costing `bicycle`, `shape_match: map_snap`). Läuft auf einem LOKALEN Server,
nicht auf grava.world. Das Backend ruft ihn über `VALHALLA_BASE_URL` an.

## Tiles bauen (Testgebiet)
1. OSM-Extrakt der Region laden (z. B. Geofabrik, `region-latest.osm.pbf`).
2. `valhalla_build_config --mjolnir-tile-dir ./valhalla_tiles > valhalla.json`
3. `valhalla_build_tiles -c valhalla.json region-latest.osm.pbf`
4. `valhalla_service valhalla.json 1` startet den Dienst (Default Port 8002).

## Knoten-Identität (Stufe 1)
`trace_attributes` liefert im Default keine OSM-Node-IDs. `ValhallaEdgeMatcher`
bildet daher einen stabilen Integer-Knoten-Ref aus den gerundeten
Endkoordinaten der Kante (`crc32(round(lat,5):round(lon,5))`, ~1.1 m Raster).
Zwei Kanten am selben Knoten teilen denselben Ref → `game_edge`-Schlüssel
`(way_id, node_a_id, node_b_id)` bleibt stabil, solange der Tile-Stand gleich
ist. Für exakte OSM-Knoten in späteren Stufen: `trace_attributes` mit
`filters` auf `edge.end_node.*` erweitern und den Matcher anpassen.

## Fehlerfall
Ist Valhalla nicht erreichbar, wirft der Matcher; der Upload-Hook schluckt das
(Route bleibt gespeichert). Re-Run per `POST /api/v1/game/ingest/{route_id}`.
