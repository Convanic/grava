# Landing-Page Setup — Quick Start

## ✅ Was bereits erstellt wurde:

```
views/web/landing/
  ├── home.php                 Landing-Page HTML (Hero, Stats, Features, CTA)
  ├── README.md                Entwicklungs-Doku
  └── SETUP.md                 Diese Anleitung

public/assets/landing/
  ├── landing.css              Styles (nutzt Designsystem "Trail")
  └── hero-placeholder.jpg     Platzhalter für Hero-Bild

src/Controllers/Web/
  └── LandingController.php    Temporärer Controller (DEV)

public/index.php               Route /landing hinzugefügt
```

## 🚀 Landing-Page im Browser öffnen:

1. **MAMP starten** (falls nicht aktiv)

2. **Im Browser öffnen:**
   ```
   http://gravelexplorer.test:8890/landing
   ```

3. **Layout anpassen:**
   - `views/web/landing/home.php` — HTML-Struktur
   - `public/assets/landing/landing.css` — Styles
   - Nach Änderungen: Browser-Refresh (⌘R)

## 📊 Was du siehst:

### Hero-Sektion
- Große Headline: "Entdecke, bewerte und erobere Radstrecken"
- Subheadline mit Produktbeschreibung
- 2 CTA-Buttons (App-Download, Routen entdecken)
- Hero-Bild (aktuell Platzhalter)

### Stats-Sektion (inspiriert von ex1.png)
- **Grüner Hintergrund** (var(--primary) = Forest-Green)
- **4 Zahlen im 2×2-Grid:**
  - 12.450 KM erobert
  - 847 Fahrer aktiv
  - 214 Crews aktiv
  - 1.840 Routen geteilt
- Aktuell Mock-Daten, später live aus der DB

### Features-Sektion
- 3-Spalten-Grid (Mobile: 1 Spalte)
- Wegqualität, Reviere, Community-Heatmap

### How it works
- 3-Schritte-Flow mit nummerierten Kreisen

### Final CTA
- Nochmal App-Download + "Erst stöbern"-Link

## 🎨 Design-Abstimmung:

### Farben (aus Designsystem "Trail"):
- **Primär:** Forest-Green `#2f5233`
- **Akzent:** Clay `#bf7a3a`
- **Hintergrund:** Paper `#f4f1ea`
- **Text:** `#2b2a26`

### Responsive:
- **Mobile:** 1-spaltig, kleinere Schriften
- **Tablet (≥768px):** Hero 2-spaltig, Stats 2×2
- **Desktop (≥768px):** Stats 4-spaltig, Features 3-spaltig

## 🔧 Nächste Schritte zum Anpassen:

### 1. Hero-Bild austauschen
Ersetze `public/assets/landing/hero-placeholder.jpg` durch:
- Screenshot aus der iOS-App (z.B. Karte mit eingefärbten Revieren)
- Empfohlene Größe: 1200×900px (4:3)
- Format: JPG oder WebP

### 2. Stats mit echten Daten
In `src/Controllers/Web/LandingController.php`:
```php
// Statt Mock-Daten:
$stats = [
    'total_km_conquered' => 12450,
    // ...
];

// Echte DB-Abfrage:
$db = Db::get();
$stmt = $db->query("SELECT SUM(length_m)/1000 FROM game_edges WHERE owner_id IS NOT NULL");
$stats['total_km_conquered'] = (int) $stmt->fetchColumn();
```

### 3. App-Store-Badge einbinden
Lade den offiziellen Apple-Badge herunter:
- https://developer.apple.com/app-store/marketing/guidelines/
- Ersetze im `home.php` die `<a href="#">` mit dem echten Badge-Bild

### 4. Feature-Icons
Aktuell: Emoji-Platzhalter (📊, 🗺️, 👥)
Ersetze durch:
- SVG-Icons (z.B. Heroicons, SF Symbols)
- Oder eigene Icon-Grafiken

## 🧪 Testen:

### Browser-Größen:
- **Mobile:** 375px (iPhone SE)
- **Tablet:** 768px (iPad)
- **Desktop:** 1440px

### Checkliste:
- [ ] Stats-Grid ist gut lesbar (Zahlen groß genug)
- [ ] CTAs sind deutlich sichtbar
- [ ] Responsive funktioniert (keine horizontale Scrollbar)
- [ ] Farben passen zum Designsystem
- [ ] Buttons haben Hover-Effekte

## 📝 Finale Integration (später):

Wenn Layout fertig ist:

1. **Route umstellen:**
   ```php
   // In public/index.php:
   $router->get('/', fn($r) => $landingController->home()); // statt /dashboard-Redirect
   ```

2. **Controller verschieben:**
   - `LandingController` → `MarketingController` umbenennen
   - Echte Stats-Queries implementieren
   - Caching hinzufügen (5 Min)

3. **Meta-Tags ergänzen:**
   - OpenGraph-Image (1200×630px)
   - Twitter-Card
   - SEO-Description

4. **Assets finalisieren:**
   - Hero-Bild optimieren (WebP, lazy loading)
   - App-Store-Badge
   - Icons

---

**Fragen?** Siehe `README.md` für Details oder frag einfach!
