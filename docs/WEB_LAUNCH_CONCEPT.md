# GRAVA — Konzept: Publikumstaugliche Webpräsenz

**Zweck dieses Dokuments:** Konzept für die öffentliche Marketing-/Launch-Webseite von GRAVA, die das Produkt professionell darstellt und neue Nutzer akquiriert. Deckt Inhalt, Struktur, Design, Pflege und technische Umsetzung ab.

**Stand:** 2026-06-25
**Status:** Konzept zur Freigabe

---

## 1. Ausgangslage & Ziele

### 1.1 Ist-Zustand
Aktuell (gemäß `docs/ARCHITECTURE.md` §4.3) gibt es **keine öffentliche Marketing-/Landingpage**:
- `/` leitet auf `/dashboard` → erzwingt Login
- Anonyme Besucher landen auf dem Login-Formular
- Öffentliche Bereiche (`/discover`, `/heatmap`) existieren, aber sind nicht aus der Startseite verlinkt
- Keine Produktdarstellung, keine Call-to-Action für neue Nutzer

### 1.2 Ziele für den Launch
Eine publikumstaugliche Webpräsenz muss:
1. **Das Produkt GRAVA erklären** — Was ist es? Für wen? Warum nutzen?
2. **Zum Download der iOS-App führen** — primärer Funnel
3. **Öffentliche Features zeigen** — Entdecken, Heatmap, Community-Profile zugänglich machen
4. **SEO/Sharing-fähig sein** — Meta-Tags, OpenGraph, Sitemap
5. **Professionell & markenkonform wirken** — gemäß Designsystem „Trail" (`2026-06-19-grava-ci-design.md`)
6. **Pflegbar bleiben** — einfache Struktur, keine Over-Engineering

---

## 2. Produktpositionierung (Basis für alle Inhalte)

### 2.1 Kernbotschaft
**"Entdecke, bewerte und erobere Radstrecken — gemeinsam mit der Community"**

### 2.2 Unique Value Propositions
Aus `FEATURES.md` abgeleitet:
1. **Wegqualität objektiv messen** — Score 1–5 aus Vibrationssensor + GPS, für jeden Belag (Asphalt, Schotter, Kopfsteinpflaster, Feldweg)
2. **Territoriales Spiel** — Ingress-artiges Erobern realer Strecken (Reviere, Crews, Fraktionen)
3. **Community & Discovery** — Routen teilen, entdecken, Heatmap der besten Wege
4. **Privacy-first** — Heimatzonen-Schutz, local-first, Konto optional
5. **Strava-Integration** — Import & Export mit Revier-Reports

### 2.3 Zielgruppen (Primär → Sekundär)
1. **Radfahrer auf unbefestigten Wegen** — Gravel, Bikepacking, MTB-Touren; suchen neue Routen, wollen Qualität vorher kennen
2. **Alltagsradler & Pendler** — wollen ruhige, gut befahrbare Strecken finden; Kopfsteinpflaster vs. glatter Asphalt
3. **Strava-Power-User** — kennen KOM-Jagd, wollen das Konzept auf Territorien übertragen
4. **Local Communities** — Crews, die gemeinsam ihr Gebiet erschließen (Stadt & Land)

---

## 3. Inhaltsstruktur der öffentlichen Webseite

### 3.1 Seitenhierarchie

```
/ (Startseite)                       ← NEU, Haupt-Landingpage
│
├── /features                        ← NEU/angepasst: Funktionsübersicht (anonym zugänglich)
├── /how-it-works                    ← NEU: Erklärstrecke (optional, kann auch Teil von /)
├── /about                           ← NEU: Story, Mission, Team (optional für später)
│
├── /discover                        ← EXISTIERT, anonym (öffentliche Routen)
├── /heatmap                         ← EXISTIERT, anonym (Community-Daten)
│
├── /u/{handle}                      ← EXISTIERT, öffentliche Profile
├── /share/{token}                   ← EXISTIERT, geteilte Routen
│
├── /privacy                         ← EXISTIERT (Datenschutz)
├── /terms                           ← EXISTIERT (AGB)
│
└── /dashboard, /routes, …           ← EXISTIERT, Login-geschützt (unverändert)
```

### 3.2 Neue Seiten (Detailliert)

#### 3.2.1 Startseite `/` (Hero + Features + CTA)
**Ziel:** Binnen 10 Sekunden klar machen: "Das ist GRAVA, damit bewertest und eroberst du Radstrecken"

