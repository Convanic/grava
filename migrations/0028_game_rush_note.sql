-- Rush-Hinweis (Freitext). Siehe backend/GAME_RUSH_BACKEND.md §13.
-- Optionaler Captain-Hinweis (z. B. Treffpunkt-Beschreibung). REINE Anzeige als
-- Plaintext — kein Einfluss auf Gate/Multiplikator/Besitz. Serverseitig: trim,
-- auf 280 Zeichen gekappt, leerer String → NULL.
ALTER TABLE game_rush
  ADD COLUMN note VARCHAR(280) NULL AFTER meetup_lon;
