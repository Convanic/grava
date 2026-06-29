# Ereignis-Strom (Phase A Teil 1) + Spiel-Push (Phase B) — Design

**Datum:** 2026-06-29
**Specs (extern):** `backend/GAME_EVENTS_BACKEND.md` (Phase A), `backend/GAME_PUSH_BACKEND.md` (Phase B)
**Status:** freigegeben

## Ziel

1. **Phase A Teil 1:** Einen gemeinsamen **Spiel-Ereignis-Strom** (`game_event`) materialisieren — die
   einzige Quelle, auf der Push (Phase B) und später Challenges (Phase C) aufsetzen.
2. **Phase B:** Spiel-Ereignisse als **Inbox-Mitteilung + APNs-Push** zustellen — per-Typ steuerbar,
   **gebündelt (Digest über Zeitfenster)** bei vielen gleichartigen Ereignissen, mit Deep-Link (`edge_id`).

Die iOS-Seite ist fertig & defensiv: neue Typen/Felder rendern sofort, fehlen sie, ändert sich nichts.

## Architektur — „EIN Strom, Abnehmer"

```
Ride-Ingest (nach Recompute)
   └─ GameEventRecorder  → INSERT IGNORE game_event (idempotent)
                              (edge_taken, pioneer_joined, edge_new)

Cron: game:notify-dispatch
   └─ GameNotificationDispatcher
        → liest pending game_event (notified_at IS NULL, push-relevante Typen)
        → bündelt pro (Empfänger, Typ) über push_game_digest_window_min
        → NotificationService::notifyGame(recipient, ?actor, type, ?edge_id, ?count)
             → INSERT notifications (+ edge_id/count)
             → PushService::dispatch (per-Typ-Pref-gegated, edge_id im Payload)
        → markiert game_event.notified_at
```

Trennung Schreiben/Zustellen macht das Zeitfenster-Digest sauber und testbar und entkoppelt die
Konsumenten vom Ingest-Pfad.

## Datenmodell (Migration `0034_game_events_push.sql`)

```sql
CREATE TABLE game_event (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type          ENUM('edge_new','edge_taken','edge_lost','edge_reclaimed','record_beaten','pioneer_joined') NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,     -- Empfänger/Betroffener
  actor_user_id BIGINT UNSIGNED NULL,         -- auslösender Fahrer
  edge_id       BIGINT UNSIGNED NULL,
  ride_id       BIGINT UNSIGNED NULL,         -- route_id
  crew_id       BIGINT UNSIGNED NULL,
  ridden_on     DATE NULL,                    -- Idempotenz-Dimension
  payload       JSON NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  notified_at   DATETIME(3) NULL,             -- Dispatcher verarbeitet
  read_at       DATETIME(3) NULL,             -- Inbox (später)
  PRIMARY KEY (id),
  UNIQUE KEY uq_game_event_dedupe (type, user_id, edge_id, ridden_on),
  KEY idx_game_event_pending (notified_at, type, user_id, created_at),
  KEY idx_game_event_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Additiv (fixt nebenbei den latenten `rush_*`/`subject_type='rush'`-ENUM-Bug):

```sql
ALTER TABLE notifications
  MODIFY COLUMN type ENUM('follow','like','comment','territory_taken','crew_invite',
                          'edge_taken','edge_lost','edge_reclaimed','record_beaten','pioneer_joined',
                          'rush_invite','rush_reminder','rush_result') NOT NULL,
  MODIFY COLUMN subject_type ENUM('route','user','rush','edge') NULL,
  ADD COLUMN edge_id BIGINT UNSIGNED NULL AFTER subject_id,
  ADD COLUMN `count` INT UNSIGNED NULL AFTER edge_id;

ALTER TABLE user_notification_pref
  ADD COLUMN game_takeover TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN game_record   TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN game_pioneer  TINYINT(1) NOT NULL DEFAULT 0;
