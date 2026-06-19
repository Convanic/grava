# GRAVA CI & Rebrand — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Das „Trail"-Designsystem (CI) in den Web-Seiten umsetzen und GravelExplorer → GRAVA (grava.world) nutzersichtbar + technisch umbenennen.

**Architecture:** Zentrale Design-Tokens als CSS Custom Properties in `public/assets/style.css` (`:root`) sind Single Source of Truth; Komponenten referenzieren nur Tokens. Bestehende Token-Namen bleiben als Aliase erhalten, damit kein Selektor bricht. Branding wird in Views, Controllern, E-Mails und Konfig über eindeutige String-Ersetzungen umgestellt. App-Icon als SVG-Monogramm + Apple-Touch-Icon.

**Tech Stack:** PHP 8.2 (servergerendert), reines CSS (keine Build-Pipeline), Leaflet-JS, PHPUnit 11.

**Bewusst stabil (NICHT umbenennen):**
- DB-Name `gravelexplorer` (nur `.env`).
- PHP-Namespace `App\`.
- GPX-XML-Namespace `https://gravelexplorer.benx.de/gpx/v1` (`NS_GE` in `src/Routes/SurfaceTrack.php:22` und `src/Routes/RouteHintParser.php:38`) — stabile Kennung in bestehenden iOS-Exporten; Änderung würde Parsing alter GPX brechen. Test-Fixtures (`tests/fixtures/*.gpx`) bleiben deshalb ebenfalls unverändert.

**Domain-Entscheidung:** Apex `grava.world` für Web + API. `APP_URL=https://grava.world`, `COOKIE_DOMAIN=grava.world`, Mail-Absender `no-reply@grava.world`.

---

## Task 1: Design-Tokens in `:root` setzen

**Files:**
- Modify: `public/assets/style.css:1-17`

- [ ] **Step 1: `:root` ersetzen**

Ersetze den bestehenden `:root`-Block (Zeilen 1–17) durch:

```css
:root {
    /* Brand */
    --primary: #2f5233;
    --primary-hover: #264227;
    --primary-weak: #e7ede2;
    --accent: #bf7a3a;
    --accent-hover: #a76a31;
    --accent-weak: #f3e7d4;
    --green-soft: #6b8e4e;

    /* Neutrals */
    --bg: #f4f1ea;
    --surface: #ffffff;
    --surface-alt: #faf8f3;
    --text: #2b2a26;
    --muted: #6f6a60;
    --border: #e6e1d6;
    --border-strong: #d6cfc0;

    /* Semantic */
    --success: #3c7d3a;
    --success-bg: #e7f4dc;
    --success-text: #2c5a17;
    --warning: #9a6b08;
    --warning-bg: #faecc9;
    --error: #a3342b;
    --error-bg: #f7e2df;
    --error-text: #a3342b;

    /* Aliase für Bestandscode (nicht entfernen) */
    --warn-bg: #faecc9;
    --warn-text: #7a5a04;

    /* Radii & Shadows */
    --radius-sm: 8px;
    --radius: 12px;
    --radius-lg: 14px;
    --radius-pill: 999px;
    --shadow-sm: 0 1px 3px rgba(43,42,38,.06);
    --shadow-card: 0 4px 14px rgba(43,42,38,.10);

    /* Layout */
    --max: 520px;
}
```

- [ ] **Step 2: Verifizieren (visuell)**

Öffne `http://gravelexplorer.test/login` und `/discover`. Erwartet: warmer Paper-Hintergrund, grüne Buttons, nichts kaputt (alle Selektoren nutzen weiterhin Tokens).

- [ ] **Step 3: Commit**

```bash
git add public/assets/style.css
git commit -m "feat(design): GRAVA Trail Design-Tokens in :root"
```

---

## Task 2: Akzent-Button & Komponenten-Feinschliff

**Files:**
- Modify: `public/assets/style.css` (Buttons-Bereich ~98-114 & ~212-252; Cards ~61-67; Tags ~303-311)

- [ ] **Step 1: Akzent-Button ergänzen**

Direkt nach der `.btn-secondary`-Regel (nach Zeile ~232) einfügen:

