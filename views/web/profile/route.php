<?php
/** @var array<string,mixed> $route */
/** @var array<string,mixed> $profile */
/** @var array<string,mixed>|null $_authedUser */

$h = static fn(string|int|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$kmFromMeters = static fn(?int $m): string => $m === null ? '—' : number_format($m / 1000, 1, ',', '.') . ' km';
$stats = $route['stats'] ?? [];
$bbox  = $stats['bbox'] ?? null;
$cent  = $stats['centroid'] ?? null;
$handle = (string)$profile['handle'];
?>

<header class="page-header">
    <h1><?= $h($route['title']) ?></h1>
    <p class="muted">
        Von <a href="/u/<?= $h($handle) ?>">@<?= $h($handle) ?></a>
        · erstellt am <?= $h(substr($route['created_at'] ?? '', 0, 10)) ?>
        <span class="badge badge-public">public</span>
    </p>
</header>

<?php if (!empty($route['description'])): ?>
    <section class="route-description">
        <h2>Beschreibung</h2>
        <p><?= nl2br($h($route['description'])) ?></p>
    </section>
<?php endif; ?>

<section>
    <h2>Statistik</h2>
    <dl class="profile">
        <dt>Distanz</dt>      <dd><?= $h($kmFromMeters($stats['distance_m'] ?? null)) ?></dd>
        <dt>Höhenmeter</dt>   <dd><?= $h($stats['elevation_gain_m'] ?? '—') ?> m</dd>
        <dt>Punkte</dt>       <dd><?= $h($stats['point_count'] ?? '—') ?></dd>
        <dt>Format</dt>       <dd><?= $h($route['format'] ?? '—') ?></dd>
        <?php if ($bbox): ?>
            <dt>Bounding Box</dt>
            <dd><code><?= $h(sprintf('%.4f, %.4f → %.4f, %.4f', $bbox['min_lat'], $bbox['min_lon'], $bbox['max_lat'], $bbox['max_lon'])) ?></code></dd>
        <?php endif; ?>
        <?php if ($cent): ?>
            <dt>Centroid</dt>
            <dd><code><?= $h(sprintf('%.4f, %.4f', $cent['lat'], $cent['lon'])) ?></code></dd>
        <?php endif; ?>
    </dl>
</section>

<?php if (!empty($route['tags'])): ?>
    <section>
        <h2>Tags</h2>
        <p>
            <?php foreach ($route['tags'] as $t): ?>
                <a href="/discover?tag=<?= $h($t) ?>" class="tag">#<?= $h($t) ?></a>
            <?php endforeach; ?>
        </p>
    </section>
<?php endif; ?>

<p>
    <a href="/u/<?= $h($handle) ?>" class="btn-link">← Zurück zu @<?= $h($handle) ?>'s Profil</a>
</p>
