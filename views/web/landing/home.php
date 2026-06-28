<?php
/**
 * Landing Page — Home (MVP)
 *
 * Variables:
 * @var array $stats Live-Stats aus der DB
 */
?>
<!-- Hero Section -->
<section class="hero">
    <div class="hero-content">
        <h1 class="hero-title">Finde, fahre und erobere Deine Tour</h1>
        <p class="hero-subtitle">
            Wie rau darf die Tour sein? Straßenbelag, Verkehr und Community-Daten
            zeigen es Dir — dann erobere dein Gebiet.
        </p>
        <div class="hero-cta">
            <a href="#" class="btn-primary">Jetzt für iOS laden</a>
            <a href="/discover" class="btn-secondary">Touren erkunden</a>
        </div>
    </div>
    <div class="hero-visual">
        <div class="hero-screenshots">
            <img src="/assets/landing/screenshot-game-map.webp" alt="GRAVA Territorialspiel" class="hero-screenshot hero-screenshot-left">
            <img src="/assets/landing/screenshot-scored-route.webp" alt="GRAVA Wegqualität-Score" class="hero-screenshot hero-screenshot-right">
        </div>
    </div>
</section>

<!-- Stats Section -->
<section class="stats-section">
    <div class="stats-container">
        <h2 class="stats-heading">Live aus der GRAVA-Community</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['surface_percentage'] ?? '42%' ?></div>
                <div class="stat-label">Schotter-Anteil</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($stats['signups_today'] ?? 12, 0, ',', '.') ?></div>
                <div class="stat-label">Anmeldungen heute</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['regions_count'] ?? '3' ?></div>
                <div class="stat-label">Länder</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($stats['km_today'] ?? 340, 0, ',', '.') ?></div>
                <div class="stat-label">KM heute</div>
            </div>
        </div>
        <p class="stats-note">Deutschland, Österreich, Schweiz — von glattem Asphalt bis rauem Schotter</p>
    </div>
</section>

<!-- Features Section -->
<section class="features-section">
    <div class="features-container">
        <div class="feature-card">
            <div class="feature-icon">📊</div>
            <h3 class="feature-title">Keine Überraschungen</h3>
            <p class="feature-description">
                Prüfe vorher ob die Tour die passende für Dich ist
            </p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">🗺️</div>
            <h3 class="feature-title">Teile und Herrsche</h3>
            <p class="feature-description">
                Speed, Erschütterungen und Verkehr wird automatisch erfasst. Alle profitieren
            </p>
        </div>
        <div class="feature-card">
            <div class="feature-icon">👥</div>
            <h3 class="feature-title">Wettkampf</h3>
            <p class="feature-description">
                Ob Solo, als Crew oder Fraktion — Baue Dein Gebiet auf
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
                <h3 class="step-title">Einfach losfahren</h3>
                <p class="step-description">
                    Starte die Aufzeichnung und fahre deine Strecke.
                    GRAVA misst alles automatisch.
                </p>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <h3 class="step-title">Score generieren</h3>
                <p class="step-description">
                    GRAVA analysiert Untergrund, Hinweise und Verkehr
                </p>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <h3 class="step-title">Erobern</h3>
                <p class="step-description">
                    Lass Deine Tour zählen und werde aktives Mitglied der Community
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
                <h3 class="faq-question">Kostet GRAVA etwas?</h3>
                <p class="faq-answer">
                    Nein, GRAVA ist kostenlos. Alle Features — Aufzeichnung, Scores, Territorialspiel —
                    sind ohne Abo nutzbar.
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
            <div class="faq-item">
                <h3 class="faq-question">Brauche ich ein Konto?</h3>
                <p class="faq-answer">
                    Ja, für die Community-Features und das Territorialspiel. Die Anmeldung dauert
                    30 Sekunden — per E-Mail oder Strava-Login.
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
