# Fahrrad-Klassen erweitern + Bestenliste nach Motor-Gruppe — Backend-Delta (Cursor)

**Kontext:** Wir verfeinern die selbst deklarierten Fahrrad-Typen (Anzeige granular), **gruppieren die
Bestenlisten aber nach der Fairness-Achse Motor** (E-Bike ↔ Muskelkraft). Delta zu
`GAME_SEGMENT_SPEED_BACKEND.md` / `GAME_SEGMENT_SPEED_PLAN.md`. **Klein, aber Pflicht** — sonst tauchen
neue Fahrten nicht in der „Muskelkraft"-Liste auf.

Entschieden 2026-06-25. iOS ist bereits umgestellt (Build grün).

---

## 1. Neue `bike_class`-Werte (granular, pro Pass)

Die GPX-Extension `<ge:bikeType>` trägt jetzt einen von **fünf** Werten:

| Anzeige | `bike_class` |
|---|---|
| Rad (normal/Trekking) | `bike` |
| Gravelbike | `gravel` |
| Rennrad | `road` |
| Mountainbike | `mtb` |
| E-Bike | `ebike` |
| *(intern, nicht wählbar)* | `other` (Backfill/Alt-GPX) · `muscle` (Legacy-Testdaten) |

- **Granular speichern** auf `game_edge_pass.bike_class` (genau der Tag-Wert; fehlt der Tag → `other`, **nie raten**).
- **Keine Migration nötig:** vorhandene `muscle`/`other` bleiben gültig — sie fallen unten in die Muskel-Gruppe.

---

## 2. `/records?bike=` ist jetzt **motor-gruppiert** (NICHT mehr Exact-Match)

Der `bike`-Parameter wählt eine **Fairness-Gruppe**, nicht einen exakten Typ:

| `bike=` | Filter |
|---|---|
| `ebike`  | `bike_class = 'ebike'` |
| `muscle` | `bike_class <> 'ebike'` (also `bike`/`gravel`/`road`/`mtb`/`other`/`muscle`) |
| `all`    | kein Filter |

> **Warum Pflicht:** Die alte Version matchte `bike=muscle` exakt auf `bike_class='muscle'`. Neue Fahrten
> speichern aber `gravel`/`road`/`mtb`/`bike` → sie würden sonst aus der Muskel-Liste verschwinden.
> Nach der Umstellung umfasst `bike=muscle` **alle Nicht-E-Bike-Pässe**.

iOS sendet nur noch `bike=muscle|ebike|all` (die granulare Typ-Info dient Anzeige/Statistik, nicht dem Filter).

---

## 3. `metric=records` und `me.records_held` — gleiche Gruppierung

„Gehaltene Bestzeit" = Rang 1 **innerhalb der eigenen Motor-Gruppe** auf einer Kante:
- E-Bike-Fahrer konkurrieren nur mit E-Bikes; alle Muskel-Typen konkurrieren **gemeinsam** (Gravel vs. Rennrad
  vs. MTB in einem Topf — bewusste Produktentscheidung gegen Fragmentierung).
- `me.records_held` zählt entsprechend die Kanten mit Rang 1 in der Motor-Gruppe des Users.

---

## 4. Akzeptanztests (Delta)

1. **Granular gespeichert:** Upload mit `<ge:bikeType>gravel` → `game_edge_pass.bike_class = 'gravel'`.
2. **Muskel-Gruppe:** Ein `gravel`- und ein `road`-Pass auf derselben Kante → beide erscheinen unter
   `bike=muscle`, gegeneinander gerankt.
3. **E-Bike getrennt:** Ein `ebike`-Pass erscheint **nicht** unter `bike=muscle`, nur unter `bike=ebike` und `all`.
4. **Legacy:** vorhandene `muscle`/`other`-Pässe erscheinen unter `bike=muscle` (kein Datenverlust, keine Migration).
5. **Records-Zahl:** `metric=records` / `me.records_held` zählen Rang-1 je Motor-Gruppe (Muskel-Typen gebündelt).
