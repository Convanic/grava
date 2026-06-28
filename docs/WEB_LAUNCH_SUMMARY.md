# GRAVA Web-Launch — Executive Summary

**Datum:** 2026-06-25
**Vollständiges Konzept:** `WEB_LAUNCH_CONCEPT.md`

---

## Problem

Die aktuelle Webseite (`grava.world`) ist **nicht publikumstauglich**:
- Keine Marketing-/Landingpage — `/` leitet direkt auf Login
- Anonyme Besucher sehen nur ein Login-Formular
- Kein Produkt-Pitch, keine App-Download-CTAs
- Nicht SEO-/Sharing-fähig (fehlende Meta-Tags, Sitemap)

→ **Für einen öffentlichen Launch ungeeignet.**

---

## Lösung (MVP)

**Schlanke Marketing-Webseite** mit:
1. **Startseite `/`** — Hero + Produkt-Pitch + App-Download-CTA + Feature-Überblick
2. **Features `/features`** — vollständige Funktionsübersicht (anonym zugänglich)
3. **Navigation** — angepasst für anonyme Besucher (Discover, Heatmap verlinkt)
4. **SEO/Sharing** — Meta-Tags, OpenGraph, Sitemap, OG-Image
5. **Cookie-Consent** — DSGVO-konformer Banner für Google Analytics

**Keine komplexe Lösung:** Server-gerenderte PHP-Templates (wie bisher), kein CMS/React, bestehende Designsystem-Tokens nutzen.

---

## Kernbotschaft

> **"Entdecke, bewerte und erobere Radstrecken — gemeinsam mit der Community"**

**3 Haupt-Features für Marketing:**
1. **Wegqualität objektiv messen** — Score 1–5 aus Sensorik, für jeden Belag (Asphalt, Schotter, Kopfsteinpflaster, Feldweg)
2. **Territoriales Spiel** — Ingress-artig, Kanten erobern, Crews bilden, Reviere halten (Stadt & Land)
3. **Community & Discovery** — Heatmap, Routen teilen, Strava-Integration

---

## Zielgruppen

1. **Radfahrer auf unbefestigten Wegen** — Gravel, Bikepacking, MTB-Touren (Primär)
2. **Alltagsradler & Pendler** — wollen ruhige, gut befahrbare Strecken finden
3. **Strava-Power-User** — KOM-Jagd-Mentalität auf Territorien übertragen
4. **Local Communities** — Crews, Regions-Eroberung (Stadt & Land)

---

## Seitenstruktur (MVP)

```
/ (NEU)                      Startseite: Hero + Features + CTA
├── /features (angepasst)    Funktionsübersicht (anonym)
├── /discover (existiert)    Community-Routen
├── /heatmap (existiert)     Heatmap
├── /privacy (existiert)     Datenschutz
└── /terms (existiert)       AGB
```

**Später (P2/P3):** `/about`, `/news`, `/faq`, `/press`

---

## Design-Prinzipien

- **Designsystem „Trail"** (bereits vorhanden): Forest-Green (`--primary`), Clay-Akzent, Sand-Hintergrund
- **Tonalität:** Rau & outdoor + community & einladend (kein Corporate-Speak)
- **Responsive:** Mobile-first, 8px-Raster
- **Bildsprache:** Screenshots der App (Karten, Reviere, Scoring) — keine Stock-Fotos

---

## Benötigte Assets (vor Umsetzung)

- [ ] Logo-Lockup (G-Tile + GRAVA-Wortmarke) als SVG
- [ ] OG-Image (1200×630px, Social-Sharing)
- [ ] 3–5 Screenshots (Aufzeichnung, Karte, Crew-Rangliste)
- [ ] App-Store-Badge (offizieller Apple-Download)

---

## Zeitplan

**MVP:** 2–3 Tage (1 Person, Vollzeit)
- Tag 1: Startseite + Navigation
- Tag 2: Meta-Tags, SEO, Cookie-Consent
- Tag 3: Responsive, Lokalisierung EN, QA

**P2 (optional):** 1–2 Tage (`/about`, `/how-it-works`)

**Launch-kritisch:** Nur MVP nötig.

---

## Launch-Checkliste (Must-haves)

- [ ] Startseite `/` mit Hero + Features + App-CTA
- [ ] Header/Footer für anonyme Besucher
- [ ] Meta-Tags/OpenGraph (SEO/Sharing)
- [ ] OG-Image erstellen
- [ ] Cookie-Consent-Banner (GA Opt-in/-out)
- [ ] Sitemap + Robots.txt
- [ ] Responsive-Test
- [ ] Lokalisierung DE+EN
- [ ] App-Store-Link funktioniert

---

## KPIs (nach Launch)

1. **Traffic:** >1.000 Unique Visitors/Monat (nach 4 Wochen)
2. **Funnel:** >10% Klicks auf "App laden"-Button
3. **Bounce-Rate:** <60% auf Startseite
4. **SEO:** Ranking für "Radstrecken bewerten App", "Wegqualität App", "Fahrrad Territorialspiel" (langfristig)

---

## Offene Entscheidungen

1. **Analytics:** Google Analytics (DSGVO-Aufwand) oder **Plausible.io** (DSGVO-freundlich)?
   → Empfehlung: **Plausible.io** (self-hosted, kein Consent-Banner nötig)
2. **Blog:** Jetzt oder später?
   → Empfehlung: **Phase 2** (erst Launch, dann Content)
3. **Video-Trailer:** Im Hero?
   → Empfehlung: **Phase 3** (statisches Bild MVP)

---

## Risiken

| Risiko | Mitigation |
|--------|------------|
| App noch nicht live | CTA auf "Coming soon" + E-Mail-Sammlung |
| Screenshots veralten | Generische Marketing-Visuals statt exaktem UI |
| DSGVO-Verstoß (GA ohne Consent) | **Cookie-Banner Pflicht** oder Plausible.io |

---

## Nächste Schritte

1. **Freigabe:** Konzept diskutieren, offene Entscheidungen treffen
2. **Assets sammeln:** Logo, Screenshots, OG-Image (§10.1 im Konzept)
3. **MVP umsetzen:** Startseite + SEO + Cookie-Consent
4. **QA:** Responsive, Lokalisierung, Links prüfen
5. **Deployment:** `.env` anpassen (`APP_URL=https://grava.world`), live schalten
6. **Launch-Kommunikation:** Social Media, Strava-Gruppen, Product Hunt (optional)

---

**Fragen?** Siehe vollständiges Konzept: `WEB_LAUNCH_CONCEPT.md`
