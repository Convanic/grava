<?php
/**
 * Öffentliche „Funktionen & Neuigkeiten"-Seite.
 *
 * WICHTIG: Diese Seite ist öffentlich-nutzerseitig. Hier stehen NUR Features
 * und Neuigkeiten in Nutzersprache — KEINE sicherheits-/infrastrukturrelevanten
 * Details (keine Endpunkte, Tokens, Server-/Build-/Signing-Infos). Bei
 * Erweiterungen bitte beibehalten.
 */
$badge = static function (string $label, string $kind = 'ok'): string {
    $styles = [
        'ok'      => 'background:var(--success-bg);color:var(--success-text)',
        'soon'    => 'background:var(--accent-weak);color:var(--accent-hover)',
        'planned' => 'background:var(--primary-weak);color:var(--primary)',
    ];
    $s = $styles[$kind] ?? $styles['ok'];
    return '<span class="badge" style="' . $s . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
};
?>

<!-- Hero Section -->
<section class="hero hero--single-column" style="padding: 80px 24px;">
    <div class="hero-content">
        <h1 class="hero-title" style="font-size: 42px;">Funktionen &amp; Neuigkeiten</h1>
        <p class="hero-subtitle">
            Was GRAVA heute alles kann — und was als Nächstes kommt.
            <br><strong>Version 0.1.0</strong> · 2026-06-30
        </p>
    </div>
</section>

<!-- Features Grid -->
<section class="features-section">
    <div class="features-container">
        <div class="feature-card">
            <div class="feature-icon">📍</div>
            <h3 class="feature-title">Aufzeichnung &amp; Wegqualität</h3>
            <ul class="feature-list">
                <li>Fahrt aufzeichnen mit Live-Anzeigen für Tempo, Höhenmeter, Untergrund und Verkehr</li>
                <li>Wegqualität-Score (1–5) aus Vibration und Geschwindigkeit</li>
                <li>Halterungsprofile mit eigener Kalibrierung</li>
                <li>Höhenprofil über Barometer und GPS</li>
                <li>Verkehrszählung per Radar (Garmin Varia, Bluetooth)</li>
                <li>Herzfrequenz per Bluetooth-Sensor – live sowie Puls-Chart und Ø/Max im Fahrt-Detail</li>
                <li>Leistung, Trittfrequenz &amp; Pedal-Balance per Bluetooth-Powermeter (Shimano, SRAM, Quarq, Stages &hellip;)</li>
                <li>Sensor-Gerät auswählen &amp; merken – verbindet gezielt dein Gerät, auch mit mehreren Rädern oder in der Gruppenausfahrt</li>
                <li>Hinweise unterwegs setzen</li>
                <li>Live Activity auf Sperrbildschirm</li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🗺️</div>
            <h3 class="feature-title">Routen, Import &amp; Export</h3>
            <ul class="feature-list">
                <li>GPX-Import und GPX-Export inklusive Wegqualität</li>
                <li>CSV-Rohdaten-Export</li>
                <li>Lokale Fahrten- und Routenliste mit Suche</li>
                <li>Fahrt-Detail: Karte mit Score-Strecke, Höhenprofil</li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">☁️</div>
            <h3 class="feature-title">Konto &amp; Cloud <span class="muted">(optional)</span></h3>
            <ul class="feature-list">
                <li>Konto optional – Aufzeichnen funktioniert auch ohne Anmeldung</li>
                <li>Registrierung mit E-Mail-Bestätigung</li>
                <li>Profil: Anzeigename, Handle, Profilbild</li>
                <li>Cloud-Routen: Upload mit Sichtbarkeit (privat/Link/öffentlich)</li>
                <li>Teilen-Links erstellen und widerrufen</li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">👥</div>
            <h3 class="feature-title">Community</h3>
            <ul class="feature-list">
                <li>Entdecken, Feed und Heatmap</li>
                <li>Folgen/Entfolgen und öffentliche Profile</li>
                <li>Kommentare und Likes auf Routen</li>
                <li>Mitteilungen mit Push (pro Typ schaltbar)</li>
                <li>Freunde einladen über Einladungslinks</li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🎮</div>
            <h3 class="feature-title">Territorialspiel „Reviere"</h3>
            <ul class="feature-list">
                <li>Solo: Karte mit eingefärbten Kanten (Besitz, Frische, Wert)</li>
                <li>Route „ins Spiel aufnehmen" – automatisches Wegenetz-Matching</li>
                <li>Crews: gründen, beitreten, Mitgliederliste, Crew-Rangliste</li>
                <li>Fraktionen: Grün vs. Blau, Meta-Karte mit Zellen-Gewinnern</li>
                <li>Spieler-Rangliste: Welt/Freunde × Woche/Saison/Gesamt</li>
                <li>Segment-Bestzeiten: schnellste Zeiten je Abschnitt (nach Rad-Typ)</li>
                <li>Rush: Gruppenfahrten mit gemeinsamer Übernahme</li>
                <li>Spielregeln &amp; Hilfe direkt in der App</li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🏅</div>
            <h3 class="feature-title">Ränge, Level &amp; Abzeichen</h3>
            <ul class="feature-list">
                <li>Aufstieg über mehrere Ränge mit eigener In-App-Aufstiegs-Feier</li>
                <li>Rang-Leiter und Abzeichen-Galerie zum Durchblättern</li>
                <li>Abzeichen in mehreren Familien und Stufen zum Freischalten</li>
                <li>Wochen-Serie (Streak): Flammen-Chip für Wochen in Folge mit Fahrt</li>
                <li>Aufgaben: wechselnde Wochen- und Saison-Ziele mit Belohnung</li>
                <li>Pionier &amp; Erstbefahrer: Namensrecht für die erste Befahrung</li>
                <li>Revier-Recap nach der Fahrt mit Punkte-Übersicht</li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🏃</div>
            <h3 class="feature-title">Strava</h3>
            <ul class="feature-list">
                <li>Strava verbinden</li>
                <li>Aktivitäten importieren als private Cloud-Routen</li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">🔒</div>
            <h3 class="feature-title">Privatsphäre</h3>
            <ul class="feature-list">
                <li>Heimat-/Privatzone: Verschleierung rund um dein Zuhause</li>
                <li>Radius frei einstellbar</li>
                <li>Wirkt auch rückwirkend</li>
            </ul>
        </div>

        <div class="feature-card">
            <div class="feature-icon">⚙️</div>
            <h3 class="feature-title">Bedienung &amp; Qualität</h3>
            <ul class="feature-list">
                <li>Sprachen: Deutsch und Englisch (vollständig)</li>
                <li>Barrierefreiheit: VoiceOver, Dynamic Type</li>
                <li>Robuste Zustände: Leer-, Fehler- und Offline-Ansichten</li>
                <li>Onboarding beim Erststart</li>
                <li>Deep Links für Routen, E-Mail, Passwort-Reset</li>
            </ul>
        </div>
    </div>
