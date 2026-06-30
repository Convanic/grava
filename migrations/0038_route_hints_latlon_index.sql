-- Kuratierung im Kantenwert (Gamification_Territory_Concept.md §5.3), v1.
-- Der EdgeRecalculator zählt beim Recompute positive Hinweise im Umkreis einer
-- Kante (Bounding-Box über game_edge.min/max_lat/lon). Dieser Index macht die
-- lat/lon-Bereichsabfrage auf route_hints effizient.
--
-- Einzel-Statement (idempotent genug: der Migrator führt jede Datei genau einmal
-- aus). Kein Schemarisiko — reiner zusätzlicher Sekundärindex.

SET NAMES utf8mb4;

ALTER TABLE route_hints ADD KEY idx_route_hints_latlon (lat, lon);
