#!/usr/bin/env bash
#
# End-to-End-Smoke-Test gegen die LIVE-HTTP-API (Prod oder Staging).
#
# Geht ausschließlich über öffentliche Endpunkte — kein DB-Zugriff, kein
# TRUNCATE. Nutzt ein BESTEHENDES, verifiziertes Konto (Upload erfordert
# verifizierte E-Mail). Zeigt die rohen HTTP-Antworten, damit echte Fehler
# (422 mit Grund vs. 500) sichtbar werden — auch wenn auf dem Server kein
# Logfile geschrieben wird.
#
# Nutzung (interaktiv, empfohlen — keine Shell-Quoting-Probleme):
#   bash tests/smoke/prod_smoke.sh
#   -> fragt E-Mail + Passwort verdeckt ab
#
# Oder per Env (Vorsicht bei Sonderzeichen im Passwort):
#   SMOKE_EMAIL='du@example.com' SMOKE_PASSWORD='geheim' \
#     bash tests/smoke/prod_smoke.sh
#
# Optional:
#   BASE_URL=https://grava.world   (Default)
#
set -uo pipefail

BASE="${BASE_URL:-https://grava.world}"
EMAIL="${SMOKE_EMAIL:-}"
PASS="${SMOKE_PASSWORD:-}"

if [ -z "$EMAIL" ]; then
  printf 'E-Mail: '
  read -r EMAIL
fi
if [ -z "$PASS" ]; then
  printf 'Passwort: '
  read -rs PASS
  printf '\n'
fi
if [ -z "$EMAIL" ] || [ -z "$PASS" ]; then
  echo "E-Mail/Passwort fehlen. Abbruch."
  exit 1
fi

line() { printf '\n=== %s ===\n' "$1"; }

line "1) Login  POST $BASE/api/v1/auth/login"
LOGIN=$(curl -s -w $'\n%{http_code}' -X POST "$BASE/api/v1/auth/login" \
  -H 'Content-Type: application/json' \
  -d "{\"email\":$(printf '%s' "$EMAIL" | php -r 'echo json_encode(stream_get_contents(STDIN));'),\"password\":$(printf '%s' "$PASS" | php -r 'echo json_encode(stream_get_contents(STDIN));')}")
LOGIN_CODE=$(printf '%s' "$LOGIN" | tail -n1)
LOGIN_BODY=$(printf '%s' "$LOGIN" | sed '$d')
echo "HTTP $LOGIN_CODE"
echo "$LOGIN_BODY" | head -c 500; echo

TOKEN=$(printf '%s' "$LOGIN_BODY" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo is_array($d)&&isset($d["access_token"])?$d["access_token"]:"";')
if [ -z "$TOKEN" ]; then
  echo ">>> Kein access_token erhalten — Login fehlgeschlagen (verifiziert? richtiges Passwort?). Abbruch."
  exit 1
fi
echo ">>> Login OK (Token-Länge ${#TOKEN})."

# Minimal gültiges GPX (3 Trackpunkte).
TMP=$(mktemp "/tmp/smoke_XXXXXX.gpx")
cat > "$TMP" <<'GPX'
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="prod_smoke" xmlns="http://www.topografix.com/GPX/1/1">
  <trk><name>Smoke Test</name><trkseg>
    <trkpt lat="49.0000" lon="8.0000"><ele>100</ele></trkpt>
    <trkpt lat="49.0010" lon="8.0010"><ele>105</ele></trkpt>
    <trkpt lat="49.0020" lon="8.0020"><ele>110</ele></trkpt>
  </trkseg></trk>
</gpx>
GPX

line "2) Upload  POST $BASE/api/v1/routes"
curl -i -s -X POST "$BASE/api/v1/routes" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Client: ios" \
  -F "title=Smoke Test $(date +%s)" \
  -F "source=app" \
  -F "visibility=private" \
  -F "payload=@$TMP;type=application/gpx+xml" \
  | sed -n '1,60p'

line "3) Referrals  GET $BASE/api/v1/referrals/me"
curl -i -s "$BASE/api/v1/referrals/me" \
  -H "Authorization: Bearer $TOKEN" \
  | sed -n '1,60p'

line "4) Eigene Routen  GET $BASE/api/v1/routes?limit=3"
curl -i -s "$BASE/api/v1/routes?limit=3" \
  -H "Authorization: Bearer $TOKEN" \
  | sed -n '1,30p'

rm -f "$TMP"
printf '\n=== fertig ===\n'
