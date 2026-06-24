# Auto-Sync — iOS-Umsetzungs-Spec

**Audience:** iOS-Client / App-Agent (eigenes Repo). Kurze Build-Order für den automatischen Upload neuer
Fahrten/Routen ohne manuelles Zutun. Backend ist fertig — diese Spec beschreibt nur die Client-Seite und
die genutzten, bereits existierenden Endpunkte (Quelle der Wahrheit: [`docs/API.md`](../docs/API.md)).

## Ziel
Aufgezeichnete/importierte Fahrten landen **automatisch** und **genau einmal** auf dem Server, sobald
sinnvoll möglich (Vordergrund, Fahrtende, Netz vorhanden) — robust gegen Abbruch, Offline und Mehrfach-Trigger.

## Trigger (in dieser Reihenfolge, alle additiv)
1. **Fahrtende:** Sobald eine Aufzeichnung gestoppt/gespeichert wird, Upload anstoßen.
2. **App-Vordergrund:** Beim Aktivwerden die Pending-Queue abarbeiten.
3. **Background:** `BGProcessingTask` (BackgroundTasks-Framework) für Uploads, die im Hintergrund
   abgeschlossen werden; `URLSession` mit `background`-Konfiguration für Durchhalten über App-Suspends.
4. **Manueller Pull-to-Refresh** bleibt als Fallback erhalten.

## Datenfluss / Backend-Touchpoints
- Upload über die bestehenden Routen-Endpunkte (siehe [`docs/API.md`](../docs/API.md) §Routes):
  Erstellen/Hochladen der Route (GPX/Geometrie) per `POST /api/v1/routes`, danach Liste/Abgleich per
  `GET /api/v1/routes`.
- **Spiel-Ingestion passiert serverseitig automatisch** beim Upload (Upload-Hook). Der Client muss nichts
  zusätzlich triggern; `POST /api/v1/game/ingest/{route_id}` ist nur der manuelle Re-Run und **nicht** Teil
  des normalen Auto-Sync.
- Auth: Bearer (Access-Token), Refresh-Flow wie bestehend.

## Dedupe & Idempotenz (kritisch)
- Jede lokale Fahrt trägt eine **stabile Client-UUID**; diese als idempotenten Schlüssel mitsenden bzw. mit
  der `public_id` der Server-Route mappen, damit ein Retry **keine** Dublette erzeugt.
- Lokaler Sync-Status je Fahrt: `pending → uploading → synced → failed`. Nur `pending`/`failed` werden
  (erneut) hochgeladen; `synced` nie wieder.
- Server-Antwort (Route-`public_id`) lokal persistieren und die Fahrt als `synced` markieren.

## Retry / Fehlerbehandlung
- **Exponentielles Backoff** mit Jitter (z. B. 5s, 30s, 2min, 10min, Deckel ~1h); Reachability-getrieben:
  nur bei Netz aktiv.
- Fehlerklassen unterscheiden: `4xx Validierung` (kein blindes Retry → Fahrt als `failed` markieren,
  Nutzer informierbar) vs. `5xx`/Netz (`retrybar`). `503 routing_unavailable` betrifft nur die Ingestion
  serverseitig — der Upload selbst gilt dann trotzdem als erfolgreich.
- Abgebrochene Background-Uploads werden beim nächsten Trigger fortgesetzt (keine Korruption durch
  Teil-Upload — UUID-Idempotenz schützt).

## Einstellungen (Settings)
- Schalter **„Automatisch synchronisieren"** (Default an).
- Schalter **„Nur über WLAN"** (Default an) — Mobilfunk-Uploads optional.
- Statusanzeige: „zuletzt synchronisiert vor …", Anzahl ausstehender Fahrten, Fehler-Retry-Button.

## Datenschutz / Ressourcen
- Privatzonen/Trimming bleiben **serverseitig** wirksam — der Client muss nichts trimmen, aber die
  Auto-Sync-Einstellung respektiert „Nur WLAN" und vermeidet Uploads bei Low-Power-Mode (außer manuell).
- Keine Standortdaten ohne explizite Nutzerfreigabe hochladen.

## Akzeptanzkriterien
1. Eine beendete Fahrt wird ohne Nutzeraktion hochgeladen und erscheint serverseitig (Route + automatische Ingestion).
2. App-Neustart/Offline → die Fahrt bleibt `pending` und wird beim nächsten Netz/Vordergrund automatisch nachgereicht.
3. Doppelte Trigger (Fahrtende + Vordergrund + Background) erzeugen **keine** Dublette (UUID-Idempotenz).
4. `4xx` markiert die Fahrt als `failed` (kein Endlos-Retry); `5xx`/Netz wird mit Backoff erneut versucht.
5. „Nur über WLAN" verhindert Mobilfunk-Uploads; Ausschalten erlaubt sie.
6. Background-Upload überlebt App-Suspend (background `URLSession`) und wird abgeschlossen oder sauber fortgesetzt.
7. Die Sync-Statusanzeige spiegelt Pending/Failed/Last-Sync korrekt.
