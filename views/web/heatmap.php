<?php
/** @var list<array{lat:float,lon:float,weight:int}> $cells */
/** @var array{grid:float,cell_count:int,max_weight:int} $meta */
/** @var array<string,mixed>|null $_authedUser */

$h = static fn(string|int|float|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$num = static fn(float $v): string => rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
$maxW = max(1, (int)($meta['max_weight'] ?? 1));
?>

<header class="page-header">
    <h1>Crowd-Heatmap</h1>
    <p class="muted">
        Aggregierte Dichte öffentlicher Routen in einem
        <?= $h($num((float)($meta['grid'] ?? 0))) ?>°-Raster
        (~<?= $h(round(((float)($meta['grid'] ?? 0)) * 111)) ?> km pro Zelle).
        Anonym &amp; vorberechnet.
    </p>
</header>

<?php if (empty($cells)): ?>
    <div class="empty-state">
        <p>Noch keine Daten. Sobald öffentliche Routen existieren und die
        Aggregation lief (<code>cron:heatmap</code>), erscheinen hier die heißesten Regionen.</p>
    </div>
<?php else: ?>
    <p class="muted"><?= $h((int)($meta['cell_count'] ?? 0)) ?> Zellen · stärkste Zelle: <?= $h($maxW) ?> Routen</p>
    <table class="data-table">
        <thead>
            <tr>
                <th>Intensität</th>
                <th class="num">Routen</th>
                <th class="num">Breite (lat)</th>
                <th class="num">Länge (lon)</th>
                <th>Karte</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($cells as $c):
            $pct = (int)round(($c['weight'] / $maxW) * 100); ?>
            <tr>
                <td>
                    <span class="heat-bar" style="width: <?= $h(max(4, $pct)) ?>%"></span>
                </td>
                <td class="num"><?= $h($c['weight']) ?></td>
                <td class="num"><?= $h($num($c['lat'])) ?></td>
                <td class="num"><?= $h($num($c['lon'])) ?></td>
                <td>
                    <a href="/discover?bbox=<?= $h($num($c['lat'] - 0.05)) ?>,<?= $h($num($c['lon'] - 0.05)) ?>,<?= $h($num($c['lat'] + 0.05)) ?>,<?= $h($num($c['lon'] + 0.05)) ?>"
                       class="btn-link">Routen hier →</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted">
        Maschinenlesbar als GeoJSON: <code>GET /api/v1/heatmap</code>
        (optional <code>?bbox=minLon,minLat,maxLon,maxLat</code>).
    </p>
<?php endif; ?>
