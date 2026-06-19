#!/usr/bin/env bash
#
# pull_prod_routes.sh — Cutover-Hinweg (Modell A) für die Heatmap-Streckenlinien.
#
# Holt die public Routen von PROD aufs lokale System, damit die lokale Valhalla
# sie matchen kann. Hintergrund: docs/CUTOVER.md §3 + docs/PLAN_HEATMAP_MAPMATCH.md.
#
# Warum nötig: Der Rebuild braucht (a) die Liste der public Routen und (b) deren
# GPX-Dateien. Die Prod-DB ist von lokal NICHT erreichbar — daher kommt die
# Liste als Manifest über den internen HTTP-Endpunkt, die Dateien über SFTP.
#
# Ablauf:
#   1. GET /internal/heatmap/manifest  -> build/heatmap_manifest.json (curl)
#   2. SFTP-Download der Payload-Dateien aus dem Manifest -> build/prod_routes/
#   3. php heatmap:rebuild-local        -> füllt lokal heatmap_edges (Valhalla)
#   4. dump heatmap_edges               -> build/heatmap_edges.sql
#
# Danach nach PROD schieben:
#   scripts/sync_heatmap_edges.sh import build/heatmap_edges.sql
#
# Konfiguration über Umgebungsvariablen (oder scripts/.env.sync, s. u.):
#   PROD_BASE_URL   z. B. https://grava.world        (Default: APP_URL aus .env)
#   INTERNAL_TOKEN  Token für den internen Endpunkt   (Default: aus .env)
#   SFTP_HOST       SFTP-Host des Webspace            (Pflicht)
#   SFTP_USER       SFTP-Benutzer                     (Pflicht)
#   SFTP_PASS       SFTP-Passwort                     (optional, sonst Key)
#   SFTP_KEY        Pfad zu privatem SSH-Key          (optional, statt Passwort)
#   SFTP_PORT       SFTP-Port                         (Default: 22)
#   SFTP_REMOTE_DIR Remote-Pfad zu storage/routes auf dem Webspace (Pflicht),
#                   z. B. /kunden/123/webseiten/grava/storage/routes
#
# Optionale Datei scripts/.env.sync (NICHT committen) mit denselben Keys wird,
# falls vorhanden, vor der Ausführung geladen.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/.env"
SYNC_ENV="$ROOT/scripts/.env.sync"

MANIFEST="${MANIFEST:-$ROOT/build/heatmap_manifest.json}"
ROUTES_DIR="${ROUTES_DIR:-$ROOT/build/prod_routes}"
EDGES_JSON="${EDGES_JSON:-$ROOT/build/heatmap_edges.json}"
PHP_BIN="${PHP_BIN:-php}"

die() { echo "FEHLER: $*" >&2; exit 1; }

# scripts/.env.sync laden (optional) — überschreibt nichts bereits Gesetztes.
if [ -f "$SYNC_ENV" ]; then
  # shellcheck disable=SC1090
  set -a; . "$SYNC_ENV"; set +a
fi

# Liest einen Schlüssel aus der .env (erste Zuweisung, ohne Quotes/Whitespace).
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
SFTP_PORT="${SFTP_PORT:-22}"

[ -n "$PROD_BASE_URL" ]   || die "PROD_BASE_URL ist leer (oder APP_URL in .env setzen)."
[ -n "$INTERNAL_TOKEN" ]  || die "INTERNAL_TOKEN ist leer (.env oder env)."
[ -n "${SFTP_HOST:-}" ]   || die "SFTP_HOST ist nicht gesetzt."
[ -n "${SFTP_USER:-}" ]   || die "SFTP_USER ist nicht gesetzt."
[ -n "${SFTP_REMOTE_DIR:-}" ] || die "SFTP_REMOTE_DIR ist nicht gesetzt (Pfad zu storage/routes auf PROD)."

command -v lftp >/dev/null 2>&1 || die "lftp wird benötigt (macOS: 'brew install lftp')."