**Sektionen:**
1. **Hero (Above-the-Fold)**
   - H1: "Entdecke, bewerte und erobere Radstrecken"
   - Subheadline: "Die iOS-App, die deine Rides in ein Territorialspiel verwandelt — mit objektiver Wegqualität für jeden Belag (Asphalt, Schotter, Kopfsteinpflaster, Feldweg), Community-Heatmap und Crew-Eroberungen"
   - CTA-Buttons:
     - Primär: "Jetzt für iOS laden" → App Store (Badge)
     - Sekundär: "Routen entdecken" → `/discover`
   - Visuell: Hero-Bild/Screenshot (Karte mit eingefärbten Revieren, Live-Aufzeichnung oder Scoring-View)

2. **Features (3-Spalten-Grid)**
   - **Wegqualität für jeden Belag** (Icon: Vibration/Sensor)
     - "Score 1–5 aus deinem Beschleunigungssensor + GPS — ob Asphalt, Schotter, Kopfsteinpflaster oder Feldweg, du weißt vorher, wie rau die Strecke ist"
   - **Reviere erobern** (Icon: Karte/Territorium)
     - "Ingress-artiges Spiel: Fahre Strecken, erobere Kanten, bilde Crews, halte dein Gebiet — Stadt & Land"
   - **Community-Heatmap** (Icon: Crowd/Heatmap)
     - "Entdecke die besten Wege der Community, teile deine Routen, folge anderen Fahrern"

3. **Wie es funktioniert (3-Schritte-Flow)**
   - Schritt 1: **Aufzeichnen** — "Fahre deine Route (Straße, Radweg, Schotter, Feldweg), die App misst Vibration, GPS, Höhe, Verkehr (Radar)"
   - Schritt 2: **Bewerten** — "Automatischer Score 1–5 pro Segment, Belag-Mix erkennbar (glatter Asphalt bis rauer Schotter)"
   - Schritt 3: **Erobern** — "Kanten ins Spiel aufnehmen, Crews gründen, Reviere halten"

4. **Spiel-Hook (optional, aber stark)**
   - Großes Visual: Ingress-artige Karte (eingefärbte Kanten/Reviere)
   - "Solo, Crew oder Fraktion — erobere deine Region, werde Pionier auf neuen Wegen. Für jede Art von Radfahrer: ob du Gravel-Abenteuer suchst, zur Arbeit pendelst oder neue MTB-Trails erkundest"
   - Link: "Spielregeln verstehen" → `/features#game`

5. **Social Proof (wenn vorhanden)**
   - "Bereits X.XXX km aufgezeichnet, Y Crews aktiv — auf Asphalt, Schotter, Feldwegen und allem dazwischen" (Live-Zahlen aus der DB — optional)
   - "Strava-Integration: Import deine Aktivitäten, teile deine Eroberungen"

6. **Finaler CTA**
   - "Bereit, dein Gebiet zu erobern? Jetzt starten"
   - App-Store-Badge (offiziell) + Link zu `/discover` ("Erst stöbern")

7. **Footer**
   - Links: Funktionen, Entdecken, Heatmap, Datenschutz, AGB
   - "Powered by"-Attribution (falls Strava/OSM sichtbar eingebunden)
   - © 2026 GRAVA

#### 3.2.2 Funktionsübersicht `/features` (anonym zugänglich)
**Ziel:** Alle Features strukturiert darstellen — für Interessenten vor dem Download

**Struktur:**
- H1: "Funktionen"
- Intro: "GRAVA funktioniert auf allen Wegen — ob glatter Asphalt, rauer Schotter, Kopfsteinpflaster oder Feldweg"
- Gruppen (gemäß `FEATURES.md`):
  1. **Aufzeichnung & Wegqualität**
     - Score 1–5 für jeden Belag (Asphalt, Schotter, Kopfsteinpflaster, Feldweg), Halterungsprofile, Höhenmeter, Radar-Verkehr, Hinweise, Live Activity
  2. **Routen & Import/Export**
     - GPX-Import/-Export, CSV-Rohdaten, Detailansichten
  3. **Community & Teilen**
     - Cloud-Routen, Folgen, Likes/Kommentare, Feed, Heatmap, Einladungen
  4. **Strava-Integration**
     - OAuth-Verbindung, Aktivitäten-Import, Teilen mit Revier-Report
  5. **Territorialspiel „Reviere"**
     - Solo → Crews → Fraktionen, Kanten-/Revier-Logik, Ranglisten, Rush-Events — Stadt & Land, jede Art von Strecke
  6. **Privatsphäre & Sicherheit**
     - Heimatzone/Schutz, Privacy-Manifest, local-first