</section>

<!-- Roadmap Section -->
<section class="how-section">
    <div class="how-container">
        <h2 class="section-heading">Was kommt als Nächstes</h2>
        <p style="text-align: center; color: var(--muted); margin-bottom: 48px;">
            Roadmap-Ausblick – Reihenfolge und Umfang können sich ändern
        </p>
        <div style="max-width: 900px; margin: 0 auto;">
            <ul style="list-style:none; padding:0; display:flex; flex-direction:column; gap:1rem;">
                <li style="padding: 20px; background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border);">
                    <?= $badge('Verfügbar') ?>
                    <strong>Ränge &amp; Abzeichen, Wochen-Serie, Aufgaben, Pionier-Namensrecht, Segment-Bestzeiten, Crew- &amp; Spieler-Rangliste, Onboarding, Heimat-/Privatzone, Spielregeln und Live Activity</strong> sind bereits an Bord.
                </li>
                <li style="padding: 20px; background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border);">
                    <?= $badge('In Kürze', 'soon') ?>
                    <strong>Push-Benachrichtigungen</strong> für neue Follower, Likes und Kommentare.
                </li>
                <li style="padding: 20px; background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border);">
                    <?= $badge('Geplant', 'planned') ?>
                    <strong>Personensuche</strong> – Fahrer per Name oder Handle finden.
                </li>
                <li style="padding: 20px; background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border);">
                    <?= $badge('Geplant', 'planned') ?>
                    <strong>Weitere Sprachen</strong> über Deutsch und Englisch hinaus.
                </li>
                <li style="padding: 20px; background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border);">
                    <?= $badge('Idee', 'planned') ?>
                    <strong>Apple-Watch-App</strong> zur Aufzeichnung am Handgelenk.
                </li>
            </ul>
        </div>
    </div>
</section>

<style>
.feature-list {
    margin: 0;
    padding-left: 1.5rem;
    list-style: disc;
    color: var(--muted);
    font-size: 15px;
    line-height: 1.6;
}

.feature-list li {
    margin-bottom: 8px;
}

.feature-card .muted {
    font-weight: 400;
    font-size: 0.9em;
    color: var(--muted);
}

.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 600;
    margin-right: 8px;
}
</style>