```

> MySQL behandelt NULLs in UNIQUE-Keys als verschieden; für `edge_new`/künftige Typen mit NULL-`edge_id`
> bleibt das Dedupe damit pro Zeile inaktiv — das ist gewollt (Idempotenz greift bei den `edge_*`-Typen,
> die `edge_id` + `ridden_on` setzen).

## Event-Emission (jetzt)

`GameEventRecorder` wird aus `GameIngestionService` **anstelle** des bisherigen
`TerritoryTakeoverNotifier` aufgerufen (kein Doppel-Notify). Eingaben: prevOwners/newOwners,
berührte Kanten, `ridden_on` je Kante, vor-Ingest-Snapshot „welche Kanten der Fahrer schon hatte".

- **`edge_taken`** — bisheriger Besitzer (alle User des verlierenden Claimants, Actor ausgeschlossen),
  `edge_id`, `ridden_on`. Priorität (Retention-Push).
- **`pioneer_joined`** — Empfänger = Entdecker-Claimant der Kante, wenn der hochladende Fahrer **neu**
  auf einer bereits von jemand anderem erschlossenen Kante ist.
- **`edge_new`** — Fahrer erschließt eine zuvor unerschlossene Kante (Recap/Challenge; **keine** Push-Zustellung).

Zurückgestellt (ENUM/Stream vorhanden, Emission später): `edge_lost`, `edge_reclaimed` (Reclaim-Historie),
`record_beaten` (§18 nicht live).

## Dispatcher (Phase B)

Config (in `GameConfig::DEFAULTS`): `push_game_digest_threshold=3`, `push_game_digest_window_min=60`.

Typ-Mapping (Event → Notification → Pref):
`edge_taken`→`edge_taken`/`game_takeover`; `edge_reclaimed`→`edge_reclaimed`/`game_takeover`;
`record_beaten`→`record_beaten`/`game_record`; `pioneer_joined`→`pioneer_joined`/`game_pioneer`.
`edge_new` wird **nicht** zugestellt (bleibt für Phase-C-Konsum offen, `notified_at` bleibt NULL).

Pro (Empfänger, Notification-Typ):
1. Pending-Events laden (push-relevante Typen, `notified_at IS NULL`).
2. Verarbeiten wenn **count ≥ Schwelle** (sofort) **oder** Fenster abgelaufen (`now − ältestes ≥ window_min`),
   sonst warten (nächster Lauf).
3. **≥ Schwelle** → **eine** Digest-Mitteilung (`count=N`, `actor=null`, `edge_id=null` ⇒ Tap öffnet Liste).
   **< Schwelle** (Fenster abgelaufen) → Einzel-Mitteilungen je Kante (`edge_id`, `actor`, `count=1`).
4. **Heimatzone (AC6):** Kante in aktivierter Privatzone des Empfängers ⇒ `edge_id=null` (kein Deep-Link).
5. Events `notified_at=now` setzen.

Push: System-Erlaubnis + Pref. Pref aus ⇒ Inbox ja, Push nein.

## Service-/API-Änderungen

- **`NotificationService`**: `notifyGame(int $recipientId, ?int $actorId, string $type, ?int $edgeId, ?int $count)`
  (Null-Actor erlaubt → ohne Self/Block-Filter; nur wenn Actor gesetzt, greifen die Filter).
  `list()` auf **LEFT JOIN** `users a` umstellen (sonst fällt die Digest-Zeile mit `actor=null` raus) und
  `edge_id`/`count` ausgeben (nur wenn gesetzt). Bestehende `notify()`-Signatur bleibt unverändert.
- **`PushService::dispatch`**: optionale `?int $edgeId`, `?int $count`; `edge_id` ins Custom-Payload (`{type, edge_id}`);
  deutsche Texte für die neuen Typen inkl. Digest (count > 1).
- **`NotificationPreferenceRepository`**: `TYPES` + `game_takeover`/`game_record`/`game_pioneer`; Mapping in
  `isPushEnabled()` (edge_*→game_takeover, record_beaten→game_record, pioneer_joined→game_pioneer);
  `GET/PUT /notifications/preferences` liefern/akzeptieren die drei Felder.
- **`Commands` + `public/index.php`**: CLI `game:notify-dispatch` + `/internal/cron/game-dispatch` (~minütlich schedulen).

## Akzeptanzkriterien (aus GAME_PUSH_BACKEND.md §6)

1. Übernahme (Hysterese) ⇒ `edge_taken` mit `edge_id` für den alten Besitzer; bei `game_takeover=true` + Erlaubnis APNs-Push.
2. `GET /notifications` liefert `edge_id` (+ ggf. `count`); Tap öffnet Kanten-Detail.
3. ≥ 3 Übernahmen im Fenster ⇒ **eine** Digest-Mitteilung `count=N` statt N Pushes.
4. `game_takeover=false` ⇒ kein Push, aber Mitteilung in der Inbox.
5. `game_pioneer` default aus ⇒ ohne Opt-in kein Pionier-Push.
6. Heimatzone-maskierte Kanten werden nicht verlinkt.

## Bewusst zurückgestellt

`record_beaten`/`edge_reclaimed`-Emission, Phase-C-Nutzung von `edge_new`, echte Empfänger-Lokalisierung
(Locale-Feld), Cross-Upload-Streak-Reminder-Push.
```
