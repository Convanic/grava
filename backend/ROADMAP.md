# Backend-Roadmap

**Audience:** Cursor / Backend-Agent. Zentrales Tracking der offenen Backend-Übergaben. Jede Zeile
verweist auf das ausführliche Handoff-Dokument (`*_BACKEND.md` / `*_SETUP.md`) mit Datenmodell,
API-Spec und Akzeptanzkriterien.

## Backlog

| Priorität | Thema | Endpunkt(e) | Migration? | Status | Doku |
|---|---|---|---|---|---|
| — | _(keine offenen Backend-Specs)_ | — | — | — | — |

> Stand per Audit ([`BACKEND_STATUS.md`](BACKEND_STATUS.md), 2026-06-24): Alle dokumentierten Backend-Specs
> sind umgesetzt. Segment-Speed wurde zuletzt gebaut (`SegmentSpeedTest`, grün).

## Erledigt (per Audit belegt)

| Thema | Endpunkt(e) | Beleg |
|---|---|---|
| Revier-Verlauf (Kanten-Historie) | `GET /game/me/history` | [`GAME_HISTORY_BACKEND.md`](GAME_HISTORY_BACKEND.md), `GameHistoryTest`, Migration `0042`, Cron `game:snapshot-daily` |
| Segment-Speed (Tempo-Wertung) | `GET /game/segments/{id}/leaderboard`, `GET /game/me/segments` | [`GAME_SEGMENT_SPEED_BACKEND.md`](GAME_SEGMENT_SPEED_BACKEND.md), `SegmentSpeedTest`, Migration `0026` |
| Follower-/Following-Listen | `GET /users/by-handle/{handle}/followers`, `…/following` | [`FOLLOW_LISTS_BACKEND.md`](FOLLOW_LISTS_BACKEND.md), `ProfileFollowListTest` |
| Solo-/Spieler-Rangliste | `GET /game/leaderboard` | [`PLAYER_LEADERBOARD_BACKEND.md`](PLAYER_LEADERBOARD_BACKEND.md), `PlayerLeaderboardTest` |
| Crew-Rangliste | `GET /game/crews/{slug}/leaderboard` | [`CREW_LEADERBOARD_BACKEND.md`](CREW_LEADERBOARD_BACKEND.md), `CrewLeaderboardTest` |
| Block/Unblock | `POST/DELETE /users/by-handle/{handle}/block`, `GET /users/me/blocks` | `BlockService`, `user_blocks` (0005) |
| Spiel-Stufen 1–3 (Solo/Crews/Fraktionen) | `…/game/edges`, `…/game/crews/*`, `…/game/factions/*` | `BACKEND_STATUS.md` (Prio B) |
| game_ingest / Valhalla / Chunking | `POST /game/ingest/{route_id}` + Upload-Hook | `BACKEND_STATUS.md` (Prio C) |

## Hinweise
- Reihenfolge in der Tabelle = grobe Bearbeitungs-Priorität, nicht verbindlich.
- „Migration? Nein" heißt: kein Schema-Eingriff nötig, höchstens additive Indizes.
- Details (Feld-Vertrag, Edge Cases, Datenschutz, Akzeptanzkriterien) stehen jeweils im verlinkten Dokument.