```css
.btn-accent {
    display: inline-block;
    background: var(--accent);
    color: #fff;
    padding: 8px 14px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 14px;
    border: 0;
    cursor: pointer;
}
.btn-accent:hover { background: var(--accent-hover); text-decoration: none; }
```

- [ ] **Step 2: Card-Hover-Schatten**

Erweitere die `.card`-Regel (Zeilen 61–67) um einen Hover-Schatten, indem du nach dem `.card { … }`-Block ergänzt:

```css
.card { transition: box-shadow .15s ease; }
.card:hover { box-shadow: var(--shadow-card); }
```

- [ ] **Step 3: Schwierigkeitsgrad-Chips ergänzen**

Nach der `.tag`-Regel (nach Zeile ~311) einfügen:

```css
.level { display:inline-block; border-radius: var(--radius-pill); padding:2px 10px; font-size:12px; font-weight:600; }
.level-easy   { background:#e7f4dc; color:#2c5a17; }
.level-medium { background:#faecc9; color:#7a5a04; }
.level-hard   { background:#f7e2df; color:#8a1f1f; }
.tag-new      { background: var(--accent-weak); color:#8a4f1c; }
```

- [ ] **Step 4: Verifizieren (visuell)**

Lade `/routes` und `/discover` neu. Erwartet: Buttons/Cards mit neuem Look, Hover-Schatten auf Karten.

- [ ] **Step 5: Commit**

```bash
git add public/assets/style.css
git commit -m "feat(design): Akzent-Button, Card-Hover, Schwierigkeitsgrad-Chips"
```

---

## Task 3: App-Icon „G" als Asset migrieren

**Files:**
- Create: `public/assets/brand/grava-icon.svg`
- Create: `public/assets/brand/apple-touch-icon.png` (aus SVG gerendert)
- Create: `public/favicon.svg`

- [ ] **Step 1: SVG-Monogramm anlegen**

Erstelle `public/assets/brand/grava-icon.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="180" height="180" viewBox="0 0 180 180" role="img" aria-label="GRAVA">
  <rect width="180" height="180" rx="40" fill="#2f5233"/>
  <text x="50%" y="52%" dominant-baseline="central" text-anchor="middle"
        font-family="-apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif"
        font-size="104" font-weight="800" fill="#f4f1ea">G</text>
</svg>
```

- [ ] **Step 2: favicon.svg anlegen**

Erstelle `public/favicon.svg` mit identischem Inhalt wie Step 1 (eigene Datei, da Browser `/favicon.svg` am Root erwartet).

- [ ] **Step 3: Apple-Touch-Icon (PNG) rendern**

PNG wird für iOS-Homescreen benötigt. Falls `rsvg-convert` oder ImageMagick vorhanden:

Run: `rsvg-convert -w 180 -h 180 public/assets/brand/grava-icon.svg -o public/assets/brand/apple-touch-icon.png`
Fallback: `magick public/assets/brand/grava-icon.svg -resize 180x180 public/assets/brand/apple-touch-icon.png`
Expected: PNG-Datei 180×180 entsteht.

Falls kein Renderer verfügbar: Schritt überspringen und im `<head>` (Task 4) nur `favicon.svg` + `apple-touch-icon` auf das SVG zeigen lassen (moderne iOS-Versionen akzeptieren auch SVG nicht zuverlässig → Renderer nachinstallieren empfohlen).

- [ ] **Step 4: Commit**

```bash
git add public/favicon.svg public/assets/brand/
git commit -m "feat(brand): GRAVA G-Monogramm als SVG-Icon + Apple-Touch-Icon"
```

---

## Task 4: Header-Logo-Lockup + Icon-Verlinkung im Layout

**Files:**
- Modify: `views/web/layout.php:18` (Title-Default), `:19` (head: Icon-Links), `:26` (Brand-Lockup), `:58` (Footer)
- Modify: `public/assets/style.css` (`.site-header .brand` ~45-49)

- [ ] **Step 1: Icon-Links im `<head>` ergänzen**

Nach `<link rel="stylesheet" href="/assets/style.css">` (Zeile 19) einfügen:

