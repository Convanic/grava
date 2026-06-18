<?php
/** @var array<string,mixed> $route */
/** @var string $shareToken */

$fmtKm   = static fn(?float $m): string => $m === null ? '—' : number_format($m / 1000, 1, ',', '.') . ' km';
$fmtElev = static fn(?float $e): string => $e === null ? '—' : number_format($e, 0, ',', '.') . ' m';
$bbox    = $route['bbox']     ?? null;
$centroid = $route['centroid'] ?? null;
$shareToken = $shareToken ?? '';

$_pageStyles  = ['/assets/vendor/leaflet/leaflet.css'];
$_pageScripts = [
    '/assets/vendor/leaflet/leaflet.js',
    '/assets/js/map-core.js',
    '/assets/js/map-route.js',
];
?>
<section class="card">
    <p class="muted shared-flag">Geteilte Route — schreibgeschützt</p>
    <h1><?= htmlspecialchars((string)$route['title'], ENT_QUOTES, 'UTF-8') ?></h1>

    <?php if (!empty($route['description'])): ?>
        <p class="route-description"><?= nl2br(htmlspecialchars((string)$route['description'], ENT_QUOTES, 'UTF-8')) ?></p>
    <?php endif; ?>

    <div id="map" class="map map--detail"
         data-geojson-url="/share/<?= htmlspecialchars(rawurlencode($shareToken), ENT_QUOTES, 'UTF-8') ?>/geojson"></div>
    <div id="map-legend" class="map-legend" hidden></div>

    <?php if (!empty($insights)) { include __DIR__ . '/partials/route-insights.php'; } ?>

    <dl class="profile profile--wide">
        <dt>Distanz</dt>      <dd><?= $fmtKm($route['distance_meters'] ?? null) ?></dd>
        <dt>Höhenmeter</dt>   <dd>↑ <?= $fmtElev($route['elevation_gain_meters'] ?? null) ?></dd>
        <dt>Punkte</dt>       <dd><?= (int)($route['point_count'] ?? 0) ?></dd>
        <?php if (!empty($route['tags'])): ?>
            <dt>Tags</dt>
            <dd>
                <div class="tag-list">
                    <?php foreach ($route['tags'] as $t): ?>
                        <span class="tag"><?= htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endforeach; ?>
                </div>
            </dd>
        <?php endif; ?>
        <?php if ($bbox !== null): ?>
            <dt>Bounding-Box</dt>
            <dd class="muted">
                <?= number_format((float)$bbox['min_lat'], 4, ',', '.') ?>, <?= number_format((float)$bbox['min_lon'], 4, ',', '.') ?>
                  – <?= number_format((float)$bbox['max_lat'], 4, ',', '.') ?>, <?= number_format((float)$bbox['max_lon'], 4, ',', '.') ?>
            </dd>
        <?php endif; ?>
        <?php if ($centroid !== null): ?>
            <dt>Mittelpunkt</dt>
            <dd class="muted">
                <?= number_format((float)$centroid['lat'], 5, ',', '.') ?>, <?= number_format((float)$centroid['lon'], 5, ',', '.') ?>
            </dd>
        <?php endif; ?>
    </dl>
</section>

<p class="muted" style="text-align:center;">
    Diese Route wurde mit dir geteilt. Du brauchst kein Konto, um sie anzusehen.
</p>
