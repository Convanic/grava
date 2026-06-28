#!/usr/bin/env bash
#
# prepare_europe.sh — Europa-PBF laden und lokalen Valhalla auf Europe umstellen.
#
# Ablauf (DACH bleibt bis „activate“ unangetastet):
#   docker/valhalla/prepare_europe.sh download    # ~28 GB PBF nach _europe_build/
#   docker/valhalla/prepare_europe.sh status     # Download / custom_files prüfen
#   docker/valhalla/prepare_europe.sh activate   # DACH-Tiles weg, Europe-Build starten
#
# Voraussetzungen: Docker, ≥250 GB freier Disk, Build-RAM realistisch 32–64 GB
# (siehe DEPLOY_HETZNER.md). Erstbau dauert Stunden bis ~1 Tag.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_DIR="$ROOT/_europe_build"
PBF="$BUILD_DIR/europe-latest.osm.pbf"
PBF_URL="https://download.geofabrik.de/europe-latest.osm.pbf"
CUSTOM="$ROOT/custom_files"
TARGET="$CUSTOM/europe-latest.osm.pbf"
COMPOSE=(docker compose -f "$ROOT/docker-compose.yml")

die() { echo "FEHLER: $*" >&2; exit 1; }

cmd_download() {
  mkdir -p "$BUILD_DIR"
  if [[ -f "$PBF" ]]; then
    echo "PBF existiert bereits: $PBF ($(du -h "$PBF" | cut -f1))"
    return 0
  fi
  command -v curl >/dev/null 2>&1 || die "curl fehlt."
  echo "Lade $PBF_URL -> $PBF (~28–30 GB, dauert)..."
  curl -L --fail --retry 3 --retry-delay 5 -C - -o "$PBF" "$PBF_URL"
  echo "Download fertig: $(du -h "$PBF" | cut -f1)"
}

cmd_status() {
  echo "=== Download (_europe_build) ==="
  if [[ -f "$PBF" ]]; then
    echo "  europe-latest.osm.pbf: $(du -h "$PBF" | cut -f1)"
  else
    echo "  europe-latest.osm.pbf: fehlt (prepare_europe.sh download)"
  fi
  echo "=== custom_files (aktiv) ==="
  ls -lah "$CUSTOM" 2>/dev/null || echo "  (leer)"
  echo "=== Valhalla ==="
  if curl -sf -m 3 http://localhost:8002/status >/dev/null 2>&1; then
    curl -s http://localhost:8002/status | head -c 200
    echo
  else
    echo "  /status nicht erreichbar (Build oder gestoppt)"
  fi
  "${COMPOSE[@]}" ps 2>/dev/null || true
}

cmd_activate() {
  [[ -f "$PBF" ]] || die "PBF fehlt — zuerst: prepare_europe.sh download"
  local min_bytes=$((30 * 1024 * 1024 * 1024))
  local size
  size="$(stat -f%z "$PBF" 2>/dev/null || stat -c%s "$PBF")"
  [[ "$size" -ge "$min_bytes" ]] || die "PBF wirkt unvollständig ($(du -h "$PBF" | cut -f1)) — Download fortsetzen."

  echo "WARNUNG: DACH-Valhalla wird gestoppt; Erstbau Europe dauert Stunden."
  echo "Tunnel/Prod-Map-Matching ist bis /status wieder antwortet offline."
  read -r -p "Fortfahren? [y/N] " ans
  case "$ans" in
    [yY]|[yY][eE][sS]) ;;
    *) echo "Abgebrochen."; exit 0 ;;
  esac

  "${COMPOSE[@]}" down
  rm -f "$CUSTOM"/*.osm.pbf \
        "$CUSTOM"/valhalla_tiles.tar \
        "$CUSTOM"/file_hashes.txt \
        "$CUSTOM"/duplicateways.txt \
        "$CUSTOM"/valhalla.json \
        "$CUSTOM"/.DS_Store
  rm -rf "$CUSTOM"/valhalla_tiles
  cp "$PBF" "$TARGET"
  echo "PBF nach $TARGET kopiert ($(du -h "$TARGET" | cut -f1))"

  VALHALLA_TILE_URLS= "${COMPOSE[@]}" up -d --force-recreate
  echo
  echo "Build gestartet. Logs:"
  echo "  docker compose -f docker/valhalla/docker-compose.yml logs -f"
  echo "Fertig wenn:"
  echo "  curl -s http://localhost:8002/status"
}

case "${1:-}" in
  download) cmd_download ;;
  status)   cmd_status ;;
  activate) cmd_activate ;;
  *) echo "Verwendung: $0 {download|status|activate}" >&2; exit 2 ;;
esac
