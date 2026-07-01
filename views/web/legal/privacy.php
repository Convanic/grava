<!-- Hero Section -->
<section class="hero hero--single-column" style="padding: 80px 24px 48px;">
    <div class="hero-content">
        <h1 class="hero-title" style="font-size: 42px;">Datenschutzerklärung</h1>
        <p class="hero-subtitle" style="color: var(--muted);">
            Stand: <strong>[Platzhalter – Datum eintragen]</strong><br>
            Diese Seite ist ein struktureller Entwurf. Der verbindliche Rechtstext wird vom Betreiber (ggf. nach anwaltlicher Prüfung) ergänzt.
        </p>
    </div>
</section>

<!-- Content Section -->
<section class="legal-content">
    <div class="legal-container">
        <h2>1. Verantwortlicher</h2>
        <p>
            [Platzhalter – Name / Firma]<br>
            [Platzhalter – Anschrift]<br>
            E-Mail: [Platzhalter – Kontakt-E-Mail]
        </p>

        <h2>2. Welche Daten wir verarbeiten</h2>
        <ul>
            <li><strong>Kontodaten:</strong> E-Mail-Adresse, Name, Handle (Benutzername).</li>
            <li><strong>Standort- und Routendaten:</strong> hochgeladene bzw. aufgezeichnete Strecken und Wegpunkte.</li>
            <li><strong>Fahrt-Metriken:</strong> Distanz, Dauer, Belag- und Aktivitätskennzahlen.</li>
            <li><strong>Fitnessdaten (optional):</strong> Leistung, Trittfrequenz und Pedal-Balance aus einem von dir gekoppelten Bluetooth-Powermeter – nur, wenn ein Sensor verbunden ist und die Fahrt hochgeladen wird.</li>
            <li><strong>Gesundheitsdaten (optional):</strong> Herzfrequenz aus einem von dir gekoppelten Bluetooth-Sensor – nur, wenn ein Sensor verbunden ist und die Fahrt hochgeladen wird. Reine Aufzeichnung; beim Teilen auf Strava wird sie mit der Aktivität übertragen.</li>
            <li><strong>Profilfotos / Avatare:</strong> von dir hochgeladene Bilder.</li>
            <li><strong>Community-Inhalte:</strong> Kommentare, Hinweise, Likes, Follows.</li>
        </ul>

        <h2>3. Zwecke der Verarbeitung</h2>
        <p>
            Die Daten werden ausschließlich zur Bereitstellung der App-Funktionalität
            verarbeitet (Konto, Routenverwaltung, Community-Funktionen, Spielmechanik).
            Es findet <strong>kein app-übergreifendes Tracking</strong> und keine Weitergabe
            zu Werbezwecken statt.
        </p>

        <h2>4. Empfänger, Hosting & Drittdienste</h2>
        <ul>
            <li><strong>Hosting:</strong> [Platzhalter – Hosting-Anbieter].</li>
            <li><strong>Push-Benachrichtigungen:</strong> Apple Push Notification service (APNs), Apple Inc.</li>
            <li><strong>Strava-Import:</strong> nur sofern von dir aktiv verbunden [Platzhalter – Details].</li>
        </ul>

        <h2>5. Speicherdauer</h2>
        <p>[Platzhalter – Aufbewahrungsfristen je Datenkategorie].</p>

        <h2>6. Deine Rechte</h2>
        <p>
            Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit und
            Widerspruch. Dein Konto kannst du jederzeit in der App löschen
            („Account löschen") – dabei werden die zugehörigen personenbezogenen Daten
            entfernt. [Platzhalter – Aufsichtsbehörde / weitere Details].
        </p>

        <h2>7. Kontakt</h2>
        <p>[Platzhalter – Kontakt für Datenschutzanfragen].</p>

        <p style="margin-top: 40px; text-align: center;">
            <a href="/terms" style="color: var(--primary);">Nutzungsbedingungen</a> ·
            <a href="/imprint" style="color: var(--primary);">Impressum</a>
        </p>
    </div>
</section>

<style>
.legal-content {
    max-width: 800px;
    margin: 0 auto;
    padding: 0 24px 80px;
}

.legal-container {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 48px;
}

.legal-container h2 {
    margin-top: 32px;
    margin-bottom: 16px;
    font-size: 20px;
    color: var(--text);
    font-weight: 600;
}

.legal-container h2:first-child {
    margin-top: 0;
}

.legal-container p, .legal-container li {
    line-height: 1.7;
    color: var(--text);
    font-size: 15px;
}

.legal-container ul {
    padding-left: 24px;
    margin: 16px 0;
}

.legal-container li {
    margin-bottom: 12px;
}

@media (max-width: 768px) {
    .legal-container {
        padding: 32px 24px;
    }
}
</style>