```php
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
```

- [ ] **Step 2: Brand-Lockup ersetzen**

Ersetze Zeile 26:

```php
        <a href="/" class="brand">GravelExplorer</a>
```

durch:

```php
        <a href="/" class="brand"><span class="brand-mark">G</span><span class="brand-word">GRAVA</span></a>
```

- [ ] **Step 3: Brand-CSS ersetzen**

Ersetze die `.site-header .brand`-Regel (Zeilen ~45-49) durch:

```css
.site-header .brand {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    font-weight: 800;
    font-size: 18px;
    letter-spacing: 0.10em;
    color: var(--text);
}
.site-header .brand:hover { text-decoration: none; }
.site-header .brand-mark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 9px;
    background: var(--primary);
    color: #f4f1ea;
    font-size: 17px;
    line-height: 1;
    letter-spacing: 0;
}
```

- [ ] **Step 4: Verifizieren (visuell)**

Lade eine beliebige Seite. Erwartet: grünes „G"-Tile + „GRAVA" im Header; Favicon im Tab.

- [ ] **Step 5: Commit**

```bash
git add views/web/layout.php public/assets/style.css
git commit -m "feat(brand): GRAVA Logo-Lockup im Header + Favicon/Touch-Icon"
```

---

## Task 5: E-Mail-Templates umfärben + umbenennen

**Files:**
- Modify: `views/email/verify_email.html.php:25,28,34,35,38` und `:15` (body bg)
- Modify: `views/email/reset_password.html.php:26,32,36` und `:13` (body bg)

- [ ] **Step 1: verify_email.html.php anpassen**

- Button-Farbe (Zeile 28): `background:#4a7c2a` → `background:#2f5233`
- Link-Farbe (Zeile 34): `color:#4a7c2a` → `color:#2f5233`
- Body-Text (Zeile 25): `willkommen bei GravelExplorer!` → `willkommen bei GRAVA!`
- Body-Text (Zeile 35): `Wenn du dich nicht bei GravelExplorer registriert hast` → `Wenn du dich nicht bei GRAVA registriert hast`
- Footer (Zeile 38): `&copy; <?= date('Y') ?> GravelExplorer` → `&copy; <?= date('Y') ?> GRAVA`

Hinweis: Die H1 (`$app_name`) wird über `MAIL_FROM_NAME` (Task 7) auf „GRAVA" gesetzt, nicht hier hartkodieren.

- [ ] **Step 2: reset_password.html.php anpassen**

- Button-Farbe (Zeile 26): `background:#4a7c2a` → `background:#2f5233`
- Link-Farbe (Zeile 32): `color:#4a7c2a` → `color:#2f5233`
- Footer (Zeile 36): `&copy; <?= date('Y') ?> GravelExplorer` → `&copy; <?= date('Y') ?> GRAVA`

- [ ] **Step 3: Verifizieren**

Run: `grep -rn "GravelExplorer\|#4a7c2a" views/email/`
Expected: keine Treffer mehr.

- [ ] **Step 4: Commit**

```bash
git add views/email/
git commit -m "feat(brand): E-Mail-Templates auf GRAVA-Farben + Name"
```

---

## Task 6: Nutzersichtbare Strings umbenennen (Views, Controller)

**Files (alle: `GravelExplorer` → `GRAVA`):**
- Modify: `views/web/layout.php:18` (Title-Default `'GravelExplorer'`), `:58` (Footer)
- Modify: `views/web/dashboard.php:48`
- Modify: `views/web/referral/landing.php:8,9`
- Modify: `src/Controllers/Web/WebView.php:33`
- Modify: `src/Controllers/Web/DashboardController.php:46`
- Modify: `src/Controllers/Web/DiscoveryPagesController.php:99,133,151,169,190,211,309,336,369`
- Modify: `src/Controllers/Web/PublicSharePageController.php:46,62`
- Modify: `src/Controllers/Web/RoutePagesController.php:66,80,132,143,166,174,220,244,281,302,449`
- Modify: `src/Controllers/Web/ReferralPagesController.php:43`
- Modify: `src/Controllers/Web/SettingsPagesController.php:42,67,79,98`
- Modify: `src/Controllers/Web/StravaPagesController.php:41`
- Modify: `src/Auth/AuthService.php:575,593` (`'app_name' => 'GravelExplorer'`)
- Modify: `src/Cli/Commands.php:286`
- Modify: `public/index.php:5` (Kommentar)

