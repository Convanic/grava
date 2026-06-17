<?php
/** @var list<array<string,mixed>> $routes */
/** @var array<string,mixed> $pagination */
/** @var array{q:string, sort:string, tags:list<string>, bbox:string} $filters */
/** @var array<string,mixed>|null $_authedUser */

$h = static fn(string|int|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$kmFromMeters = static fn(?int $m): string => $m === null ? '—' : number_format($m / 1000, 1, ',', '.') . ' km';

// Marker-Daten für die Übersichtskarte: nur Treffer mit Centroid.
$mapRoutes = [];
foreach ($routes as $r) {
    $cent = $r['stats']['centroid'] ?? null;
    if ($cent === null || !isset($cent['lat'], $cent['lon'])) {
        continue;
    }
    $oh = $r['owner']['handle'] ?? null;
    $mapRoutes[] = [
        'lat'   => (float)$cent['lat'],
        'lon'   => (float)$cent['lon'],
        'title' => (string)($r['title'] ?? 'Route'),
        'url'   => $oh ? '/u/' . rawurlencode((string)$oh) . '/r/' . rawurlencode((string)$r['id']) : null,
    ];
}
$mapRoutesJson = json_encode($mapRoutes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($mapRoutes !== []) {
    $_pageStyles  = ['/assets/vendor/leaflet/leaflet.css'];
    $_pageScripts = [
        '/assets/vendor/leaflet/leaflet.js',
        '/assets/js/map-core.js',
        '/assets/js/map-discover.js',
    ];
}
?>

<header class="page-header">
    <h1>Routen entdecken</h1>
    <p class="muted">Öffentliche Routen aus der Community.
        <a href="/discover/users">User entdecken &rarr;</a>
    </p>
</header>

<form method="get" action="/discover" class="filter-form">
    <label>
        Suche
        <input type="text" name="q" value="<?= $h($filters['q'] ?? '') ?>" placeholder="Titel enthält…" maxlength="100">
    </label>
    <label>
        Sortierung
        <select name="sort">
            <?php foreach ([
                'newest' => 'Neueste zuerst',
                'oldest' => 'Älteste zuerst',
                'distance_asc'  => 'Distanz aufsteigend',
                'distance_desc' => 'Distanz absteigend',
            ] as $val => $label): ?>
                <option value="<?= $h($val) ?>" <?= ($filters['sort'] ?? '') === $val ? 'selected' : '' ?>><?= $h($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>
        BBox (minLat,minLon,maxLat,maxLon)
        <input type="text" name="bbox" value="<?= $h($filters['bbox'] ?? '') ?>" placeholder="48.0,8.0,50.0,10.0" maxlength="80">
    </label>
    <?php if (!empty($filters['tags'])): foreach ($filters['tags'] as $t): ?>
        <input type="hidden" name="tag[]" value="<?= $h($t) ?>">
    <?php endforeach; endif; ?>
    <div class="form-actions">
        <button type="submit">Filtern</button>
        <a href="/discover" class="btn-link">Zurücksetzen</a>
    </div>
</form>

<?php if (!empty($filters['tags'])): ?>
    <p class="tag-list">
        Tags:
        <?php foreach ($filters['tags'] as $t): ?>
            <span class="tag">#<?= $h($t) ?></span>
        <?php endforeach; ?>
        <a href="/discover" class="btn-link">× alle Tags entfernen</a>
    </p>
<?php endif; ?>

<?php if (empty($routes)): ?>
    <div class="empty-state">
        <p>Keine Routen passen zu deinen Filtern.</p>
    </div>
<?php else: ?>
    <?php if ($mapRoutes !== []): ?>
        <div id="map" class="map" data-routes="<?= $h($mapRoutesJson) ?>"></div>
    <?php endif; ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Titel</th>
                <th>Owner</th>
                <th class="num">Distanz</th>
                <th class="num">Höhenmeter</th>
                <th>Tags</th>
                <th>Erstellt</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($routes as $r):
            $owner = $r['owner'] ?? null;
            $oh    = $owner['handle'] ?? null;
            $tags  = $r['tags'] ?? [];
            ?>
            <tr>
                <td><a href="/u/<?= $h($oh) ?>/r/<?= $h($r['id']) ?>"><?= $h($r['title']) ?></a></td>
                <td>
                    <?php if ($oh): ?>
                        <a href="/u/<?= $h($oh) ?>">@<?= $h($oh) ?></a>
                    <?php endif; ?>
                </td>
                <td class="num"><?= $h($kmFromMeters($r['stats']['distance_m'] ?? null)) ?></td>
                <td class="num"><?= $h($r['stats']['elevation_gain_m'] ?? '—') ?> m</td>
                <td>
                    <?php foreach ($tags as $t): ?>
                        <span class="tag">#<?= $h($t) ?></span>
                    <?php endforeach; ?>
                </td>
                <td><?= $h(substr($r['created_at'] ?? '', 0, 10)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    $offset = (int)($pagination['offset'] ?? 0);
    $limit  = (int)($pagination['limit']  ?? 20);
    $total  = (int)($pagination['total']  ?? 0);
    $hasMore = !empty($pagination['has_more']);
    $qs = $_GET; unset($qs['offset']);
    ?>
    <p class="muted">
        Zeige <?= $h($offset + 1) ?>–<?= $h(min($offset + $limit, $total)) ?> von <?= $h($total) ?>
    </p>
    <p class="pagination">
        <?php if ($offset > 0):
            $qs['offset'] = max(0, $offset - $limit); ?>
            <a href="?<?= $h(http_build_query($qs)) ?>" class="btn-link">← Vorherige</a>
        <?php endif; ?>
        <?php if ($hasMore):
            $qs['offset'] = $offset + $limit; ?>
            <a href="?<?= $h(http_build_query($qs)) ?>" class="btn-link">Nächste →</a>
        <?php endif; ?>
    </p>
<?php endif; ?>
