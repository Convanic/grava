# Landing Page Assets — To-Do

## ✅ Was bereits fertig ist:
- Hero HTML mit finalen Texten
- Hero CSS mit Split-Screen Layout
- Trust-Badge eingebaut

## 🚧 Was noch fehlt:

### 1. Apple App Store Badge ✅ FERTIG

**Status:**
- ✅ Badge vorhanden als `download.webp`
- ✅ Eingebunden in Hero-Sektion (home.php:22)
- ✅ Eingebunden in Final CTA (home.php:132)
- ✅ CSS mit Hover-Effekt (landing.css)

**Noch zu tun:**
- 🚧 App-Store-URL mit echter App-ID ersetzen:
```php
<!-- Aktuell: -->
<a href="https://apps.apple.com/app/grava/idXXXXXXXXXX" ...>

<!-- Ersetzen mit: -->
<a href="https://apps.apple.com/de/app/grava/id[DEINE_APP_ID]" ...>
```
(In home.php Zeile 21 + 131)

---

### 2. iPhone Screenshots

**Screenshot 1: GameMapView** ✅ FERTIG
- Bereits vorhanden als `pic1.webp`
- Kopiert als `screenshot-game-map.webp`
- Zeigt Territorialspiel mit eingefärbten Kanten

**Screenshot 2: ScoredRouteMap** 🚧 FEHLT NOCH
- Aktuell: Platzhalter (pic1.webp dupliziert)
- Benötigt: Route mit Wegqualität-Farbskala (rot = rau, grün = glatt)
- Todo: Screenshot aus App erstellen mit ScoredRouteMap
- Speichern als: `screenshot-scored-route.webp`

**Verarbeitung:**
1. Screenshots sind im Format 1290×2796px (iPhone 15 Pro)
2. Zuschneiden auf Content-Bereich (ohne Statusbar wenn möglich)
3. Als JPG exportieren (Qualität 85%)
4. Speichern als:
   ```
   /Users/arminlorenz/Sites/gravelexplorer/public/assets/landing/screenshot-game-map.jpg
   /Users/arminlorenz/Sites/gravelexplorer/public/assets/landing/screenshot-scored-route.jpg
   ```

**Tools zum Zuschneiden:**
- Preview.app (Mac): Öffnen → Tools → Adjust Size
- Oder: ImageOptim für Kompression

---

### 3. Platzhalter-Bild löschen (SPÄTER)

Wenn echte Screenshots da sind:
```bash
rm /Users/arminlorenz/Sites/gravelexplorer/public/assets/landing/hero-placeholder.jpg
```

---

## Testen nach Asset-Upload:

1. Screenshots hochladen
2. App-Store-Badge hochladen
3. Browser öffnen: http://gravelexplorer.test:8890/landing
4. Refresh (⌘R)
5. Checken:
   - ✅ Beide Screenshots sichtbar nebeneinander
   - ✅ App-Store-Badge statt "Jetzt für iOS laden"
   - ✅ Trust-Badge "🔒 Deine Heimat bleibt privat" sichtbar
   - ✅ Responsive: Mobile zeigt Screenshots untereinander

---

## Quick-Test ohne echte Screenshots:

Falls du sofort testen willst, kannst du Platzhalter nutzen:

```bash
# Im public/assets/landing/ Ordner:
cp hero-placeholder.jpg screenshot-game-map.jpg
cp hero-placeholder.jpg screenshot-scored-route.jpg
```

Dann siehst du das Layout, auch wenn die Bilder noch nicht final sind.