- [ ] **Step 1: Sed-Ersetzung über die nutzersichtbaren PHP-Dateien**

Alle Vorkommen sind eindeutig der Marken-Anzeigename (Titel-Suffixe `· GravelExplorer`, Footer, app_name, CLI-Banner). Run:

```bash
grep -rl "GravelExplorer" src/ views/ public/index.php \
  | xargs sed -i '' 's/GravelExplorer/GRAVA/g'
```

(Linux: `sed -i` ohne `''`.)

- [ ] **Step 2: Verifizieren**

Run: `grep -rn "GravelExplorer" src/ views/ public/`
Expected: keine Treffer mehr (GPX-Namespace ist `gravelexplorer` lowercase und bleibt — siehe Step 3).

- [ ] **Step 3: GPX-Namespace prüfen (darf NICHT geändert sein)**

Run: `grep -rn "gravelexplorer.benx.de/gpx/v1" src/Routes/`
Expected: `SurfaceTrack.php` und `RouteHintParser.php` enthalten die URI unverändert.

- [ ] **Step 4: Tests laufen lassen**

Run: `composer test`
Expected: Suite grün (Rename betrifft nur Anzeige-Strings).

- [ ] **Step 5: Commit**

```bash
git add src/ views/ public/index.php
git commit -m "feat(brand): nutzersichtbare Strings GravelExplorer -> GRAVA"
```

---

## Task 7: Technische Referenzen umbenennen (Konfig, API, Doku)

**Files:**
- Modify: `composer.json:2,3`
- Modify: `.env.example:2,26,27,41`
- Modify: `openapi.yaml:3,6,10`
- Modify: `src/Mail/MailService.php:61,83,117` (Default-Fallback `'GravelExplorer'` → `'GRAVA'`)
- Modify: `README.md` (Titel/Beschreibung + `COOKIE_DOMAIN`-Beispiel Zeile 89)
- Modify: `docs/API.md:16,281` (Base-URL prod)

- [ ] **Step 1: composer.json**

- Zeile 2: `"name": "gravelexplorer/backend"` → `"name": "grava/backend"`
- Zeile 3: `"description": "GravelExplorer backend ..."` → `"description": "GRAVA backend ..."`

Run danach: `composer dump-autoload`
Expected: ok, keine Fehler.

- [ ] **Step 2: .env.example**

- Zeile 2: `APP_URL=https://gravelexplorer.benx.de` → `APP_URL=https://grava.world`
- Zeile 26: `MAIL_FROM_ADDRESS=no-reply@gravelexplorer.benx.de` → `MAIL_FROM_ADDRESS=no-reply@grava.world`
- Zeile 27: `MAIL_FROM_NAME=GravelExplorer` → `MAIL_FROM_NAME=GRAVA`
- Zeile 41: `COOKIE_DOMAIN=gravelexplorer.benx.de` → `COOKIE_DOMAIN=grava.world`
- `DB_NAME=gravelexplorer` BLEIBT.

- [ ] **Step 3: openapi.yaml**

- Zeile 3: `title: GravelExplorer API` → `title: GRAVA API`
- Zeile 6: `... des GravelExplorer-Backends ...` → `... des GRAVA-Backends ...`
- Zeile 10: `- url: https://gravelexplorer.benx.de/api/v1` → `- url: https://grava.world/api/v1`

- [ ] **Step 4: MailService Default-Fallbacks**

In `src/Mail/MailService.php` die drei `'GravelExplorer'`-Defaults (Zeilen 61, 83, 117) → `'GRAVA'`.

- [ ] **Step 5: Doku**

