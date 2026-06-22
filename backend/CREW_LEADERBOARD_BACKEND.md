# Crew-Rangliste — Backend-Anweisung

**Audience:** Cursor / Backend-Agent. iOS-Seite (Plan S6) ist gebaut: `CrewLeaderboardView` mit drei
umschaltbaren Sichten (Präsenz / Gebiet / Aktivität), Zugang über „Rangliste" in `CrewView`. Wartet auf
diesen Endpunkt.

## Endpunkt
**`GET /api/v1/game/crews/{slug}/leaderboard`** (Bearer; nur Mitglieder der Crew). Antwort:
```json
{ "members": [
  { "handle": "armin", "role": "captain",
    "presence_contribution": 18.4,
    "held_edges": 23, "held_length_m": 5400.0,
    "activity_distance_m": 73200.0, "activity_rides": 9 }
] }
```
Alle Kennzahlen nullable (0/null bis Daten existieren). `role` = `captain|member`.

## Kennzahlen (alle aus `game_edge_pass` + Mitgliedschaft ableitbar)
Bezug: die Kanten, die der **Crew** aktuell gehören (`game_edge.owner_claimant_id` = Crew-Claimant).
- **`presence_contribution`** — Σ der **90-Tage-Präsenz** (gewichtete, tagesgedeckelte Pässe, `weight = max(0,1-age/90)`) des Mitglieds **auf den crew-eigenen Kanten**. Das ist der Beitrag des Mitglieds zum Halten des Gebiets (am engsten am Besitzmodell).
- **`held_edges` / `held_length_m`** — Gebiet, das das Mitglied „trägt": crew-eigene Kanten, auf denen **dieses Mitglied der größte Präsenz-Beitragende** ist (Tie → deterministisch). Summe Kanten + Länge.
- **`activity_distance_m` / `activity_rides`** — gefahrene Distanz/Fahrtenzahl des Mitglieds im 90-Tage-Fenster (aus den Ride-/Pass-Daten; unabhängig vom Besitz).

Invalidierte Pässe (Dashboard-Soft-Invalidierung) ausschließen (`WHERE invalidated_at IS NULL`).

## Akzeptanzkriterien
1. Nicht-Mitglied → 403; Mitglied → 200 mit `members[]` aller Crew-Mitglieder.
2. `presence_contribution` summiert korrekt die gewichteten Pässe des Mitglieds auf crew-eigenen Kanten.
3. `held_*` zählt nur Kanten, bei denen das Mitglied Top-Beitragender ist; Summe über alle Mitglieder ≤ Crew-Gesamtkanten.
4. `activity_*` spiegelt die Fahrten im Fenster.
5. Solo-/leere Crew → leere/0-Werte, kein Fehler.
6. Voll-Recompute-neutral (reine Aggregation beim Lesen; kann später gecacht werden).

## iOS-Erwartung
Die App sortiert je nach gewählter Sicht selbst (`presence_contribution` / `held_length_m` /
`activity_distance_m`). Default-Sicht = Präsenz. Felder additiv — bitte alle mitsenden.
