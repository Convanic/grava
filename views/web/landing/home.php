<?php
/**
 * Landing Page — Home (MVP)
 *
 * Variables:
 * @var array $stats Live-Stats aus der DB
 */
?>
<!-- Hero Section -->
<section class="hero hero--single-column">
    <div class="hero-content">
        <div class="hero-badge">🚀 Launch-Phase: Sei einer der Ersten</div>

        <h1 class="hero-title">
            Ergänze die Karte und erobere Deine Region,<br>
            Solo oder in der Crew
        </h1>

        <p class="hero-subtitle">
            Wie rau ist die Strecke? Wie viel Verkehr? Durchfahrt blockiert?
            GRAVA misst und speichert alles — automatisch per Radarlicht, manuell per Tipp.
            Sammle dein Gebiet und hilf der Community, die Datenbank aufzubauen.
        </p>

        <div class="hero-cta">
            <a href="#" class="btn-primary">📱 Kostenlos beitreten</a>
            <a href="/discover" class="btn-secondary">Beispiele ansehen</a>
        </div>

        <p class="hero-trust">
            <span class="trust-item">✓ Kein Abo</span>
            <span class="trust-item">✓ DSGVO-konform</span>
            <span class="trust-item">✓ Offline-fähig</span>
        </p>
    </div>
</section>

