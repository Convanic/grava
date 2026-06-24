# Rush / Group-Ride-Übernahme — Testreport

Spec: `backend/GAME_RUSH_BACKEND.md` (extern: `~/Documents/GravelExplorer/backend/GAME_RUSH_BACKEND.md`)
Stand: alle Tests grün — `vendor/bin/phpunit --testsuite integration` → **223 Tests, 842 Assertions, OK**.

Der Rush-Kern (Auto-Tag, Multiplikator, Hysterese-Übernahme) ist server-kanonisch:
`GameIngestionService` (Tag), `EdgeRecalculator::resolveRush` (Gate + Multiplikator + Edge-Cap),
`RushService` (Lifecycle/Endpunkte/Push). `game:recompute` ohne aktiven/qualifizierten Rush ist
bit-identisch (volle Suite grün, §9.7).

## Akzeptanzkriterien (§9) → Soll / Ist

| # | Kriterium | Test | Ist |
|---|---|---|---|
| 9.1 | Auto-Tag nur im Fenster `[start_at,end_at]` | `RushServiceTest::testAutoTagTagsPassesInsideWindowOnly` | Pass 11:00 → `rush_id` gesetzt, Pass 09:00 → NULL ✅ |
| 9.2 | Gate: < `rush_min_crew_size` kein Multiplikator, ab N greift er | `testTickExpiresWhenUnderMinCrew` (1 Fahrer → expired), `testRushMultiplierFlipsOwnershipAndIsOrthogonalWhenDisabled` (3 Fahrer → greift) | ✅ |
| 9.3 | `rush_stacks_with_group_bonus`: replace vs. Produkt | `testStacksWithGroupBonus` | replace 2.0× → Inhaber bleibt; stack 2.0×1.5 → Crew übernimmt ✅ |
| 9.4 | Übernahme nur via Hysterese, kein Sofort-Flip | `testRushMultiplierFlips…` (2.99 < Schwelle 3.41 → kein Flip; ×2.0 → Flip) | ✅ |
| 9.5 | Sanftes Scheitern → `expired`, keine Bestrafung | `testTickExpiresWhenUnderMinCrew` | Status `expired`, getaggte Pässe zählen normal ✅ |
| 9.6 | RSVP ohne Scoring-Wirkung | `testRsvpDoesNotAffectOwnership` (alle `no` → Besitz unverändert), `testRsvpUpsertAndMembershipGuard` | ✅ |
| 9.7 | Orthogonalität / bit-identisch ohne Rush | `testRushMultiplierFlips…` Teil (a) `rush_enabled=0` + volle Suite (223 grün) | ✅ |
| 9.8 | Captain-Gate (403) / Overlap+Cooldown (409) / window-Cap | `testCreateRequiresCaptain`, `testCreateRejectsOverlap`, `testCreateRespectsCooldown`, `testWindowHoursIsCappedAtMax` | 403/409/409, 99h→12h ✅ |
| 9.9 | Edge-Cap deterministisch nach `edge_id`, kein Silent-Cap | `testEdgeCapAppliesMultiplierToFirstNEdgesOnly` | kleinste edge_id bekommt Multiplikator, Rest regulär + Log-Zeile ✅ |
| 9.10 | Abbruch/Löschen neutralisiert Multiplikator | `testCancelAndDeleteNeutralizeMultiplier` | cancel → Inhaber zurück; `DELETE` → `rush_id` via `ON DELETE SET NULL` NULL ✅ |
| 9.11 | Statuszeit planned→active→completed/expired + `rush_result` | `testTickActivatesThenCompletesWhenQualified`, `testTickExpiresWhenUnderMinCrew` | Übergänge folgen `now`, Push-Hook ausgelöst (mockbar) ✅ |
| — | DTO-Live-Kennzahlen (§5.5) | `testMyRushDtoExposesLiveMetrics` | `participants_ridden`, `qualified`, `meetup_lat`, ISO-`start_at` ✅ |

## Definition of Done (§10)

- [x] Migration `0027_game_rush.sql`: `game_rush`, `game_rush_rsvp`, `game_edge_pass.rush_id` (+Indizes, FKs) + `game_config`-Keys + `user_notification_pref.rush`.
- [x] Auto-Tag im `game_ingest` (Fenster + Crew, ohne RSVP-Bedingung, nicht blockierend) — `GameIngestionService::matchRush`.
- [x] Gate + Multiplikator + Edge-Cap im `game:recompute` (replace/stack via Config) — `EdgeRecalculator::resolveRush`.
- [x] Lifecycle (lazy + `game:rush-tick`-Cron + `/internal/cron/rush-tick`) inkl. `rush_result`-Trigger — `RushService::tick`.
- [x] Endpunkte §5 (Captain-/Cooldown-/Überlappungs-Guards) + RSVP — `RushController` + Router.
- [x] Push `rush_invite/reminder/result` + Preference-Key `rush` (drei Typen am Schalter `rush`) — `PushService`, `NotificationPreferenceRepository`.
- [x] Akzeptanztests §9 grün + Testbericht; `game:recompute` ohne Rush bit-identisch (§9.7).
- [x] Alle `rush_*`-`game_config`-Werte ohne Deploy im Admin-Bereich änderbar — `GameConfigAdminService` (numeric/nullable/bool) + Admin-View iteriert `GameConfig::all()`.

