<?php
/** @var array<string,mixed> $route */
/** @var array<string,mixed> $profile */
/** @var array<string,mixed>|null $_authedUser */
/** @var array{count:int,liked_by_viewer:bool,recent:list<string>} $likes */
/** @var string $_csrf */

$h = static fn(string|int|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$kmFromMeters = static fn(?int $m): string => $m === null ? '—' : number_format($m / 1000, 1, ',', '.') . ' km';
$stats = $route['stats'] ?? [];
$bbox  = $stats['bbox'] ?? null;
$cent  = $stats['centroid'] ?? null;
$handle = (string)$profile['handle'];
$likes = $likes ?? ['count' => 0, 'liked_by_viewer' => false, 'recent' => []];
?>

<header class="page-header">
    <h1><?= $h($route['title']) ?></h1>
    <p class="muted">
        Von <a href="/u/<?= $h($handle) ?>">@<?= $h($handle) ?></a>
        · erstellt am <?= $h(substr($route['created_at'] ?? '', 0, 10)) ?>
        <span class="badge badge-public">public</span>
    </p>

    <div class="like-bar">
        <?php if ($_authedUser !== null): ?>
            <?php if (!empty($likes['liked_by_viewer'])): ?>
                <form method="post" action="/u/<?= $h($handle) ?>/r/<?= $h($route['id']) ?>/unlike" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
                    <button type="submit" class="btn-secondary">♥ Geliked</button>
                </form>
            <?php else: ?>
                <form method="post" action="/u/<?= $h($handle) ?>/r/<?= $h($route['id']) ?>/like" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
                    <button type="submit" class="btn-primary">♡ Liken</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <span class="like-count"><strong><?= $h((int)$likes['count']) ?></strong> Like<?= (int)$likes['count'] === 1 ? '' : 's' ?></span>
        <?php if (!empty($likes['recent'])): ?>
            <span class="muted">— zuletzt:
                <?php foreach ($likes['recent'] as $i => $rh): ?><?= $i > 0 ? ', ' : '' ?><a href="/u/<?= $h($rh) ?>">@<?= $h($rh) ?></a><?php endforeach; ?>
            </span>
        <?php endif; ?>
    </div>
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
