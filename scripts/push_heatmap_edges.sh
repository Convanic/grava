#!/usr/bin/env bash
#
# push_heatmap_edges.sh — Cutover-Rückweg (Modell A) für die Heatmap-Streckenlinien.
#
# Schiebt die lokal berechneten heatmap_edges als JSON an den token-geschützten
# Endpunkt /internal/heatmap/import auf PROD. Dort werden die Zeilen in eine
# Shadow-Tabelle geladen und atomar geswappt — KEIN phpMyAdmin / mysql-Client
# nötig. Hintergrund: docs/CUTOVER.md §3.
#
# Voraussetzung: build/heatmap_edges.json existiert (erzeugt von
# pull_prod_routes.sh bzw. `php public/index.php heatmap:export-edges --out=…`).
#
# Konfiguration (env oder scripts/.env.sync):
#   PROD_BASE_URL   z. B. https://grava.world   (Default: APP_URL aus .env)
#   INTERNAL_TOKEN  Token für den Endpunkt       (Default: aus .env)
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/.env"
SYNC_ENV="$ROOT/scripts/.env.sync"

EDGES_JSON="${EDGES_JSON:-${1:-$ROOT/build/heatmap_edges.json}}"
PHP_BIN="${PHP_BIN:-php}"

die() { echo "FEHLER: $*" >&2; exit 1; }

if [ -f "$SYNC_ENV" ]; then
  # shellcheck disable=SC1090
  set -a; . "$SYNC_ENV"; set +a
fi

env_get() {
  local key="$1"
  [ -f "$ENV_FILE" ] || return 0
  local line
  line="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" | head -n1 || true)"
  line="${line#*=}"; line="${line%$'\r'}"
  line="${line#\"}"; line="${line%\"}"
  line="${line#\'}"; line="${line%\'}"
  printf '%s' "$line"
}

PROD_BASE_URL="${PROD_BASE_URL:-$(env_get APP_URL)}"
INTERNAL_TOKEN="${INTERNAL_TOKEN:-$(env_get INTERNAL_TOKEN)}"

[ -n "$PROD_BASE_URL" ]  || die "PROD_BASE_URL ist leer (oder APP_URL in .env setzen)."
[ -n "$INTERNAL_TOKEN" ] || die "INTERNAL_TOKEN ist leer (.env oder env)."
[ -f "$EDGES_JSON" ]     || die "Datei nicht gefunden: $EDGES_JSON (vorher pull_prod_routes.sh laufen lassen)."

LOCAL_COUNT="$("$PHP_BIN" -r '$d=json_decode(file_get_contents($argv[1]),true);echo (int)($d["count"]??count($d["rows"]??[]));' "$EDGES_JSON" 2>/dev/null || echo 0)"
SIZE="$(wc -c < "$EDGES_JSON" | tr -d ' ')"
echo ">> Push: ${LOCAL_COUNT} Kanten (${SIZE} Bytes) -> ${PROD_BASE_URL%/}/internal/heatmap/import"
[ "${LOCAL_COUNT:-0}" -gt 0 ] || die "Export enthält 0 Kanten — Abbruch (Live-Tabelle bleibt unangetastet)."

RESP="$(curl -fsS -X POST \
  -H 'Content-Type: application/json' \
  -H "X-Internal-Token: ${INTERNAL_TOKEN}" \
  --data-binary "@${EDGES_JSON}" \
  "${PROD_BASE_URL%/}/internal/heatmap/import")" \
  || die "HTTP-Request fehlgeschlagen (Antwort siehe oben; ggf. post_max_size/Upload-Limit auf PROD erhöhen)."

echo "$RESP" | "$PHP_BIN" -r '$d=json_decode(stream_get_contents(STDIN),true);
  if(!is_array($d)){fwrite(STDERR,"Unerwartete Antwort.\n");exit(1);}
  printf("   received=%s imported=%s swapped=%s\n",$d["received"]??"?",$d["imported"]??"?",var_export($d["swapped"]??false,true));
  if(empty($d["swapped"])){fwrite(STDERR,"WARNUNG: Kein Swap durchgeführt — Live-Tabelle unverändert.\n");exit(1);}
  echo "OK. heatmap_edges auf PROD aktualisiert.\n";'