## Konfiguration (Defaults, §2.4)

`rush_enabled=1`, `rush_multiplier=2.0`, `rush_stacks_with_group_bonus=0`, `rush_min_crew_size=3`,
`rush_window_hours=4`, `rush_window_hours_max=12`, `rush_max_edges_per_rush=` (∅ = unbegrenzt),
`rush_cooldown_days=7`, `rush_requires_announcement=1`, `rush_require_colocation=0`,
`rush_colocation_radius_m=100`, `rush_hysteresis_factor=` (∅ = erbt `hysteresis_factor`).

## Endpunkte

| Methode | Pfad | Auth | Zweck |
|---|---|---|---|
| POST | `/api/v1/game/crews/me/rush` | Bearer (Captain) | Rush anlegen |
| GET | `/api/v1/game/crews/me/rush` | Bearer | aktiver/nächster Rush + RSVPs (204 wenn keiner) |
| POST | `/api/v1/game/rush/{id}/rsvp` | Bearer (Mitglied) | yes/no/maybe |
| DELETE | `/api/v1/game/rush/{id}` | Bearer (Captain) | abbrechen (nur `planned`) |
| GET/POST | `/internal/cron/rush-tick` | Internal-Token | Cron-Statuszeit |

## §12 — captain-lose Crew heilen (Self-Healing)

| # | Kriterium | Test | Ist |
|---|---|---|---|
| 12.a | captain-los + Mitglied bestimmt gültigen Member → wird Captain; `me.captain_handle` stimmt | `CrewServiceTest::testClaimCaptainPromotesMemberWhenCaptainless` | ✅ |
| 12.b | Crew **mit** Captain → 409, Captain unverändert | `testClaimCaptainConflictsWhenCaptainExists` | ✅ |
| 12.c | Handle kein Mitglied → 404, kein Wechsel | `testClaimCaptainUnknownHandleReturns404` | ✅ |
| 12.d | Nicht-Mitglied (fremder Bearer) → 403 | `testClaimCaptainNonMemberReturns403` | ✅ |
| 12.3 | Idempotenz bei Mehrfachklick (2. Aufruf → 409-Guard) | `testClaimCaptainIsIdempotentOnDoubleClick` | ✅ |
| 12.2 | `captain_handle` in `GET /game/crews/me` (+`/{slug}`) | `testMePayloadExposesCaptainHandle` | ✅ |
| 12.1 | Datencheck + Heilung (ältestes aktives Mitglied) | `testHealCaptainlessCrewsPromotesOldestMember` | promotet + idempotent ✅ |
| 12.1 | Invariante bei Account-Löschung (Captain promoten / Solo auflösen) | `testAccountDeletionPromotesOldestRemainingMember`, `testAccountDeletionDissolvesSoloCrew` | ✅ |

**Umsetzung:**
- `captain_handle` zeigt nur den **aktiven** Captain — eine Captain-Zeile auf einem
  gelöschten Account gilt als captain-los (→ iOS-Recovery-UI greift).
- Invariante erzwungen in `CrewService`: `leave`/`transfer` (bestehend) **und**
  `handleAccountDeletion` (neu, via `AuthService::deleteAccount`).
- Datencheck/Heilung: `php public/index.php game:heal-crews` bzw.
  `POST /internal/game/heal-crews` (Internal-Token). Auf der Dev-DB aktuell keine
  Funde; auf Staging/Prod idempotent ausführbar.
- Neuer Endpunkt: `POST /api/v1/game/crews/{slug}/captain` (Body `{ "user_handle": "<member>" }`),
  Bearer + Crew-Mitgliedschaft, Audit `crew_captain_claim`.

## Out of Scope (§0)

- Co-Location-Geofence (v1 aus; Hook `rush_require_colocation` + Radius vorhanden).
- `rush_reminder`-Scheduling (Push-Titel/Preference vorhanden, Auslöser-Cron später).
- iOS-DTO-/View-Anbindung (Kontrakt §11/Plan §2–§3 erfüllt das Backend serverseitig).
