# GRAVA — CI & Designsystem (Spec)

**Datum:** 2026-06-19
**Status:** Entwurf zur Freigabe
**Scope:** Web-First — Umsetzung in diesem Repo (servergerenderte PHP-Seiten + E-Mails). iOS-App spiegelt dieselben Tokens später in einem eigenen Repo.

## 1. Marke

- **Name:** GRAVA (vormals „GravelExplorer")
- **Domain:** grava.world
- **Persönlichkeit:** rau & outdoor (Abenteuer, Schotter, Natur, erdig/robust) **kombiniert mit** community & einladend (freundlich, sozial, zugänglich).
- **Designhaltung:** Marken-Layer (Farbe, Logo, Bildsprache, Tonalität) ist plattformübergreifend gleich. Der Interaktions-Layer folgt pro Plattform den Konventionen (iOS → Apple HIG; Web → schlank, responsiv).

## 2. Logo & Icon

Gewählte Richtung: **Monogramm + Wortmarke**.

- **App-Icon:** „G"-Monogramm auf abgerundetem Tile (Forest-Green `#2f5233`, weißes „G", Radius ~13/14px). „GRAVA" als Volltext ist im kleinen Icon nicht lesbar — daher Monogramm.
- **Web-Header / Wortmarke:** Lockup aus „G"-Tile + Schriftzug **GRAVA** daneben.
  - Schriftzug: System-Font, `font-weight: 800`, Versalien, `letter-spacing: ~0.10em`, Farbe `text` (`#2b2a26`); das Tile in `primary`.
- **Regeln:**
  - Schutzraum um das Lockup ≥ Höhe des „G"-Tiles.
  - Auf dunklem Grund: Tile bleibt grün oder wird invertiert (heller Schriftzug `#f4f1ea`), Wortmarke hell.
  - Keine Verzerrung, kein Schlagschatten, keine zusätzlichen Effekte.

## 3. Design-Tokens (Light Mode)

Single Source of Truth: CSS Custom Properties in `:root` (`public/assets/style.css`). iOS übernimmt dieselben Werte später als Asset-Catalog/Swift-Konstanten.

### Farben

