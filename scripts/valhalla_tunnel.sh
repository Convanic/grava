#!/usr/bin/env bash
#
# valhalla_tunnel.sh — startet/stoppt einen cloudflared Quick-Tunnel auf den
# lokalen Valhalla (http://localhost:8002), damit das Prod-Backend (grava.world)
# den lokalen Map-Matching-Dienst erreichen kann.
#
# Hintergrund: docker/valhalla/docker-compose.prod.yml (trycloudflare-Quick-Tunnel),
# docs/LOCAL_DEV_STARTUP.md.
#
# Für rein LOKALE Arbeit/Tests NICHT nötig — die nutzen direkt localhost:8002.
#
#   scripts/valhalla_tunnel.sh start    # Tunnel im Hintergrund starten, URL ausgeben
#   scripts/valhalla_tunnel.sh url      # aktuelle öffentliche URL anzeigen
#   scripts/valhalla_tunnel.sh status   # läuft der Tunnel?
#   scripts/valhalla_tunnel.sh stop     # Tunnel beenden
#   scripts/valhalla_tunnel.sh logs     # Live-Logs folgen
#
# Die URL ist ephemer und ändert sich bei jedem Neustart. Danach auf dem
# Prod-Server VALHALLA_BASE_URL auf die ausgegebene URL setzen.

set -euo pipefail

LOCAL_URL="http://localhost:8002"
RUN_DIR="${TMPDIR:-/tmp}/ge_valhalla_tunnel"
LOG_FILE="${RUN_DIR}/cloudflared.log"
PID_FILE="${RUN_DIR}/cloudflared.pid"

mkdir -p "$RUN_DIR"

die() { echo "FEHLER: $*" >&2; exit 1; }

is_running() {
  [[ -f "$PID_FILE" ]] && kill -0 "$(cat "$PID_FILE")" 2>/dev/null
}

extract_url() {
  # Erste trycloudflare.com-URL aus dem Log fischen.
  grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' "$LOG_FILE" 2>/dev/null | head -n1
}

cmd_start() {
  command -v cloudflared >/dev/null 2>&1 || die "cloudflared nicht installiert (brew install cloudflared)."

  if is_running; then
    echo "Tunnel läuft bereits (PID $(cat "$PID_FILE"))."
    cmd_url
    return 0
  fi

  # Vorab: ist Valhalla überhaupt da?
  if ! curl -sf -m 5 "${LOCAL_URL}/status" >/dev/null 2>&1; then
    echo "WARNUNG: ${LOCAL_URL}/status antwortet nicht — läuft Docker/ge_valhalla?" >&2
  fi

  : > "$LOG_FILE"
  nohup cloudflared tunnel --url "$LOCAL_URL" >>"$LOG_FILE" 2>&1 &
  echo $! > "$PID_FILE"

  # Auf die URL warten (max ~20s).
  local url=""
  for _ in $(seq 1 40); do
    url="$(extract_url)"
    [[ -n "$url" ]] && break
    is_running || die "cloudflared ist abgestürzt. Log: $LOG_FILE"
    sleep 0.5
  done
  [[ -n "$url" ]] || die "Keine Tunnel-URL gefunden. Log: $LOG_FILE"

  echo "Tunnel gestartet (PID $(cat "$PID_FILE"))."
  echo "Öffentliche URL: $url"
  echo
  echo "Auf dem Prod-Server setzen:  VALHALLA_BASE_URL=$url"
}

cmd_url() {
  is_running || die "Tunnel läuft nicht (scripts/valhalla_tunnel.sh start)."
  local url; url="$(extract_url)"
  [[ -n "$url" ]] || die "Noch keine URL im Log — kurz warten und erneut versuchen."
  echo "$url"
}

cmd_status() {
  if is_running; then
    echo "läuft (PID $(cat "$PID_FILE")) — $(extract_url)"
  else
    echo "gestoppt"
    return 1
  fi
}

cmd_stop() {
  if is_running; then
    kill "$(cat "$PID_FILE")" 2>/dev/null || true
    sleep 1
    kill -9 "$(cat "$PID_FILE")" 2>/dev/null || true
    echo "Tunnel gestoppt."
  else
    echo "Tunnel läuft nicht."
  fi
  rm -f "$PID_FILE"
}

cmd_logs() {
  [[ -f "$LOG_FILE" ]] || die "Kein Log unter $LOG_FILE."
  tail -f "$LOG_FILE"
}

case "${1:-}" in
  start)  cmd_start ;;
  url)    cmd_url ;;
  status) cmd_status ;;
  stop)   cmd_stop ;;
  logs)   cmd_logs ;;
  *) echo "Verwendung: $0 {start|url|status|stop|logs}" >&2; exit 2 ;;
esac
