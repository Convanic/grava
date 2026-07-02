# Revier-Verlauf (zeitliche Kennzahlen-Historie) — Backend Build-Order

**Audience:** Cursor / Backend-Agent. Der iOS-Client (Reviere-Tab → Mehr-Menü →
„Revier-Verlauf", `GameHistoryView`) zeigt einen zeitlichen Verlaufs-Chart der
eigenen Revier-Kennzahlen und ruft `GET /game/me/history` bereits auf (graceful:
leerer Zustand, bis Punkte vorliegen).

**Status:** **gebaut** (2026-07-02) — Route + Migration + Cron + Test vorhanden.
Belege siehe unten und in [`BACKEND_STATUS.md`](BACKEND_STATUS.md).

## Datenmodell / Migration

Neue additive Tabelle `migrations/0042_game_user_stats_daily.sql` — ein Tages-
Snapshot je Claimant (Rider **oder** Crew, konsistent mit `/game/me`):

```sql
CREATE TABLE game_user_stats_daily (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  claimant_id     BIGINT UNSIGNED NOT NULL,   -- FK game_claimant
  snapshot_date   DATE NOT NULL,              -- UTC-Kalendertag
  held_edges      INT NOT NULL,
  pioneered_edges INT NOT NULL,
  held_length_m   DOUBLE NOT NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  UNIQUE KEY uq_stats_daily (claimant_id, snapshot_date)
);
```

## Endpunkt

```
GET /api/v1/game/me/history?days=<n>        (RequireBearer)
```

- Effektiver Claimant wie `/game/me` (`GameRepository::effectiveClaimantId` — Crew,
  wenn Mitglied), damit `held_edges` mit `/game/me` übereinstimmt.
- `days` (optional, Default 365, geklammert 1…730): Fenster rückwärts ab heute (UTC).
- **Antwort** (snake_case, Client mappt auf camelCase):

```json
{ "points": [
  { "date": "2026-06-20", "held_edges": 42, "pioneered_edges": 7, "held_length_m": 18240.0 }
] }
```

- **`date` ist ein reines Datum „YYYY-MM-DD"** (kein ISO-Zeitstempel) — der iOS-Decoder
  akzeptiert sonst nur volle Zeitstempel und parst dieses Feld separat. Ein Punkt pro Tag,
  chronologisch, leere Tage weggelassen.

## Snapshot + Backfill (Zwei-Wege-Strategie)

Umgesetzt in `App\Game\GameHistoryService`:

- **Vorwärts (exakt):** je aktivem Claimant den heutigen Stand upserten
  (`allClaimantHoldings()` = zwei GROUP-BY-Abfragen; idempotent über den UNIQUE-Key).
- **Rückwärts (einmalig, beim ersten Lauf pro Claimant):** Backfill aus
  `game_edge.owner_since` / `discovered_at`. **Pionier ist exakt** (Erstbefahrer bleibt
  es dauerhaft); **gehaltene Kanten** sind die Wachstumskurve des HEUTE gehaltenen Reviers
  (seither verlorene Kanten fehlen — dokumentierte Näherung, weil der Event-Ledger
  `edge_lost`/`edge_reclaimed` derzeit nicht emittiert, siehe `GameEventRecorder`).

### Auslösung — drei Wege (Hoster ohne System-Cron: united domains)

1. **Self-Heal auf dem Lese-Pfad (kein Cron nötig):** `GET /game/me/history` ruft
   `ensureTodaySnapshot($claimant)` — schreibt den heutigen Punkt (und backfillt beim
   ersten Mal). Der Chart wächst also allein durchs Öffnen der Ansicht; deckt genau den
   Claimant ab, der ihn ansieht. **Das reicht für das Feature.**
2. **Interner HTTP-Trigger (für tägliche/alle-Claimants-Abdeckung ohne SSH/Cron):**
   `GET|POST /internal/cron/game-snapshot?token=<INTERNAL_TOKEN>` läuft `game:snapshot-daily`
   über HTTP (gleiches Muster wie `/internal/cron/game-dispatch`). Manuell aufrufbar oder
   von einem **externen Scheduler** (cron-job.org, EasyCron, UptimeRobot, GitHub-Actions-
   `schedule`) täglich anpingen. Ohne gesetzten `INTERNAL_TOKEN` → 404 (deaktiviert).
3. **System-Cron (falls verfügbar):** `php public/index.php game:snapshot-daily`
   (alias `cron:game-snapshot`), ~00:05 UTC.

## Datenschutz

Aggregierte Eigenwerte des anfragenden Claimants; kein Fremd-Bezug, keine Geometrie,
kein Standort. Bearer erzwungen. Bei Claimant-Löschung kaskadiert die Tabelle (FK).

## Akzeptanzkriterien

1. Ohne Snapshots liefert der Endpunkt `{"points":[]}` (Client zeigt leeren Zustand). ✅
2. `game:snapshot-daily` schreibt je Claimant genau eine Zeile pro Tag (idempotent). ✅
   Zusätzlich schreibt der Lese-Pfad `ensureTodaySnapshot` denselben Punkt (Self-Heal). ✅
3. Der jüngste Punkt stimmt mit `/game/me` (`held_edges`/`held_length_m`/`pioneered_edges`). ✅
4. Erster Lauf backfillt die Vergangenheit; `date` ist „YYYY-MM-DD". ✅
5. `days` klammert das Fenster (1…730). ✅

## Belege

- **Route:** `public/index.php` → `GET /api/v1/game/me/history` (RequireBearer).
- **Controller:** `GameController::history()`.
- **Service:** `App\Game\GameHistoryService` (`history` / `snapshotAll` / `backfill`).
- **Repository:** `GameRepository::{allClaimantHoldings,upsertDailySnapshot,hasDailySnapshots,dailySnapshots,edgeAcquisitionDates}`.
- **Cron:** `Commands::gameSnapshotDaily()` (`game:snapshot-daily`).
- **Migration:** `migrations/0042_game_user_stats_daily.sql`.
- **Tests:** `tests/Integration/Game/GameHistoryTest.php` (Backfill+Snapshot, Idempotenz, Fenster) — grün.
- **Client-Spec (iOS):** `GameHistory_Backend_Spec.md` im App-Repo.