- Pro Feature: kurze Beschreibung (2–3 Sätze) + Icon/Screenshot
- CTA am Ende: "Jetzt ausprobieren" → App Store

#### 3.2.3 Über uns `/about` (optional, Phase 2)
**Ziel:** Mission, Story, ggf. Team-Vorstellung

**Inhalt (Beispiel-Draft):**
- H1: "Über GRAVA"
- "Wir sind Radfahrer, die frustriert waren, vor jeder Tour zu rätscheln: Ist der Weg fahrbar? Wie rau ist er? Ist die Straße ruhig oder stark befahren? Wo fährt die Community eigentlich?
  GRAVA ist unsere Antwort: objektive Wegqualität aus Sensorik für jeden Belag + ein Spiel, das echtes Erkunden belohnt."
- Mission: "Jeder Weg soll kartiert, bewertet und teilbar sein — von der Community, für die Community. Egal ob Asphalt, Schotter oder Feldweg."
- Werte: Privacy-first, open-data-freundlich (OSM), fair-play (Anti-Cheat)
- CTA: "Teil der Community werden"

### 3.3 Anpassungen bestehender Seiten

#### `/discover` (EXISTIERT, minimal anpassen)
- Header: sicherstellen, dass anonyme Nutzer klare Navigation sehen (Logo → `/`, "App laden")
- Intro-Text ergänzen: "Durchstöbere öffentliche Routen der Community — Straßen, Radwege, Schotterpisten, alles ist dabei. Lade die App, um selbst zu teilen"

#### `/heatmap` (EXISTIERT, minimal anpassen)
- Intro-Text: "Sieh, wo die Community unterwegs ist — auf Asphalt, Schotter und allem dazwischen. Grün = Einzelfahrer, orange/rot = Crowd-Hotspots"

#### `/dashboard` (bleibt Login-geschützt, aber Redirect anpassen)
- Aktuell: `/` → `/dashboard` (erzwingt Login)
- Neu: `/` bleibt die öffentliche Startseite; `/dashboard` nur bei direktem Aufruf oder nach Login erreichbar

---

## 4. Design & Markenkonformität

### 4.1 Designsystem-Compliance
Alle neuen Seiten folgen strikt `2026-06-19-grava-ci-design.md`:
- **Farbpalette:** `--primary` (Forest-Green), `--accent` (Clay), `--bg` (Paper), `--surface` (weiß)
- **Typografie:** System-Font-Stack, H1 30px/700, H2 22px/700, Body 16px/400
- **Komponenten:**
  - Buttons: `.btn-primary` (grün), `.btn-accent` (clay, sekundär), `.btn-link`
  - Cards: `.card` mit `--radius-lg`, `--shadow-sm`
  - Hero: großer Verlauf (Sand → Paper), zentrierter Content-Block
  - Feature-Grid: 3 Spalten (Desktop), 1 Spalte (Mobile), Icons + H3 + Beschreibung
