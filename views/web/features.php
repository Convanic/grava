<?php
/**
 * Nutzerseitige „Funktionen & Neuigkeiten"-Seite.
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
<section class="card">
    <h1>Funktionen &amp; Neuigkeiten</h1>
    <p class="muted">
        Was grava heute alles kann — und was als Nächstes kommt.
        Stand: <strong>Version 0.1.0</strong> · 2026-06-23.
    </p>
</section>

<section class="card">
    <h2>Aufzeichnung &amp; Wegqualität</h2>
    <ul>
        <li><strong>Fahrt aufzeichnen</strong> mit Live-Anzeigen für Tempo, Höhenmeter, Untergrund und Verkehr.</li>
        <li><strong>Wegqualität-Score (1–5)</strong> aus Vibration und Geschwindigkeit – die Strecke wird pro Abschnitt eingefärbt.</li>
        <li><strong>Halterungsprofile</strong> mit eigener Kalibrierung je Montageposition (per Referenzfahrt justierbar).</li>
        <li><strong>Höhenmeter</strong> über Barometer und GPS, inkl. <strong>Höhenprofil</strong> in Fahrt-, Routen- und Live-Ansicht.</li>
        <li><strong>Verkehrszählung per Radar</strong>: kompatibles Fahrrad-Radar (Garmin Varia, Bluetooth) zählt vorbeifahrende Fahrzeuge als zusätzliche Qualitätsdimension.</li>
        <li><strong>Hinweise unterwegs</strong> setzen (positive/negative Gründe, Schnell-Buttons) – als Punkte auf der Karte.</li>
        <li><strong>Live Activity</strong> auf Sperrbildschirm und Dynamic Island während der Aufzeichnung.</li>
    </ul>
</section>

<section class="card">
    <h2>Routen, Import &amp; Export</h2>
    <ul>
        <li><strong>GPX-Import</strong> (auch aus iCloud) und <strong>GPX-Export</strong> inklusive Wegqualität, Hinweisen, Höhe und Verkehr.</li>
        <li><strong>CSV-Rohdaten-Export</strong>.</li>
        <li><strong>Lokale Fahrten- und Routenliste</strong> mit Suche, Sortierung und Filter „nur mit Bewertung".</li>
        <li><strong>Fahrt-Detail</strong>: Karte mit Score-Strecke, Übersicht, Score-Verteilung, Neuberechnung, Höhenprofil und Hinweisen.</li>
    </ul>
</section>

<section class="card">
    <h2>Konto &amp; Cloud <span class="muted" style="font-weight:400">(optional)</span></h2>
    <ul>
        <li><strong>Konto optional</strong> – Aufzeichnen, Bewerten und GPX funktionieren auch ganz ohne Anmeldung.</li>
        <li>Registrierung mit <strong>E-Mail-Bestätigung</strong>, Login, Passwort ändern/zurücksetzen, Konto löschen.</li>
        <li><strong>Profil</strong>: Anzeigename, öffentlicher Handle, Profilbild.</li>
        <li><strong>Cloud-Routen</strong>: Upload mit Sichtbarkeit (privat / per Link / öffentlich), umbenennen, löschen.</li>
        <li><strong>Teilen-Links</strong> erstellen, teilen, widerrufen – mit Aufrufzähler.</li>
        <li><strong>Einstellungen</strong>: Einheiten (automatisch/km/mi), Mitteilungen, Rechtliches.</li>
    </ul>
</section>

<section class="card">
    <h2>Community</h2>
    <ul>
        <li><strong>Entdecken</strong> (öffentliche Routen mit Suche &amp; Sortierung), <strong>Feed</strong> der gefolgten Fahrer und <strong>Heatmap</strong>.</li>
        <li><strong>Folgen/Entfolgen</strong> und öffentliche Profile.</li>
        <li><strong>Kommentare</strong> und <strong>Likes</strong> auf Routen.</li>
        <li><strong>Mitteilungen</strong> (neue Follower, Likes, Kommentare) mit ungelesen-Zähler – mit <strong>Push</strong> und pro Typ einzeln schaltbar.</li>
        <li><strong>Freunde einladen</strong> über Einladungslinks/-codes.</li>
    </ul>
</section>

<section class="card">
    <h2>Strava</h2>
    <ul>
        <li><strong>Strava verbinden</strong> und <strong>Aktivitäten importieren</strong> als private Cloud-Routen.</li>
    </ul>
</section>

<section class="card">
    <h2>Territorialspiel „Reviere"</h2>
    <ul>
        <li><strong>Solo</strong>: Karte mit eingefärbten Kanten (Besitz = Farbe, Frische = Deckkraft, Wert = Linienstärke); Kanten-Detail mit Wert-Aufschlüsselung (Pionier, Beliebtheit, Kuratierung) und Pionier-Kohorte; eigene Statistik.</li>
        <li><strong>Route „ins Spiel aufnehmen"</strong> – Strecken werden automatisch aufs Wegenetz abgeglichen; auch sehr lange Touren funktionieren. Jede Route zeigt ihren „Im Spiel"-Status.</li>
        <li><strong>Crews</strong>: gründen, per Code beitreten oder verlassen, Mitgliederliste, Captain und <strong>Crew-Rangliste</strong>.</li>
        <li><strong>Fraktionen</strong>: Grün gegen Blau, Meta-Karte mit Zellen-Gewinnern und Fraktions-Gesamtstand.</li>
        <li><strong>Spieler-Rangliste</strong>: Bereich (Welt/Freunde) × Zeitfenster (Woche/Saison/Gesamt) × Kennzahl (Gebiet/Pionier/Wert/Distanz) – dein Rang hervorgehoben.</li>
        <li><strong>Spielregeln &amp; Hilfe</strong> direkt in der App.</li>
    </ul>
</section>

<section class="card">
    <h2>Privatsphäre</h2>
    <ul>
        <li><strong>Heimat-/Privatzone</strong>: rund um dein Zuhause werden Revier, Tracks und Heatmap verschleiert – Radius frei einstellbar, wirkt auch rückwirkend.</li>
    </ul>
</section>

<section class="card">
    <h2>Bedienung &amp; Qualität</h2>
    <ul>
        <li><strong>Sprachen</strong>: Deutsch und Englisch (vollständig, inkl. VoiceOver).</li>
        <li><strong>Barrierefreiheit</strong>: VoiceOver-Labels, Dynamic Type, keine Nur-Farbe-Information, Text-Alternativen für Karten und Charts.</li>
        <li><strong>Robuste Zustände</strong>: Leer-, Fehler- und Offline-Ansichten mit „Erneut versuchen".</li>
        <li><strong>Onboarding</strong> beim Erststart inkl. optionalem Handle-Schritt.</li>
        <li><strong>Deep Links</strong> für geteilte Routen, E-Mail-Bestätigung, Passwort-Reset und Einladungscodes.</li>
    </ul>
</section>

<section class="card">
    <h2>Neuigkeiten</h2>
    <p class="muted">Die jüngsten Änderungen – kurz und nutzersichtbar.</p>

    <h3 style="margin-bottom:.25rem">Aktuell in Arbeit</h3>
    <ul>
        <li>„Im Spiel"-Markierung direkt in der Cloud-Routen-Liste – aufgenommene Routen sind auf einen Blick erkennbar.</li>
        <li>Auch sehr lange Fahrten lassen sich zuverlässig „ins Spiel aufnehmen".</li>
    </ul>

    <h3 style="margin-bottom:.25rem">Version 0.1.0 – 2026-06-23 <span class="muted" style="font-weight:400">· erster Stand</span></h3>
    <ul>
        <li>Aufzeichnung &amp; Wegqualität, Höhenprofil, Radar-Verkehr, Hinweise und Live Activity.</li>
        <li>Routen: GPX-Import/-Export, CSV-Export, Fahrt-/Routen-Detail mit Suche, Sortierung und Filter.</li>
        <li>Konto &amp; Cloud: optionales Konto, Profil, Cloud-Routen mit Sichtbarkeit und Teilen-Links.</li>
        <li>Community: Entdecken/Feed/Heatmap, Folgen, Kommentare/Likes, Mitteilungen, Freunde einladen.</li>
        <li>Strava verbinden und importieren.</li>
        <li>Reviere-Spiel: Solo, Crews, Fraktionen, Ranglisten und Spielregeln.</li>
        <li>Privatsphäre: Heimat-/Privatzone. Bedienung: Deutsch &amp; Englisch, Barrierefreiheit, Onboarding.</li>
    </ul>
</section>

<section class="card">
    <h2>Was kommt als Nächstes</h2>
    <p class="muted">Roadmap-Ausblick – Reihenfolge und Umfang können sich ändern.</p>
    <ul style="list-style:none;padding-left:0;display:flex;flex-direction:column;gap:.5rem">
        <li><?= $badge('Verfügbar') ?> Onboarding, Heimat-/Privatzone, Spielregeln, Crew- &amp; Spieler-Rangliste, Einstellungen und Live Activity sind bereits an Bord.</li>
        <li><?= $badge('In Kürze', 'soon') ?> Push-Benachrichtigungen für neue Follower, Likes und Kommentare.</li>
        <li><?= $badge('Geplant', 'planned') ?> Personensuche – Fahrer per Name oder Handle finden.</li>
        <li><?= $badge('Geplant', 'planned') ?> Weitere Sprachen über Deutsch und Englisch hinaus.</li>
        <li><?= $badge('Idee', 'planned') ?> Apple-Watch-App zur Aufzeichnung am Handgelenk.</li>
    </ul>
</section>
