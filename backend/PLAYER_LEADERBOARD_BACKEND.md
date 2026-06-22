# Solo-/Spieler-Rangliste — Backend-Anweisung (S7)

**Audience:** Cursor / Backend-Agent. Die iOS-Seite (Plan S7) ist gebaut: `PlayerLeaderboardView`
(Einstieg über das „Mehr"-Menü in der Reviere-Toolbar) mit drei umschaltbaren Achsen und wartet auf
diesen einen Lese-Endpunkt. Muster wie die Crew-Rangliste (`CREW_LEADERBOARD_BACKEND.md`): reine
Aggregation beim Lesen, kein Recompute.

## Endpunkt
**`GET /api/v1/game/leaderboard`** — OptionalBearer (wie `/game/edges`): `scope=world` geht anonym;
`scope=friends`, `is_me` und der eigene Rang brauchen einen gültigen Bearer.

### Query-Parameter
| Param | Werte | Default | Bedeutung |
|---|---|---|---|
| `scope` | `world` \| `friends` | `world` | Grundgesamtheit. `friends` = Fahrer, denen der Anfragende folgt (Follow-Graph), inkl. sich selbst. |
| `window` | `week` \| `season` \| `all` | `season` | Zeitfenster: 7 Tage / 90 Tage / gesamt. |
| `metric` | `area` \| `pioneer` \| `value` \| `distance` | `area` | Sortier-/Anzeige-Kennzahl (siehe unten). |

> **Region kommt später.** v1 hat bewusst nur `world` + `friends`. Eine spätere Erweiterung wird
> `scope=region&bbox=minLon,minLat,maxLon,maxLat` ergänzen (gekoppelt an die Heimatzone, Plan S8).
> Bitte den `scope`-Switch erweiterbar halten.

### Antwort
```json
{
  "entries": [
    { "rank": 1, "handle": "armin", "value": 8400.0, "is_me": true },
    { "rank": 2, "handle": "lea",   "value": 7200.0, "is_me": false }
  ],
  "me": { "rank": 1, "value": 8400.0 }
}
```
- `entries`: absteigend sortiert nach `value`, fortlaufender `rank` (1-basiert). **Top-N kappen** (Vorschlag: 100). Bei Gleichstand deterministisch (z. B. nach `user_id`).
- `value`: je nach `metric` Länge in **Metern** (`area`/`distance`), **Anzahl** (`pioneer`) oder **Wert-Summe** (`value`). Die App formatiert selbst (m→km/mi etc.).
- `is_me`: markiert die Zeile des Anfragenden (nur mit Bearer; sonst überall `false`).
- `me`: Rang + Wert des Anfragenden **auch wenn außerhalb der Top-N** (`null`, wenn ausgeloggt oder ohne Daten). `rank`/`value` einzeln nullable.

## Kennzahlen (pro **einzelnem Fahrer**, NICHT Crew)
Gerankt wird die Einzelperson nach ihrem persönlichen Beitrag — analog zu den Pro-Mitglied-Kennzahlen
der Crew-Rangliste, aber global/über den Follow-Graph. Invalidierte Pässe ausschließen
(`WHERE invalidated_at IS NULL`).

- **`area`** — `held_length_m`: Σ Länge der Kanten, auf denen **dieser Fahrer der größte 90-Tage-Präsenz-Beitragende** ist (gleiche Definition wie `held_*` in der Crew-Rangliste, nur Bezug = Fahrer statt Mitglied-in-Crew). Präsenzbasiert ⇒ inhärent 90-Tage.
- **`pioneer`** — Anzahl Kanten, in deren **Pionier-Kohorte** (erste ≤10 Erstbefahrer) der Fahrer steht. Bei `window=week|season` nur Kanten, deren Erstbefahrung des Fahrers im Fenster liegt; bei `all` alle.
- **`value`** — Σ `game_edge.value` der Kanten, die der Fahrer hält (Top-Beitragender). Präsenzbasiert ⇒ 90-Tage.
- **`distance`** — gefahrene Distanz (Meter) des Fahrers im Fenster (aus Ride-/Pass-Daten, unabhängig vom Besitz).

### Zeitfenster-Hinweis
`area`/`value` sind modellbedingt **90-Tage-rollierend** (Präsenz). Für `window=all` deshalb wie `season`
behandeln (oder all-time-Präsenz, falls definiert) — Hauptsache konsistent und dokumentiert. `week`
grenzt die zugrunde liegenden Pässe/Erstbefahrungen/Distanzen auf 7 Tage ein. `distance`/`pioneer` ehren
alle drei Fenster sinnvoll.

## Akzeptanzkriterien
1. `scope=world&window=season&metric=area` (anonym) → 200 mit `entries[]` (rank fortlaufend, value absteigend), `is_me` überall false, `me=null`.
2. Mit Bearer → genau eine Zeile `is_me=true` (falls der Fahrer Daten in der Sicht hat) und `me` gefüllt, auch wenn der Fahrer außerhalb der Top-N liegt.
3. `scope=friends` ohne Bearer → 401; mit Bearer → nur gefolgte Fahrer (+ self), sonst leer (kein Fehler).
4. `metric` wechselt Sortierung/`value` korrekt; `window` grenzt die zugrunde liegenden Daten korrekt ab.
5. `area`/`value` summieren nur Kanten, bei denen der Fahrer Top-Präsenz-Beitragender ist; invalidierte Pässe ausgeschlossen.
6. Unbekannte/leere Werte → 0/leer, nie 500. Ungültige Param-Werte → 422 oder Default.
7. Reine Lese-Aggregation (recompute-neutral; später cachebar).

## iOS-Erwartung
Die App sendet `scope`/`window`/`metric` als Query-Params und zeigt `entries` mit Rang, `@handle`,
metrik-formatiertem `value` und hervorgehobener Eigen-Zeile (`is_me`) plus „Dein Rang" aus `me`.
Felder additiv — bitte alle mitsenden. Default-Sicht = `world` / `season` / `area`.