- **Spacing:** 8px-Raster, Sektionen mit `32–48px` Abstand
- **Logo:** `.brand`-Lockup (G-Tile + Wortmarke „GRAVA") im Header
- **Responsiv:** Mobile-first, Breakpoints `min-width: 768px` (Tablet), `1024px` (Desktop)

### 4.2 Bildsprache (Empfehlungen)
- **Hero/Screenshots:** Live-Aufzeichnung mit Score-Overlay, eingefärbte Karte (Reviere), Crew-Rangliste
- **Icons:** System-/SF-Symbols-Stil (Linie, nicht gefüllt), Farbe `--primary` oder `--accent`
- **Karten:** Leaflet-Maps mit GRAVA-Marken-Stil (grüne Overlays, Sand-Hintergrund)
- **Vermeide:** Stock-Fotos von lachenden Radfahrern — zeige das Produkt, nicht Klischees

### 4.3 Tonalität (aus CI-Spec §1)
- **Rau & outdoor** — ehrlich, direkt, keine Marketing-Floskeln ("erobere", "erschließe", "halte dein Gebiet")
- **Community & einladend** — freundlich, inklusiv ("gemeinsam", "teile", "entdecke")
- **Technisch fundiert** — Sensorik, GPS, Valhalla-Map-Matching erwähnen (baut Glaubwürdigkeit)
- **Deutsch-First, Englisch parallel** — alle Texte zweisprachig (Locale-Switch im Header)

---

## 5. SEO & Sharing

### 5.1 Meta-Tags (pro Seite)
Template-Variablen in `views/web/layout.php`:
```php
$_pageTitle = "GRAVA — Entdecke, bewerte und erobere Radstrecken";
$_pageDescription = "Die iOS-App für Radfahrer: objektive Wegqualität für jeden Belag (Asphalt, Schotter, Kopfsteinpflaster), Territorialspiel, Community-Heatmap. Jetzt kostenlos laden.";
$_pageImage = "https://grava.world/assets/brand/og-image.jpg"; // 1200×630px
```

#### Startseite `/`
```html
<title>GRAVA — Entdecke und erobere Radstrecken</title>
<meta name="description" content="Die iOS-App, die deine Rides in ein Territorialspiel verwandelt. Objektive Wegqualität für jeden Belag, Community-Heatmap, Crew-Eroberungen.">

<!-- OpenGraph -->
<meta property="og:title" content="GRAVA — Radstrecken bewerten & erobern">
<meta property="og:description" content="Ingress-artiges Territorialspiel für Radfahrer. Wegqualität-Score 1–5 für jeden Belag, Reviere halten, Crews gründen.">
<meta property="og:image" content="https://grava.world/assets/brand/og-image.jpg">
<meta property="og:url" content="https://grava.world/">
<meta property="og:type" content="website">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="GRAVA — Radstrecken erobern">
<meta name="twitter:description" content="Die iOS-App für objektive Wegqualität (jeden Belag) und Territorialspiel.">
<meta name="twitter:image" content="https://grava.world/assets/brand/twitter-card.jpg">
```

#### Features, Discover, Heatmap
- Angepasste Titles/Descriptions (kurz, fokussiert)
- Gleiche OG-Image (Marken-Hero), außer bei geteilten Routen (dort Route-Karte)

### 5.2 Sitemap `public/sitemap.xml`
```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://grava.world/</loc>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>
  <url><loc>https://grava.world/features</loc><priority>0.8</priority></url>
  <url><loc>https://grava.world/discover</loc><priority>0.8</priority></url>
  <url><loc>https://grava.world/heatmap</loc><priority>0.7</priority></url>
  <url><loc>https://grava.world/privacy</loc><priority>0.3</priority></url>
  <url><loc>https://grava.world/terms</loc><priority>0.3</priority></url>
</urlset>
```
→ Statische Datei, manuell pflegen (oder per CLI-Befehl generieren lassen — Phase 2)

### 5.3 Robots.txt `public/robots.txt`
```
User-agent: *
Allow: /
Disallow: /dashboard
Disallow: /routes
Disallow: /settings
Disallow: /admin

Sitemap: https://grava.world/sitemap.xml
```

### 5.4 Strukturierte Daten (Schema.org, optional Phase 2)
- `WebApplication` für die App (Name, URL, Plattform iOS)
- `Organization` für GRAVA (Logo, Social-Links)

---

## 6. Cookie-Consent & Analytics

### 6.1 Ist-Zustand
- Google Analytics (gtag) ist eingebunden (`/assets/js/ga.js`)
- **Aktuell kein Consent-Banner** → nicht DSGVO-konform für öffentlichen Launch

### 6.2 Empfehlung
**Minimaler Consent-Banner** (schlank, keine Cookie-Monster-Popup-Hölle):
- Bei erstem Besuch: Banner am unteren Rand
  - "Wir nutzen Google Analytics, um die Seite zu verbessern. [Akzeptieren] [Ablehnen] [Mehr erfahren]"
- Akzeptieren → GA startet, Cookie `grava_consent=accepted` (1 Jahr)
- Ablehnen → GA nicht laden, Cookie `grava_consent=declined`
- "Mehr erfahren" → `/privacy#cookies`

**Technische Umsetzung:**
- Cookie-Consent-Script in `layout.php` (Vanilla-JS, kein Framework nötig)
- `ga.js` nur laden, wenn `grava_consent=accepted`
- Alternative (DSGVO-freundlicher): **Plausible.io** oder **Matomo** (self-hosted) statt Google Analytics

---

## 7. Technische Umsetzung

### 7.1 Architektur (gemäß `docs/ARCHITECTURE.md`)
- **Stack:** PHP 8.2+ / MySQL, server-gerenderte Views (`views/web/*.php`)
- **Front-Controller:** `public/index.php`, Routing über `App\Http\Router`
- **Controller:** Neuer `App\Controllers\Web\MarketingController` für `/`, `/features`, `/about`
- **Views:** `views/web/home.php`, `views/web/features.php`, `views/web/about.php` (nutzen `layout.php`)
- **Assets:** `public/assets/style.css` (Designsystem bereits vorhanden), ggf. neue Icons/Bilder

### 7.2 Routen-Registrierung (`public/index.php`)
```php
// Öffentliche Marketing-Seiten (Web-Controller)
$router->get('/', [new MarketingController(), 'home']);
$router->get('/features', [new MarketingController(), 'features']);
$router->get('/about', [new MarketingController(), 'about']); // optional

// Dashboard bleibt geschützt, aber kein Auto-Redirect mehr von /
$router->get('/dashboard', [new DashboardController(), 'index'], [new CookieAuth()]);
```

### 7.3 Views-Struktur
```
views/web/
  layout.php                 (bestehend, Header/Footer)
  home.php                   (NEU, Startseite)
  features.php               (NEU oder bestehende anpassen)
  about.php                  (NEU, optional)
  discover/index.php         (bestehend, ggf. Intro-Text ergänzen)
  heatmap.php                (bestehend, ggf. Intro-Text ergänzen)

views/web/partials/
  hero.php                   (NEU, Hero-Sektion für Home)
  feature-grid.php           (NEU, 3-Spalten-Feature-Cards)
  app-cta.php                (NEU, App-Store-Badge + Buttons)
```

### 7.4 Assets
```
public/assets/brand/
  logo-lockup.svg            (G-Tile + GRAVA-Wortmarke, inline in layout.php)
  og-image.jpg               (1200×630px, Social-Sharing)
  twitter-card.jpg           (1200×600px, Twitter)
  app-store-badge.svg        (offizieller Apple-Badge, Download Link)
  screenshots/
    hero-recording.jpg       (Hero-Bild, Aufzeichnung)
    map-territories.jpg      (Karte mit Revieren)
    crew-leaderboard.jpg     (Crew-Rangliste)
```

### 7.5 Lokalisierung
- **Deutsch-First:** alle neuen Views auf Deutsch schreiben
- **Englisch parallel:** `$_locale`-Switch im Header (Cookie oder Browser-Header)
- Texte als PHP-Variablen/Arrays in Views (kein i18n-Framework nötig im MVP):
  ```php
  $texts = [
    'de' => ['hero_h1' => 'Entdecke, bewerte und erobere Gravel-Strecken', ...],
    'en' => ['hero_h1' => 'Discover, rate and conquer gravel routes', ...],
  ];
  ```

---

## 8. Content-Pflege & Workflow

### 8.1 Content-Verantwortung
- **Produktbeschreibungen:** aus `FEATURES.md` ableiten → bei Feature-Änderungen auch Web-Features-Seite updaten
- **Screenshots/Bilder:** aus der iOS-App exportieren (Xcode-Simulator, Export 2× Retina)
- **Changelog/News:** später optional `/news` oder `/changelog`-Seite (aktuell nur in `CHANGELOG.md`)

### 8.2 Workflow für Änderungen
1. **Texte:** direkt in `views/web/*.php` bearbeiten (PHP-Templates, kein CMS)
2. **Bilder:** in `public/assets/brand/` ablegen, in Views referenzieren
3. **Design/CSS:** nur über Tokens in `style.css`, keine hartkodierten Farben
4. **Deployment:** Git-Push → Server zieht (`git pull`), kein Build-Schritt nötig (PHP)

### 8.3 Zukünftige Erweiterungen (Phase 2/3)
- **Blog/News-Sektion** (`/news`) — Feature-Updates, Community-Highlights (optional CMS oder Markdown-Files)
- **FAQ** (`/faq`) — häufige Fragen (z. B. "Brauche ich ein Konto?", "Funktioniert es mit E-Bikes?")
- **Presse/Media-Kit** (`/press`) — Logo-Downloads, Screenshots, Pressemitteilung
- **Dynamische Sitemap** — generiert aus DB (öffentliche Routen, Profile)

---

## 9. Launch-Checkliste (Priorität MVP → P2 → P3)

### MVP (Launch-kritisch)
- [ ] **Startseite `/`** (Hero + Features + CTA) implementieren
- [ ] `/features` anonym zugänglich machen (ggf. bestehende View anpassen)
- [ ] Header-Navigation für anonyme Besucher (Logo → `/`, "App laden", "Entdecken", "Heatmap")
- [ ] Footer (Links, Copyright)
- [ ] Meta-Tags/OpenGraph für `/`, `/features`, `/discover`, `/heatmap`
- [ ] OG-Image/Twitter-Card erstellen (`og-image.jpg`, 1200×630px)
- [ ] App-Store-Badge einbinden (offizieller Apple-Badge, Link)
- [ ] `sitemap.xml` + `robots.txt` erstellen
- [ ] Cookie-Consent-Banner (GA-Opt-In/-Out)
- [ ] `/privacy` + `/terms` final prüfen (Cookie-Hinweis ergänzen)
- [ ] Responsive-Test (Mobile, Tablet, Desktop)
- [ ] Lokalisierung DE+EN (mindestens Hero + Features)
- [ ] Produktions-`.env`: `APP_URL=https://grava.world`, `COOKIE_DOMAIN=grava.world`

### P2 (Nice-to-have, nach Launch)
- [ ] `/about` (Mission, Story, Team)
- [ ] `/how-it-works` (ausführliche Erklärstrecke mit Screenshots)
- [ ] Spiel-Regeln-Sektion auf `/features#game` verlinken (oder eigene Seite `/rules`)
- [ ] Screenshots/Bilder professionell aufbereiten (Mockups, Annotationen)
- [ ] Strukturierte Daten (Schema.org `WebApplication`)
- [ ] Dynamische Stats auf Startseite ("X.XXX km aufgezeichnet" live aus DB)
- [ ] Locale-Switcher im Header (Cookie-basiert, persistiert)

### P3 (Langfristig)
- [ ] Blog/News-Sektion (`/news`)
- [ ] FAQ (`/faq`)
- [ ] Presse/Media-Kit (`/press`)
- [ ] Testimonials/Social-Proof (Nutzer-Feedback, Community-Highlights)
- [ ] Video-Trailer (Hero-Hintergrund oder eingebettet)
- [ ] A/B-Testing (Headlines, CTAs)

---

## 10. Ressourcen & Abhängigkeiten

### 10.1 Benötigte Assets (vor Umsetzung)
- [ ] **Logo-Lockup** (G-Tile + GRAVA-Wortmarke) als SVG → aus iOS-App exportieren oder neu erstellen
- [ ] **OG-Image** (1200×630px) — Hero-Karte mit Marken-Overlay
- [ ] **App-Icon** als Favicon (bereits vorhanden, ggf. aktualisieren)
- [ ] **Screenshots** (3–5 Stück):
  - Live-Aufzeichnung mit Score-Overlay
  - Karte mit eingefärbten Revieren
  - Crew-Rangliste oder Kanten-Detail
  - Heatmap oder Discovery-View
- [ ] **App-Store-Badge** (offizieller Download von Apple)

### 10.2 Abhängigkeiten
- **iOS-App muss live sein** (App Store Link funktioniert)
- **Backend produktiv** (grava.world erreichbar, API funktioniert)
- **SSL-Zertifikat** aktiv (HTTPS Pflicht)
- **Domain `grava.world`** zeigt auf Server
- **Google Analytics** (oder Alternative) eingerichtet

### 10.3 Technische Skills
- **PHP/HTML/CSS** — server-gerenderte Templates bearbeiten (kein React/Vue)
- **Designsystem-Kenntnis** — Tokens aus `style.css` nutzen, keine Custom-Farben
- **Bildbearbeitung** — Screenshots croppen, OG-Images erstellen (Figma/Photoshop/Sketch)
- **Copywriting** — Marketing-Texte formulieren (rau & einladend, siehe §4.3)

---

## 11. Zeitplan & Meilensteine (Schätzung)

### Phase 1: MVP (Launch-kritisch)
**Aufwand:** ca. 2–3 Tage (1 Person, Vollzeit)
- Tag 1: Startseite `/` (Hero + Features + CTA) + Header/Footer anpassen
- Tag 2: Meta-Tags, SEO, Cookie-Consent, Screenshots einbinden
- Tag 3: Responsive-Test, Lokalisierung EN, QA

### Phase 2: Vertiefung
**Aufwand:** ca. 1–2 Tage
- `/about`, `/how-it-works`, Spiel-Regeln-Detail
- Strukturierte Daten, dynamische Stats

### Phase 3: Erweiterung
**Aufwand:** fortlaufend
- Blog/News (bei Bedarf), FAQ, Presse-Kit

**Empfehlung:** MVP zuerst live bringen (Launch-kritisch), dann iterativ ausbauen.

---

## 12. Erfolgsmessung (KPIs)

Nach Launch tracken (via GA oder Plausible):
1. **Traffic:** Unique Visitors auf `/` (Baseline: 0, Ziel: nach 4 Wochen >1.000)
2. **Funnel:** Klicks auf "App laden"-Button (Conversion-Rate: Besucher → Klick >10%)
3. **Discovery:** Nutzung von `/discover` + `/heatmap` (zeigt Interesse an Inhalten)
4. **Bounce-Rate:** <60% auf `/` (zeigt, dass Inhalte relevant sind)
5. **App-Store-Conversions:** Klicks auf Badge → tatsächliche Downloads (App-Store-Analytics)
6. **SEO:** Ranking für "Radstrecken bewerten App", "Wegqualität App", "Fahrrad Territorialspiel" (langfristig)

---

## 13. Offene Entscheidungen

1. **Blog/News:** Jetzt schon einbauen oder erst bei Bedarf (z. B. Launch-Announcement)? → Empfehlung: **P2**
2. **Video-Trailer:** Hero-Hintergrund-Video oder statisches Bild? → Empfehlung: **statisch im MVP** (Video P3)
3. **Testimonials:** Früh einbinden (sofern vorhanden) oder erst nach Launch sammeln? → Empfehlung: **nach Launch**
4. **Analytics-Tool:** Google Analytics (einfach, aber DSGVO-Aufwand) oder **Plausible.io** (DSGVO-freundlich, self-hosted)? → Entscheidung nötig
5. **Locale-Strategie:** Cookie-basiert oder URL-Präfix (`/en/`, `/de/`)? → Empfehlung: **Cookie + Browser-Header** (URL-Präfix nur bei SEO-Bedarf)

---

## 14. Risiken & Mitigation

| Risiko | Wahrscheinlichkeit | Impact | Mitigation |
|--------|-------------------|--------|------------|
| **App noch nicht im App Store** | Mittel | Hoch | CTA auf "Coming soon" + E-Mail-Sammlung |
| **Screenshots veralten schnell** | Hoch | Mittel | Generisches Marketing-Visual statt exaktem UI |
| **Content-Pflege wird vernachlässigt** | Mittel | Mittel | Einfache Struktur (PHP-Templates), kein CMS-Overhead |
| **SEO braucht Monate für Ranking** | Hoch | Niedrig | Launch ist nicht SEO-abhängig (Strava/Word-of-Mouth wichtiger) |
| **DSGVO-Verstoß (GA ohne Consent)** | Hoch | Hoch | Cookie-Banner MVP-Pflicht, oder Plausible.io nutzen |

---

## 15. Zusammenfassung & nächste Schritte

### Was wir bauen
Eine **schlanke, marken-konforme Marketing-Webseite** auf Basis des bestehenden PHP-Backends:
- **Startseite** (Hero + Features + CTA)
- **Features-Übersicht** (anonym)
- **Angepasste Navigation** (anonym zugänglich)
- **SEO/Sharing-Basics** (Meta-Tags, Sitemap, OG-Images)
- **Cookie-Consent** (DSGVO-konform)

### Was wir NICHT bauen (vorerst)
- CMS oder Blog-System (manuell pflegen genügt)
- Dark Mode (Light reicht im MVP)
- Video-Trailer oder aufwendige Animationen
- Dynamische Content-Generierung (statische Templates genügen)

### Nächste Schritte
1. **Freigabe dieses Konzepts** → diskutieren, offene Punkte klären (§13)
2. **Assets sammeln** (Logo, Screenshots, OG-Image) → §10.1
3. **MVP umsetzen** → §9 Launch-Checkliste
4. **QA & Lokalisierung** → Responsive-Test, DE+EN prüfen
5. **Deployment** → `.env` anpassen, Live-Schalten
6. **Launch-Kommunikation** → Social Media, Strava-Gruppen, Product Hunt (optional)

---

**Ende des Konzepts.** Bereit zur Diskussion & Umsetzung.
