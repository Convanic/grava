# Splash-/Launch-Design — iOS-Umsetzungs-Spec

**Audience:** iOS-Client / App-Agent (eigenes Repo). Kurze Build-Order für den Start-/Splash-Auftritt im
GRAVA-Markenbild. Marken-Tokens stammen aus der CI-Spec
([`docs/superpowers/specs/2026-06-19-grava-ci-design.md`](../docs/superpowers/specs/2026-06-19-grava-ci-design.md))
— iOS spiegelt dieselben Werte als Asset-Catalog/Swift-Konstanten.

## Ziel
Ein markenkonformer, schneller, ruhiger Start: sofortiger statischer Launch-Screen + dezenter animierter
Splash-Übergang in die App. Kein „Werbe-Splash", keine künstliche Verzögerung.

## Zwei Ebenen
1. **Launch Screen (statisch, instant):** `LaunchScreen.storyboard`/SwiftUI-Launch — wird vom System
   **vor** App-Code gezeigt. Nur statisch: zentriertes „G"-Monogramm-Tile auf `--bg`. Keine Animation,
   kein Text-Lockup nötig (System rendert es ohne Logik).
2. **Animated Splash (App-gesteuert, kurz):** Direkt nach Launch ein SwiftUI-Overlay, das nahtlos aus dem
   Launch-Screen-Zustand startet (gleiches Tile, gleiche Position) und in ~0,4–0,8 s zum Lockup
   („G"-Tile + Wortmarke **GRAVA**) auflöst, dann in die erste App-Sicht überblendet.

## Marke / Tokens
- **Tile:** Forest-Green `#2f5233`, weißes „G", Eckenradius proportional (App-Icon-Look).
- **Hintergrund:** `--bg` `#f4f1ea` (warmes Paper) im Light Mode; **Dark Mode:** dunkler erdiger Grund,
  Tile bleibt grün **oder** invertiert, Wortmarke hell `#f4f1ea`.
- **Wortmarke:** System-Font, `weight 800`, Versalien, `letter-spacing ≈ 0.10em`, Farbe `--text` `#2b2a26`
  (Light) / hell (Dark).
- Schutzraum um das Lockup ≥ Höhe des Tiles. Keine Schatten/Effekte/Verzerrung.

## Animation
- Bewegung dezent: Tile leicht skaliert/„settled" (z. B. 1,06 → 1,0), Wortmarke faded/slidet von rechts ein.
- Gesamtdauer Splash **≤ 1,0 s**; danach Crossfade (≈0,2 s) in die App. Splash darf den Start **nicht**
  künstlich blockieren — sobald die App bereit ist, früher überblenden.
- **Kein** Spinner, solange < ~1 s. Dauert die Initialisierung länger, dezenten Fortschritts-Hint einblenden.

## Accessibility / Robustheit
- **Reduce Motion** (`accessibilityReduceMotion`): Animation durch sofortiges/Cross-Fade ersetzen.
- Dynamic Type beachtet die Wortmarke (sie ist Grafik-Lockup, skaliert proportional, nicht über Textgröße).
- Korrekt auf allen Größen/Notch/Dynamic-Island (Safe Areas), Hoch- und Querformat.
- VoiceOver: Splash ist dekorativ → als solches kennzeichnen (kein Fokusfang), App-Inhalt sofort fokussierbar.

## Assets
- App-Icon-Set + „G"-Monogramm als Vektor/PDF im Asset-Catalog (Light/Dark-Varianten).
- Farben als benannte Color-Assets (`primary`, `bg`, `text`, …) gemäß CI-Tokens — Single Source mit dem Web.

## Akzeptanzkriterien
1. Statischer Launch-Screen erscheint sofort (systemgerendert), zentriertes „G"-Tile auf Marken-Hintergrund.
2. Der animierte Splash startet **nahtlos** aus dem Launch-Screen-Zustand (kein sichtbarer Sprung/Flackern).
3. Splash dauert ≤ 1,0 s und blockiert den Start nicht künstlich; bei früher App-Bereitschaft wird früher überblendet.
4. Light- **und** Dark-Mode sind korrekt (Kontrast, invertierte Wortmarke), inkl. Querformat und Notch/Dynamic-Island.
5. Bei aktivem **Reduce Motion** läuft keine Bewegung — nur ein Cross-Fade.
6. Farben/Logo entsprechen exakt den CI-Tokens (`#2f5233`, `#f4f1ea`, `#2b2a26`, Lockup-Regeln); keine Effekte/Verzerrung.
7. Splash ist für VoiceOver dekorativ und fängt keinen Fokus.
