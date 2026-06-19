#!/usr/bin/env bash
#
# sync_heatmap_edges.sh — Cutover-Modell A für die Heatmap-Streckenlinien (M6).
#
# Valhalla läuft NICHT in Prod. Die heatmap_edges-Tabelle wird lokal (gegen die
# lokale Valhalla) berechnet und nur das *Ergebnis* nach Prod übertragen — der
# Web-/API-Layer braucht zur Laufzeit nur diese Tabelle.
# Hintergrund: docs/PLAN_HEATMAP_MAPMATCH.md §12.
#
#   LOKAL:  scripts/sync_heatmap_edges.sh export [datei.sql]
#             -> php cron:heatmap-lines (Rebuild gegen lokale Valhalla)
#             -> mysqldump heatmap_edges (nur Daten) nach datei.sql
#
#   datei.sql per scp/rsync auf den Prod-Server kopieren, dann DORT:
#
#   PROD:   scripts/sync_heatmap_edges.sh import datei.sql
#             -> lädt in Shadow-Tabelle heatmap_edges_new
#             -> atomarer RENAME-Swap (kein Downtime), alte Tabelle wird gedroppt
#
# DB-Zugang kommt aus der .env (DB_HOST/PORT/NAME/USER/PASS/SOCKET).
# mysql/mysqldump aus PATH; bei MAMP o. Ä. via MYSQL_BIN/MYSQLDUMP_BIN überschreibbar,
# z. B.:  MYSQL_BIN=/Applications/MAMP/Library/bin/mysql ...
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/.env"
TABLE="heatmap_edges"
SHADOW="heatmap_edges_new"
OLD="heatmap_edges_old"

MYSQL_BIN="${MYSQL_BIN:-mysql}"
MYSQLDUMP_BIN="${MYSQLDUMP_BIN:-mysqldump}"
PHP_BIN="${PHP_BIN:-php}"

die() { echo "FEHLER: $*" >&2; exit 1; }

# Liest einen Schlüssel aus der .env (erste Zuweisung, ohne Quotes/Whitespace).
env_get() {
  local key="$1"
  [ -f "$ENV_FILE" ] || die ".env nicht gefunden: $ENV_FILE"
  local line
  line="$(grep -E "^[[:space:]]*${key}=" "$ENV_FILE" | head -n1 || true)"
  line="${line#*=}"
  line="${line%$'\r'}"                       # evtl. CR entfernen
  line="${line#\"}"; line="${line%\"}"        # umschließende " entfernen
  line="${line#\'}"; line="${line%\'}"        # umschließende ' entfernen
  printf '%s' "$line"
}

# Baut die gemeinsamen mysql/mysqldump-Verbindungsargumente.
build_conn_args() {
  DB_HOST="$(env_get DB_HOST)"; DB_PORT="$(env_get DB_PORT)"
  DB_NAME="$(env_get DB_NAME)"; DB_USER="$(env_get DB_USER)"
  DB_PASS="$(env_get DB_PASS)"; DB_SOCKET="$(env_get DB_SOCKET)"
  [ -n "$DB_NAME" ] || die "DB_NAME ist leer (.env)"

  CONN=()
  if [ -n "$DB_SOCKET" ]; then
    CONN+=(--socket="$DB_SOCKET")
  else
    CONN+=(--host="${DB_HOST:-127.0.0.1}" --protocol=TCP)
    [ -n "$DB_PORT" ] && CONN+=(--port="$DB_PORT")
  fi
  [ -n "$DB_USER" ] && CONN+=(--user="$DB_USER")
  # Passwort über MYSQL_PWD (nicht als Argument -> nicht in der Prozessliste).
  export MYSQL_PWD="$DB_PASS"
}

mysql_run()  { "$MYSQL_BIN" "${CONN[@]}" "$DB_NAME" "$@"; }

table_exists() {
  local t="$1" n
  n="$(mysql_run -N -B -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='${t}'")"
  [ "$n" = "1" ]
}

cmd_export() {
  local out="${1:-$ROOT/build/heatmap_edges.sql}"
  mkdir -p "$(dirname "$out")"

  echo ">> Rebuild lokal (cron:heatmap-lines gegen lokale Valhalla) ..."
  ( cd "$ROOT" && "$PHP_BIN" public/index.php cron:heatmap-lines )

  build_conn_args
  table_exists "$TABLE" || die "Tabelle ${TABLE} fehlt lokal — erst 'cli:migrate' laufen lassen."

  echo ">> Dump ${TABLE} (nur Daten) -> $out"
  # --no-create-info: nur INSERTs; Struktur kommt in Prod aus 'CREATE ... LIKE'.
  "$MYSQLDUMP_BIN" "${CONN[@]}" \
    --single-transaction --no-create-info --skip-add-locks --complete-insert \
    "$DB_NAME" "$TABLE" > "$out"

  local rows
  rows="$(grep -c 'INSERT INTO' "$out" || true)"
  echo "OK. Datei: $out  (INSERT-Statements: ${rows})"
  echo "Nächster Schritt: Datei auf Prod kopieren, dort 'import $out' ausführen."
}

cmd_import() {
  local file="${1:-}"
  [ -n "$file" ] || die "Usage: import <datei.sql>"
  [ -f "$file" ] || die "Datei nicht gefunden: $file"

  build_conn_args
  table_exists "$TABLE" || die "Tabelle ${TABLE} fehlt in Prod — erst Migration 0012 einspielen ('cli:migrate')."

  echo ">> Shadow-Tabelle ${SHADOW} (neu) aus ${TABLE}-Struktur erzeugen ..."
  mysql_run -e "DROP TABLE IF EXISTS \`${SHADOW}\`; CREATE TABLE \`${SHADOW}\` LIKE \`${TABLE}\`;"

  echo ">> Daten in ${SHADOW} laden ..."
  # INSERT-Ziel im Dump auf die Shadow-Tabelle umschreiben.
  sed "s/\`${TABLE}\`/\`${SHADOW}\`/g" "$file" | mysql_run

  local before after
  before="$(mysql_run -N -B -e "SELECT COUNT(*) FROM \`${TABLE}\`")"
  after="$(mysql_run -N -B -e "SELECT COUNT(*) FROM \`${SHADOW}\`")"
  echo "   ${TABLE}: ${before} Zeilen  ->  ${SHADOW}: ${after} Zeilen"
  [ "${after:-0}" -gt 0 ] || die "Shadow-Tabelle ist leer — Abbruch (kein Swap, ${TABLE} bleibt unverändert)."

  echo ">> Atomarer Swap (RENAME) ..."
  mysql_run -e "DROP TABLE IF EXISTS \`${OLD}\`; \
    RENAME TABLE \`${TABLE}\` TO \`${OLD}\`, \`${SHADOW}\` TO \`${TABLE}\`; \
    DROP TABLE \`${OLD}\`;"

  echo "OK. ${TABLE} jetzt mit ${after} Zeilen aktiv. (Rollback nicht nötig — Swap war atomar.)"
}

usage() {
  cat >&2 <<EOF
sync_heatmap_edges.sh — Cutover-Modell A (siehe Kopf der Datei)

  export [datei.sql]   LOKAL: Rebuild + Dump (Default: build/heatmap_edges.sql)
  import <datei.sql>   PROD : Shadow-Load + atomarer RENAME-Swap

Env-Overrides: MYSQL_BIN, MYSQLDUMP_BIN, PHP_BIN
EOF
  exit 1
}

case "${1:-}" in
  export) shift; cmd_export "$@" ;;
  import) shift; cmd_import "$@" ;;
  *)      usage ;;
esac
