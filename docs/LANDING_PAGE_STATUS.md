# GRAVA Landing Page — Aktueller Stand

**Datum:** 2026-06-25
**Status:** Phase 2 (Content) — KOMPLETT FERTIG ✅ (alle 6 Sektionen)

---

## ✅ Was fertig ist:

### **1.0 Hero-Sektion** ✅
- **Headline:** "Finde, fahre und erobere Deine Tour"
- **Subheadline:** "Wie rau darf die Tour sein? Straßenbelag, Verkehr und Community-Daten zeigen es Dir — dann erobere dein Gebiet."
- **CTAs:**
  - Primary: "Jetzt für iOS laden" (Platzhalter href="#")
  - Secondary: "Touren erkunden" (→ /discover)
- **Visual:** Split-Screen mit 2 Screenshots (pic1.webp als Platzhalter)
- **Trust-Badge:** Entfernt (war zu aufdringlich)

### **2.0 Stats-Sektion** ✅
- **Überschrift:** "Live aus der GRAVA-Community"
- **4 Stats:**
  1. **42%** — Schotter-Anteil
  2. **12** — Anmeldungen heute
  3. **3** — Länder
  4. **340** — KM heute
- **Kontext-Note:** "Deutschland, Österreich, Schweiz — von glattem Asphalt bis rauem Schotter"
- **Strategie:** Aktivität/Wachstum statt absolute User-Zahlen (kaschiert kleine Nutzerbasis)

### **3.0 Benefits-Sektion** ✅
- **3 Benefits (outcome-fokussiert):**
  1. **"Keine Überraschungen"** — "Prüfe vorher ob die Tour die passende für Dich ist"
  2. **"Teile und Herrsche"** — "Speed, Erschütterungen und Verkehr wird automatisch erfasst. Alle profitieren"
  3. **"Wettkampf"** — "Ob Solo, als Crew oder Fraktion — Baue Dein Gebiet auf"

### **4.0 How It Works** ✅
- **Überschrift:** "In 3 Schritten loslegen"
- **3 Schritte:**
  1. **"Einfach losfahren"** — "Starte die Aufzeichnung und fahre deine Strecke. GRAVA misst alles automatisch."
  2. **"Score generieren"** — "GRAVA analysiert Untergrund, Hinweise und Verkehr"
  3. **"Erobern"** — "Lass Deine Tour zählen und werde aktives Mitglied der Community"

### **5.0 FAQ/Objection Handling** ✅
- **Überschrift:** "Gut zu wissen"
- **4 FAQ-Fragen:**
  1. "Kostet GRAVA etwas?" → Nein, kostenlos
  2. "Ist meine Heimatadresse sicher?" → Privacy-Zone
  3. "Brauche ich Internet während der Fahrt?" → Nein, offline-fähig
  4. "Brauche ich ein Konto?" → Ja, 30 Sek. Anmeldung
- **Trust-Badges:** 🔒 Deine Daten bleiben bei dir | ⚡ Offline-fähig | 🇪🇺 DSGVO-konform

---

## ✅ Was komplett fertig ist:

### **6.0 Final CTA** ✅
- **Headline:** "Bereit, dein Gebiet zu erobern?"
- **Description:** "Objektive Wegqualität, Community-Power und Territorialspiel — alles in einer App, komplett kostenlos"
- **CTAs:**
  - Primary: "Jetzt für iOS laden" (Platzhalter href="#")
  - Secondary: "Erst stöbern" (→ /discover)

---

## 📋 Assets-Status:

### ✅ Vorhanden:
- `pic1.webp` — Territorial-Grafik (als beide Screenshots verwendet)
- `download.webp` — Apple Download Button (aktuell nicht eingebunden)

### 🚧 Noch fehlend:
- **Screenshot 2:** ScoredRouteMap (Route mit Wegqualität-Farbskala)
- **App-Store-URL:** Echte App-ID statt Platzhalter "idXXXXXXXXXX"

---

## 🎨 Design & Code:

### Dateien:
- **HTML:** `/Users/arminlorenz/Sites/gravelexplorer/views/web/landing/home.php`
- **CSS:** `/Users/arminlorenz/Sites/gravelexplorer/public/assets/landing/landing.css`
- **Controller:** `/Users/arminlorenz/Sites/gravelexplorer/src/Controllers/Web/LandingController.php`
- **Plan:** `/Users/arminlorenz/Sites/gravelexplorer/docs/LANDING_PAGE_PLAN.md`

### Design:
- Designsystem "Trail" (Forest-Green, Clay-Akzent, Paper-BG)
- Responsive (Mobile-First)
- 8px-Grid, CSS-Tokens aus `style.css`

---

## 🧪 Testen:

```bash
# URL:
http://gravelexplorer.test:8890/landing

# Oder mit PHP Dev Server:
php -S localhost:9000 -t /Users/arminlorenz/Sites/gravelexplorer/public
# → http://localhost:9000/landing
```

---

## 📝 Nächste Schritte (optional):

1. **6.0 Final CTA optimieren** (falls gewünscht)
2. **Screenshot 2 erstellen** (ScoredRouteMap)
3. **App-Store-URL eintragen** (echte App-ID)
4. **Im Browser testen** (Layout, Responsive, Content-Flow)
5. **Phase 3:** Design-Varianten (A/B/C) wenn gewünscht
6. **Phase 4:** A/B-Testing Setup

---

## ✨ Highlights dieser Session:

- ✅ Systematischer CRO-Ansatz mit nummerierten Komponenten (1.0-6.0)
- ✅ Outcome-fokussierte Texte statt Feature-Listen
- ✅ Stats zeigen Aktivität statt absolute Größe (kaschiert kleine Nutzerbasis)
- ✅ FAQ adressiert wichtigste Einwände (Kosten, Privacy, Offline)
- ✅ Komplette Landing Page in ~1 Session erstellt

**Alle Entscheidungen sind dokumentiert in:** `LANDING_PAGE_PLAN.md`

---

**Status:** Bereit für Browser-Test & weitere Optimierung! 🚀
