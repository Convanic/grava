<?php
/** @var array<string,mixed>|null $_authedUser */
/** @var string|null $flash */

$_pageStyles  = ['/assets/vendor/leaflet/leaflet.css'];
$_pageScripts = [
    '/assets/vendor/leaflet/leaflet.js',
    '/assets/js/map-core.js',
    '/assets/js/map-game-admin.js',
];
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/game/config">Config</a>
    <a href="/admin/game/ingest">Ingest</a>
    <a href="/admin/game/moderation">Moderation</a>
    <a href="/admin/game/players">Spieler</a>
    <a href="/admin/game/player">Spieler-Detail</a>
    <a href="/admin/game/crews">Crews</a>
    <a href="/admin/game/edge">Inspector</a>
    <a href="/admin/game/map"><strong>Karte</strong></a>
</nav>
<section class="card">
    <h1>Game · Übersichtskarte</h1>
    <p class="muted">
        Kanten des sichtbaren Ausschnitts, eingefärbt nach gewähltem Kriterium.
        Klick auf eine Kante öffnet den Inspector. Beim Verschieben/Zoomen
        werden die Daten des Viewports nachgeladen.
    </p>
    <label class="inline-form">
        Einfärben nach
        <select id="game-map-color">
            <option value="value">Wert</option>
            <option value="freshness">Frische</option>
            <option value="owner">Owner</option>
        </select>
    </label>
</section>

<div id="map" class="map map--full"
     data-edges-url="/admin/game/edges.geojson"
     data-edge-base="/admin/game/edge/"></div>
<div id="map-legend" class="map-legend" hidden></div>

<p class="muted map-hint">
    <strong>Wert</strong>: hell → niedrig, kräftig → hoch (relativ zum Ausschnitt).
    <strong>Frische</strong>: rot = alt, grün = frisch (0–1).
    <strong>Owner</strong>: feste Farbe pro Besitzer, grau = herrenlos.
</p>