| Token | Hex | Verwendung |
|---|---|---|
| `--primary` | `#2f5233` | Primärfarbe (Forest-Green): Buttons, Links, Brand |
| `--primary-hover` | `#264227` | Hover/Pressed von primary |
| `--primary-weak` | `#e7ede2` | Grüner Tint-Hintergrund (Chips, aktive States) |
| `--accent` | `#bf7a3a` | Clay/Terracotta-Akzent: sekundäre CTAs, Highlights, „Neu" |
| `--accent-weak` | `#f3e7d4` | Akzent-Tint-Hintergrund |
| `--green-soft` | `#6b8e4e` | Sekundäres Grün (dekorativ, Verläufe, Thumbnails) |
| `--bg` | `#f4f1ea` | Seitenhintergrund (warmes „Paper") |
| `--surface` | `#ffffff` | Karten/Flächen |
| `--surface-alt` | `#faf8f3` | Alternative Fläche (Zebra, sekundäre Panels) |
| `--text` | `#2b2a26` | Primärtext (warmes Fast-Schwarz) |
| `--muted` | `#6f6a60` | Sekundärtext/Meta |
| `--border` | `#e6e1d6` | Standard-Rahmen |
| `--border-strong` | `#d6cfc0` | Stärkere Rahmen (Inputs, sekundäre Buttons) |
| `--success` / `--success-bg` | `#3c7d3a` / `#e7f4dc` | Erfolg |
| `--warning` / `--warning-bg` | `#9a6b08` / `#faecc9` | Warnung |
| `--error` / `--error-bg` | `#a3342b` / `#f7e2df` | Fehler |

> Hinweis: Die bestehenden Tokennamen (`--error-text`, `--warn-*`, `--success-text`) werden im Zuge der Umsetzung auf dieses Schema gemappt/vereinheitlicht (siehe Migrationshinweis unten).

### Schwierigkeitsgrade (Chips)

| Stufe | Hintergrund | Text |
|---|---|---|
| Leicht | `#e7f4dc` | `#2c5a17` |
| Mittel | `#faecc9` | `#7a5a04` |
| Schwer | `#f7e2df` | `#8a1f1f` |

### Radien, Schatten, Abstände

| Token | Wert |
|---|---|
| `--radius-sm` | `8px` |
| `--radius` | `12px` |
| `--radius-lg` | `14px` |
| `--radius-pill` | `999px` |
| `--shadow-sm` | `0 1px 3px rgba(43,42,38,.06)` |
| `--shadow-card` | `0 4px 14px rgba(43,42,38,.10)` (Hover) |
| Spacing-Skala | `4 · 8 · 12 · 16 · 24 · 32 · 48` |

### Typografie (System-Font)

Font-Stack bleibt: `-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif`.

| Rolle | Größe | Gewicht | Hinweise |
|---|---|---|---|
| H1 / Display | 30px | 700 | Seitentitel |
| H2 | 22px | 700 | Sektion |
| H3 | 17px | 600 | Karten-/Eintragstitel |
| Body | 16px | 400 | `line-height: 1.55` |
| Meta/Small | 13px | 400 | `--muted` |
| Label | 12px | 600 | Uppercase, `letter-spacing: .05em`, `--muted` |

## 4. Komponenten

- **Buttons:** `primary` (grün, weiß), `accent` (clay, weiß — sekundäre CTAs wie „Folgen"), `secondary` (weiß, Rahmen `--border-strong`), `ghost`/`link` (transparent, `--primary`). Radius `--radius-sm`/`--radius`, `font-weight: 600`.
- **Inputs:** weißer Grund, Rahmen `--border-strong`, Radius `--radius`, Focus-Ring `color-mix(in srgb, var(--primary) 30%, transparent)` + `border-color: var(--primary)`.
- **Cards:** `--surface`, `--border`, Radius `--radius-lg`, `--shadow-sm`; Hover `--shadow-card`.
- **Routen-Card:** Thumbnail (Verlauf `green-soft → primary` als Platzhalter), Schwierigkeits-Chip, Titel (H3), Meta-Zeile, Primär-Button.
- **Chips/Tags/Badges:** Pill-Form (`--radius-pill`); Kategorie (grüner Tint), „Neu" (Akzent-Tint), Schwierigkeitsgrade (siehe Tabelle).
- **Alerts/Flash:** success/warning/error mit jeweiligem `*-bg` + Textfarbe.
- **Header:** weiße Fläche, `--border`-Unterkante, links Logo-Lockup (Tile + GRAVA), rechts Navigation.

## 5. Light/Dark

- **Jetzt:** nur Light Mode sauber umsetzen.
- **Vorbereitung Dark Mode:** Alle Farben ausschließlich über Tokens referenzieren, damit ein späteres `@media (prefers-color-scheme: dark)` / `[data-theme="dark"]`-Override genügt. Keine hartkodierten Hex-Werte in Komponenten.

## 6. Domain & Rename-Entscheidungen

- **Domain-Aufteilung:** Alles auf der **Apex-Domain `grava.world`** (Web + API). `APP_URL=https://grava.world`, `COOKIE_DOMAIN=grava.world`.
- **PHP-Namespace:** bleibt `App\` (kein Namespace-Rename nötig).
- **Datenbankname:** bleibt `gravelexplorer` (rein interner, in `.env` konfigurierbarer Wert — keine DB-Migration, kein Risiko).
- **Rename-Tiefe:** nutzersichtbares **und** technisches Branding wird auf GRAVA/grava.world umgestellt (siehe unten), mit Ausnahme von DB-Name und Namespace.

## 7. Umsetzung im Repo (Überblick — Details im Implementation-Plan)

**Design / CI**

1. **Tokens** in `public/assets/style.css` `:root` aktualisieren/erweitern (neue Farben, Radien, Schatten, Akzent). Bestehende Tokennamen (`--error-text`, `--warn-*`, `--success-text`) auf das neue Schema vereinheitlichen und Verwendungsstellen anpassen.
2. **Komponenten** an die neuen Tokens angleichen (Buttons inkl. `accent`, Cards, Chips/Schwierigkeitsgrade, Inputs, Alerts, Header).
3. **E-Mail-Templates** (`views/email/*`) farblich angleichen.

**Logo / Icon**

4. **Logo-Lockup** als CSS-Komponente (`.brand` mit „G"-Tile + Wortmarke „GRAVA") in `views/web/layout.php`.
5. **App-Icon „G" als Asset migrieren:** SVG-Monogramm + abgeleitete Favicon-/Apple-Touch-Icon-Größen unter `public/assets/`, im `<head>` von `layout.php` verlinkt (`icon`, `apple-touch-icon`).

**Rename GravelExplorer → GRAVA (nutzersichtbar + technisch)**

6. **Nutzersichtbar:** Header-Brand, `<title>`-Default, Footer, alle Views/Templates, E-Mail-Texte, `MAIL_FROM_NAME=GRAVA`.
7. **Technisch:** `composer.json` (`name`, `description`), `.env.example` (`APP_URL`, `MAIL_FROM_ADDRESS=no-reply@grava.world`, `COOKIE_DOMAIN=grava.world`; `DB_NAME` unverändert), Default-Werte/Strings im Code (`src/**`), `openapi.yaml` (Server-URLs, Titel), Doku (`README.md`, `docs/**`), `apple-app-site-association`.
8. **Deploy-Hinweise:** Produktions-`.env` (`APP_URL`, `COOKIE_DOMAIN`, Mail-Absender) sind beim Cutover anzupassen; bestehende Sessions/Cookies auf der alten Domain werden ungültig.

**Konsistenz festschreiben**

9. **Cursor-Rule** `.cursor/rules/design-system.mdc` mit Tokens, Komponenten-Regeln und Do/Don'ts, damit künftige UI automatisch CI-konform gebaut wird.

## 8. Bewusst NICHT im Scope

- Dark Mode (nur vorbereitet).
- Custom Web-Fonts (System-Font gewählt).
- iOS-Umsetzung (nur Token-Spiegelung später).
- Umbenennung von **DB-Name** (`gravelexplorer` bleibt) und **PHP-Namespace** (`App\` bleibt).
- Repo-Verzeichnis-/Git-Remote-Umbenennung (separat, außerhalb des Codes).
- Foto-/Bildsprache-Guidelines (kann als Folge-Spec entstehen).