- `README.md`: Titel `# GravelExplorer Backend` → `# GRAVA Backend`; Zeile 3 sinngemäß; Zeile 89 `COOKIE_DOMAIN=gravelexplorer.benx.de` → `COOKIE_DOMAIN=grava.world`.
- `docs/API.md` Zeile 16: Base-URL prod `https://gravelexplorer.benx.de` → `https://grava.world`. Zeile 281: GPX-Namespace-Erwähnung BLEIBT `gravelexplorer.benx.de/gpx/v1` (Kennung).

- [ ] **Step 6: Verifizieren**

Run: `grep -rn "gravelexplorer.benx.de" . --include='*.php' --include='*.yaml' --include='*.json' --include='*.md' | grep -v "gpx/v1"`
Expected: keine Treffer außer GPX-Namespace.
Run: `composer test`
Expected: grün.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock .env.example openapi.yaml src/Mail/MailService.php README.md docs/API.md
git commit -m "feat(brand): technische Referenzen auf GRAVA / grava.world"
```

---

## Task 8: Leaflet-Kartenfarben angleichen

**Files:**
- Modify: `public/assets/js/map-route.js:27`
- Modify: `public/assets/js/map-discover.js:86,88`

- [ ] **Step 1: map-route.js**

Zeile 27: `var BASE_COLOR = '#4a7c2a';` → `var BASE_COLOR = '#2f5233';`

- [ ] **Step 2: map-discover.js**

Zeile 86: `color: '#3c6622',` → `color: '#264227',`
Zeile 88: `fillColor: '#4a7c2a',` → `fillColor: '#2f5233',`

- [ ] **Step 3: Verifizieren (visuell)**

Lade `/discover` und eine Routen-Detailseite mit Karte. Erwartet: Tracks/Marker in neuem Grün.

- [ ] **Step 4: Commit**

```bash
git add public/assets/js/
git commit -m "feat(design): Leaflet-Kartenfarben auf GRAVA-Grün"
```

---

## Task 9: Cursor-Rule fürs Designsystem

**Files:**
- Create: `.cursor/rules/design-system.mdc`

- [ ] **Step 1: Rule anlegen** (Inhalt siehe Task „Cursor-Rule" — wird im nächsten Schritt separat erzeugt, da als Skill-gestützte Rule)

- [ ] **Step 2: Commit**

```bash
git add .cursor/rules/design-system.mdc
git commit -m "docs(design): Cursor-Rule fuer GRAVA Designsystem"
```

---

## Task 10: Abschluss-Verifikation

- [ ] **Step 1: Vollständiger Grep-Sweep**

Run:
```bash
grep -rn "GravelExplorer" . --exclude-dir=vendor --exclude-dir=.superpowers --exclude-dir=.git
grep -rn "gravelexplorer.benx.de" . --exclude-dir=vendor --exclude-dir=.git | grep -v "gpx/v1"
```
Expected: erste Suche nur Treffer in `docs/superpowers/` (Spec/Plan, historisch ok) und `tests/fixtures/*.gpx` (creator-Attribut, bewusst belassen); zweite Suche leer.

- [ ] **Step 2: Test-Suite**

Run: `composer test`
Expected: grün.

- [ ] **Step 3: Visueller Smoke-Test**

`/login`, `/register`, `/dashboard`, `/routes`, `/discover`, `/heatmap`, `/feed`: CI konsistent (Farben, Header-Lockup, Buttons, Cards). Test-E-Mail (z. B. Passwort-vergessen) prüfen: Absendername „GRAVA", grüner Button.

---

## Self-Review-Notizen

- **Spec-Abdeckung:** Tokens (T1), Komponenten inkl. Akzent/Chips (T2), Logo+Icon (T3/T4), E-Mails (T5), Rename nutzersichtbar (T6) + technisch (T7), Kartenfarben (T8, Ergänzung), Cursor-Rule (T9). Domain-/DB-/Namespace-Entscheidungen in T7 berücksichtigt.
- **Stabile Kennungen** (GPX-Namespace, DB-Name, `App\`) sind explizit ausgenommen und werden in T6/T7/T10 aktiv gegengeprüft.
- **Keine Platzhalter** in Code-Schritten; T9-Inhalt wird über die create-rule-Skill erzeugt (eigener, qualitätsgesicherter Schritt).