<!-- Live Community Gallery Section -->
<section class="community-gallery-section">
    <div class="community-gallery-container">
        <h2 class="gallery-heading">Live aus der GRAVA-Community</h2>
        <p class="gallery-subheading">🚀 Launch-Phase: Gemeinsam bauen wir die Datenbank auf</p>

        <div class="gallery">
            <!-- Gallery Navigation -->
            <div class="gallery-nav">
                <button class="gallery-btn gallery-btn-prev" aria-label="Vorheriges Slide">‹</button>
                <div class="gallery-indicators">
                    <button class="gallery-indicator active" data-slide="0" aria-label="Slide 1">●</button>
                    <button class="gallery-indicator" data-slide="1" aria-label="Slide 2">●</button>
                    <button class="gallery-indicator" data-slide="2" aria-label="Slide 3">●</button>
                </div>
                <button class="gallery-btn gallery-btn-next" aria-label="Nächstes Slide">›</button>
            </div>

            <!-- Gallery Slides -->
            <div class="gallery-slides">
                <!-- Slide 1: Karte mit Game-Kanten -->
                <div class="gallery-slide active" data-slide="0">
                    <h3 class="slide-title">🗺️ Eroberte Gebiete</h3>
                    <div id="landing-map" class="landing-map"
                         data-center-lat="48.21"
                         data-center-lon="12.40"
                         data-radius-km="50"
                         data-zoom="11"></div>
                    <p class="slide-note">Waldkraiburg & Umgebung — Live-Daten der eroberten Strecken</p>
                </div>

                <!-- Slide 2: Aktuelle Fahrten -->
                <div class="gallery-slide" data-slide="1">
                    <h3 class="slide-title">📋 Aktuelle Fahrten</h3>
                    <div class="recent-routes-table">
                        <?php if (!empty($recentRoutes) && count($recentRoutes) > 0): ?>
                            <table class="routes-table">
                                <thead>
                                    <tr>
                                        <th>Fahrer</th>
                                        <th>Titel</th>
                                        <th>Distanz</th>
                                        <th>Datum</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentRoutes as $route): ?>
                                    <tr>
                                        <td><a href="/u/<?= htmlspecialchars($route['handle'] ?? 'anonym', ENT_QUOTES, 'UTF-8') ?>">@<?= htmlspecialchars($route['handle'] ?? 'anonym', ENT_QUOTES, 'UTF-8') ?></a></td>
                                        <td><?= htmlspecialchars($route['title'] ?? 'Unbenannte Fahrt', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= number_format(($route['distance_m'] ?? 0) / 1000, 1, ',', '.') ?> km</td>
                                        <td><?= date('d.m.Y', strtotime($route['created_at'] ?? 'now')) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="empty-state">Noch keine öffentlichen Fahrten vorhanden. Sei der Erste!</p>
                        <?php endif; ?>
                    </div>
                    <p class="slide-note">Die neuesten öffentlichen Fahrten der Community</p>
                </div>

                <!-- Slide 3: App Screenshots -->
                <div class="gallery-slide" data-slide="2">
                    <h3 class="slide-title">📱 Die GRAVA App</h3>
                    <div class="app-screenshots-placeholder">
                        <div class="screenshot-box">
                            <p class="screenshot-placeholder-text">Screenshot 1<br>Territorialspiel</p>
                        </div>
                        <div class="screenshot-box">
                            <p class="screenshot-placeholder-text">Screenshot 2<br>Wegqualität-Score</p>
                        </div>
                        <div class="screenshot-box">
                            <p class="screenshot-placeholder-text">Screenshot 3<br>Route-Details</p>
                        </div>
                    </div>
                    <p class="slide-note">Einblick in die GRAVA iOS-App</p>
                </div>
            </div>
        </div>

        <p class="gallery-footer">
            🌱 <strong>Early Access:</strong> Sei einer der Ersten, die die Map aufbauen —
            Deutschland, Österreich, Schweiz
        </p>
    </div>
</section>

<!-- Features Section -->
<section class="features-section">
    <div class="features-container">
        <div class="feature-card">
            <div class="feature-icon">🎮</div>
            <h3 class="feature-title">Erobere dein Gebiet</h3>
            <p class="feature-description">
                Fahre Strecken und markiere sie als "dein" Gebiet.
                Tritt Crews bei, messe dich mit anderen, sammle Punkte.
                Je mehr du fährst, desto mehr baust du die Map für alle auf.
            </p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">📡</div>
            <h3 class="feature-title">Automatisch: Oberfläche & Verkehr messen</h3>
            <p class="feature-description">
                Starte die Aufzeichnung und fahre. GRAVA misst per Radarlicht
                automatisch Oberflächenqualität und Verkehr. Keine Eingabe nötig —
                alles passiert im Hintergrund.
            </p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">💬</div>
            <h3 class="feature-title">Manuell: Hinweise für die Community</h3>
            <p class="feature-description">
                Durchfahrt blockiert? Gefahrenstelle? Baustelle? Speichere Hinweise
                während der Fahrt. Alle profitieren von deinen Beobachtungen —
                und du von denen der anderen.
            </p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">📊</div>
            <h3 class="feature-title">Routen checken (sobald Daten verfügbar)</h3>
            <p class="feature-description">
                Importiere GPX aus Komoot. Wenn die Community die Strecke bereits fuhr,
                siehst du: Oberflächenqualität, Verkehr, Hinweise.
                <span class="feature-note">💡 Je mehr fahren, desto mehr Routen sind checkbar.</span>
            </p>
        </div>
    </div>
</section>

<!-- How it works -->
<section class="how-section">
    <div class="how-container">
        <h2 class="section-heading">In 3 Schritten loslegen</h2>
        <div class="steps-grid">
            <div class="step">
                <div class="step-number">1</div>
                <h3 class="step-title">App laden & losfahren</h3>
                <p class="step-description">
                    Starte die Aufzeichnung und fahre deine Lieblingsstrecke.
                    GRAVA misst automatisch Oberfläche, Verkehr und Erschütterungen.
                </p>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <h3 class="step-title">Gebiet erobern & Punkte sammeln</h3>
                <p class="step-description">
                    Jeder Kilometer zählt. Erobere Gebiete, tritt Crews bei,
                    steige im Ranking. Gamification macht Datensammeln zum Spaß.
                </p>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <h3 class="step-title">Community wächst → Daten werden nutzbar</h3>
                <p class="step-description">
                    Je mehr fahren, desto mehr Strecken sind analysiert.
                    Bald kannst du JEDE Route vorher checken — dank der Community.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
<section class="faq-section">
    <div class="faq-container">
        <h2 class="section-heading">Gut zu wissen</h2>
        <div class="faq-grid">
            <div class="faq-item">
                <h3 class="faq-question">Lohnt sich GRAVA, wenn noch wenige Daten vorhanden sind?</h3>
                <p class="faq-answer">
                    Ja! Erstens macht das Territorialspiel jetzt schon Spaß.
                    Zweitens: Je früher du dabei bist, desto mehr Gebiet kannst du erobern.
                    Drittens: Du hilfst, die beste Rad-Datenbank aufzubauen — für alle.
                </p>
            </div>
            <div class="faq-item">
                <h3 class="faq-question">Kann ich schon Routen checken?</h3>
                <p class="faq-answer">
                    Teilweise. Wenn die Community eine Strecke bereits gefahren ist,
                    siehst du Oberflächendaten und Hinweise. Noch nicht überall —
                    aber je mehr fahren, desto schneller wächst die Abdeckung.
                </p>
            </div>
            <div class="faq-item">
                <h3 class="faq-question">Kostet GRAVA etwas?</h3>
                <p class="faq-answer">
                    Nein, GRAVA ist kostenlos. Alle Features — Aufzeichnung, Scores, Territorialspiel —
                    sind ohne Abo nutzbar.
                </p>
            </div>
            <div class="faq-item">
                <h3 class="faq-question">Warum sollte ich Daten sammeln?</h3>
                <p class="faq-answer">
                    Weil's Spaß macht (Territorialspiel, Crews, Wettbewerb) UND
                    weil du damit allen Radfahrern hilfst. Wie Wikipedia oder OpenStreetMap —
                    nur für Oberflächenqualität.
                </p>
            </div>
            <div class="faq-item">
                <h3 class="faq-question">Ist meine Heimatadresse sicher?</h3>
                <p class="faq-answer">
                    Absolut. GRAVA hat eine Privacy-Zone: Deine Start- und Endpunkte bleiben privat
                    und werden nicht veröffentlicht.
                </p>
            </div>
            <div class="faq-item">
                <h3 class="faq-question">Brauche ich Internet während der Fahrt?</h3>
                <p class="faq-answer">
                    Nein. Aufzeichnung funktioniert komplett offline. Nur für Upload und
                    Community-Features brauchst du Netz.
                </p>
            </div>
        </div>
        <div class="faq-trust-badges">
            <span class="trust-badge">🔒 Deine Daten bleiben bei dir</span>
            <span class="trust-badge">⚡ Offline-fähig</span>
            <span class="trust-badge">🇪🇺 DSGVO-konform</span>
        </div>
    </div>
</section>

<!-- Final CTA -->
<section class="cta-section">
    <div class="cta-container">
        <h2 class="cta-heading">Bereit, dein Gebiet zu erobern?</h2>
        <p class="cta-description">
            Objektive Wegqualität, Community-Power und Territorialspiel —
            alles in einer App, komplett kostenlos
        </p>
        <div class="cta-buttons">
            <a href="#" class="btn-primary btn-large">Jetzt für iOS laden</a>
            <a href="/discover" class="btn-link">Erst stöbern</a>
        </div>
    </div>
</section>
