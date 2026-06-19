<?php
/**
 * M9: Surface-Check — Fremd-Route hochladen, Crowd-Belag projizieren.
 *
 * @var bool                    $verified
 * @var array<string,string[]>  $errors
 * @var string|null             $token
 * @var array<string,mixed>|null $result   {method, geojson, summary}
 * @var string|null             $filename
 * @var bool                    $valhallaEnabled
 * @var array<string,mixed>|null $_authedUser
 * @var string                  $_csrf
 */
$h = static fn(string|int|float|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

$scoreLabels = [
    0 => 'sehr glatt', 1 => 'glatt', 2 => 'überwiegend fest',
    3 => 'gemischt', 4 => 'ruppig', 5 => 'grob / Schotter',
];

$result   = $result ?? null;
$summary  = is_array($result) ? ($result['summary'] ?? null) : null;
$hasResult = is_array($result) && is_array($summary);

$km = static fn(int $m): string => number_format($m / 1000, 1, ',', '.');

$_pageStyles  = ['/assets/vendor/leaflet/leaflet.css'];
$_pageScripts = [
    '/assets/vendor/leaflet/leaflet.js',
    '/assets/js/map-core.js',
    '/assets/js/map-surface-check.js',
];
?>

<header class="page-header">
    <h1>Belag prüfen</h1>
    <p class="muted">
        Lade eine fremde Route (z. B. Strava-GPX) hoch — GRAVA gleicht sie mit den
        vorhandenen Crowd-Belagsdaten ab und zeigt dir, wie die Strecke wirklich ist.
        Die Datei wird <strong>nicht gespeichert</strong>.
    </p>
</header>

<?php if (!$verified): ?>
    <div class="flash">Bitte bestätige zuerst deine E-Mail-Adresse, um Routen zu analysieren.</div>
<?php endif; ?>

<form method="post" action="/surface-check" enctype="multipart/form-data" class="upload-form">
    <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
    <div class="field">
        <label for="payload">GPX- oder GeoJSON-Datei</label>
        <input type="file" id="payload" name="payload" accept=".gpx,.geojson,.json,application/gpx+xml,application/geo+json">
        <?php if (!empty($errors['payload'])): ?>
            <?php foreach ($errors['payload'] as $msg): ?>
                <p class="field-error"><?= $h($msg) ?></p>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <button type="submit" class="btn">Analysieren</button>
</form>

<?php if ($hasResult): ?>
    <?php
    $cov     = (float)($summary['coverage_pct'] ?? 0);
    $total   = (int)($summary['total_length_m'] ?? 0);
    $covered = (int)($summary['covered_length_m'] ?? 0);
    $avg     = $summary['avg_score'] ?? null;
    $buckets = $summary['by_bucket'] ?? ['paved' => 0, 'mixed' => 0, 'gravel' => 0];
    $avgLabel = ($avg !== null) ? ($scoreLabels[max(0, min(5, (int)round((float)$avg)))] ?? '') : null;
    $detailsUrl = $token !== null ? ('/surface-check/details?r=' . rawurlencode($token)) : '';
    ?>

    <section class="surface-result">
        <h2>Belags-Profil<?php if ($filename): ?> <span class="muted">· <?= $h($filename) ?></span><?php endif; ?></h2>

        <div class="surface-summary">
            <div class="surface-stat">
                <span class="surface-stat-value"><?= $h(number_format($cov, 0, ',', '.')) ?> %</span>
                <span class="surface-stat-label">mit Crowd-Daten abgedeckt</span>
                <div class="coverage-bar"><span style="width: <?= $h(max(0, min(100, $cov))) ?>%"></span></div>
                <span class="muted"><?= $h($km($covered)) ?> von <?= $h($km($total)) ?> km</span>
            </div>
            <div class="surface-stat">
                <span class="surface-stat-value">
                    <?= $avg !== null ? $h(number_format((float)$avg, 1, ',', '.')) : '–' ?>
                </span>
                <span class="surface-stat-label">Ø Untergrund<?php if ($avgLabel): ?> · <?= $h($avgLabel) ?><?php endif; ?></span>
            </div>
        </div>

        <ul class="bucket-list">
            <li><span class="swatch swatch-paved"></span> Befestigt/glatt: <strong><?= $h(number_format((float)($buckets['paved'] ?? 0), 0, ',', '.')) ?> %</strong></li>
            <li><span class="swatch swatch-mixed"></span> Gemischt: <strong><?= $h(number_format((float)($buckets['mixed'] ?? 0), 0, ',', '.')) ?> %</strong></li>
            <li><span class="swatch swatch-gravel"></span> Schotter/grob: <strong><?= $h(number_format((float)($buckets['gravel'] ?? 0), 0, ',', '.')) ?> %</strong></li>
        </ul>

        <?php if ($cov <= 0): ?>
            <p class="muted">Für diese Route liegen noch keine Crowd-Daten vor. Die Karte zeigt die Strecke neutral.</p>
        <?php endif; ?>

        <div id="map" class="map map--full"
             data-details-url="<?= $h($detailsUrl) ?>"
             data-valhalla="<?= $valhallaEnabled ? '1' : '0' ?>"></div>
        <div id="map-legend" class="map-legend" hidden></div>

        <?php if ($valhallaEnabled && $token !== null): ?>
            <p class="surface-actions">
                <button type="button" id="surface-details-btn" class="btn-link">Details zur Wegbeschaffenheit (präzise)</button>
                <span id="surface-details-status" class="muted"></span>
            </p>
        <?php endif; ?>

        <script type="application/json" id="surface-data">
            <?= json_encode([
                'method'  => $result['method'] ?? 'spatial',
                'geojson' => $result['geojson'] ?? ['type' => 'FeatureCollection', 'features' => []],
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
        </script>
    </section>
<?php endif; ?>
