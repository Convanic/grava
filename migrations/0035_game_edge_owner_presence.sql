-- Owner-Präsenz als materialisierter Live-Wert je Kante (GAME_IN_REACH_BACKEND.md).
-- Additiv & neutral: bestehende Kanten starten bei 0 und werden vom
-- EdgeRecalculator neu berechnet (wie value_cached/freshness_cached/
-- vulnerability_cached) — ein Voll-Recompute reproduziert den Wert identisch.
--
-- Bedeutung: aggregierte Präsenz des aktuellen Besitzer-Claimants auf der Kante
-- (Σ Tagesgewichte über die gültigen Pässe, inkl. Gruppen-/Rush-Bonus). Dient
-- der `in_reach`-Berechnung beim Lesen: eine fremde/freie Kante ist in
-- Reichweite, wenn P(du)+1 > owner_presence_cached × Hysterese. So fällt das
-- Flag bei der ohnehin nötigen Besitz-/Präsenz-Berechnung mit ab, ohne pro
-- Lese-Request alle Pässe je Kante neu zu aggregieren.

ALTER TABLE game_edge
  ADD COLUMN owner_presence_cached DOUBLE NOT NULL DEFAULT 0 AFTER vulnerability_cached;