# --- 1. Manifest holen --------------------------------------------------------
echo ">> 1/4 Manifest holen: ${PROD_BASE_URL%/}/internal/heatmap/manifest"
mkdir -p "$(dirname "$MANIFEST")"
# Antwort ist {"ok":true,"output":"<manifest-json>"} — innere JSON extrahieren.
curl -fsS "${PROD_BASE_URL%/}/internal/heatmap/manifest?token=${INTERNAL_TOKEN}" \
  | "$PHP_BIN" -r '$d=json_decode(stream_get_contents(STDIN),true);
      if(!is_array($d)||empty($d["ok"])){fwrite(STDERR,"Endpoint-Fehler: ".($d["error"]??"unbekannt")."\n");exit(1);}
      echo $d["output"];' \
  > "$MANIFEST"

ROUTE_COUNT="$("$PHP_BIN" -r '$d=json_decode(file_get_contents($argv[1]),true);echo (int)($d["count"]??count($d["routes"]??[]));' "$MANIFEST")"
echo "   Manifest: ${ROUTE_COUNT} public Route(n) -> $MANIFEST"
[ "${ROUTE_COUNT:-0}" -gt 0 ] || die "Manifest enthält keine public Routen — Abbruch."

# --- 2. Payload-Dateien per SFTP holen ---------------------------------------
echo ">> 2/4 Payload-Dateien per SFTP holen -> $ROUTES_DIR"
mkdir -p "$ROUTES_DIR"

# Relative payload_path-Liste aus dem Manifest.
mapfile -t REL_PATHS < <("$PHP_BIN" -r '$d=json_decode(file_get_contents($argv[1]),true);
  foreach(($d["routes"]??[]) as $r){$p=ltrim((string)($r["payload_path"]??""),"/");
    if($p!=="" && !str_contains($p,"..")) echo $p."\n";}' "$MANIFEST")

[ "${#REL_PATHS[@]}" -gt 0 ] || die "Keine payload_path-Einträge im Manifest."

# Lokale Zielverzeichnisse anlegen + lftp-Skript bauen (ein get pro Datei).
LFTP_SCRIPT="$(mktemp)"
trap 'rm -f "$LFTP_SCRIPT"' EXIT
{
  echo "set net:timeout 20"
  echo "set sftp:auto-confirm yes"
  if [ -n "${SFTP_KEY:-}" ]; then
    echo "set sftp:connect-program \"ssh -a -x -i ${SFTP_KEY}\""
  fi
  if [ -n "${SFTP_PASS:-}" ]; then
    echo "open -u \"${SFTP_USER},${SFTP_PASS}\" -p ${SFTP_PORT} sftp://${SFTP_HOST}"
  else
    echo "open -u \"${SFTP_USER}\" -p ${SFTP_PORT} sftp://${SFTP_HOST}"
  fi
  for rel in "${REL_PATHS[@]}"; do
    mkdir -p "$ROUTES_DIR/$(dirname "$rel")"
    echo "get -O \"$ROUTES_DIR/$(dirname "$rel")\" \"${SFTP_REMOTE_DIR%/}/$rel\""
  done
  echo "bye"
} > "$LFTP_SCRIPT"

lftp -f "$LFTP_SCRIPT"

DOWNLOADED="$(find "$ROUTES_DIR" -type f | wc -l | tr -d ' ')"
echo "   ${DOWNLOADED} Datei(en) lokal unter $ROUTES_DIR"
[ "${DOWNLOADED:-0}" -gt 0 ] || die "Keine Dateien geladen — SFTP-Konfiguration prüfen."

# --- 3. Lokaler Rebuild gegen Valhalla ---------------------------------------
echo ">> 3/4 Lokaler Rebuild (heatmap:rebuild-local gegen lokale Valhalla) ..."
( cd "$ROOT" && "$PHP_BIN" public/index.php heatmap:rebuild-local \
    --manifest="$MANIFEST" --routes-dir="$ROUTES_DIR" )

# --- 4. heatmap_edges als JSON exportieren -----------------------------------
echo ">> 4/4 heatmap_edges exportieren -> $EDGES_JSON"
( cd "$ROOT" && "$PHP_BIN" public/index.php heatmap:export-edges --out="$EDGES_JSON" )

echo
echo "FERTIG. Jetzt nach PROD schieben (HTTP-Import, kein mysql/phpMyAdmin nötig):"
echo "  scripts/push_heatmap_edges.sh \"$EDGES_JSON\""
