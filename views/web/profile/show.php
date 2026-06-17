<?php
/** @var array<string,mixed> $profile */
/** @var list<array<string,mixed>> $routes */
/** @var array<string,mixed> $pagination */
/** @var bool $isSelf */
/** @var array<string,mixed>|null $_authedUser */
/** @var string $_csrf */

$h = static fn(string|int|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$kmFromMeters = static fn(?int $m): string => $m === null ? '—' : number_format($m / 1000, 1, ',', '.') . ' km';
$handle = (string)$profile['handle'];
?>

<header class="page-header profile-header">
    <img class="profile-avatar" src="/u/<?= $h($handle) ?>/avatar" alt="Profilbild von @<?= $h($handle) ?>" width="96" height="96">
    <h1>@<?= $h($handle) ?></h1>
    <?php if (!empty($profile['display_name'])): ?>
        <p class="profile-display-name"><?= $h($profile['display_name']) ?></p>
    <?php endif; ?>
    <p class="muted">
        Beigetreten am <?= $h(substr((string)$profile['joined_at'], 0, 10)) ?>
    </p>

    <ul class="profile-stats">
        <li><strong><?= $h($profile['route_count_public']) ?></strong> Routen</li>
        <li><strong><?= $h($profile['follower_count']) ?></strong> Follower</li>
        <li><strong><?= $h($profile['following_count']) ?></strong> Folgt</li>
    </ul>

    <?php if ($_authedUser !== null && !$isSelf): ?>
        <div class="profile-actions">
            <?php if (!empty($profile['is_followed_by_viewer'])): ?>
                <form method="post" action="/u/<?= $h($handle) ?>/unfollow" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
                    <button type="submit" class="btn-secondary">Nicht mehr folgen</button>
                </form>
            <?php else: ?>
                <form method="post" action="/u/<?= $h($handle) ?>/follow" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
                    <button type="submit" class="btn-primary">Folgen</button>
                </form>
            <?php endif; ?>
            <form method="post" action="/u/<?= $h($handle) ?>/block" class="inline-form" onsubmit="return confirm('@<?= $h($handle) ?> wirklich blockieren? Bestehende Follow-Beziehungen werden entfernt.');">
                <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
                <button type="submit" class="btn-danger">Blockieren</button>
            </form>
        </div>
    <?php elseif ($isSelf): ?>
        <p class="muted profile-self-hint">
            Das ist dein Profil. <a href="/routes">Routen verwalten</a> ·
            <a href="/dashboard">Dashboard</a>
        </p>
    <?php endif; ?>
</header>

<section>
    <h2>Public Routen</h2>
    <?php if (empty($routes)): ?>
        <div class="empty-state">
            <p>Noch keine öffentlichen Routen.</p>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Titel</th>
                    <th class="num">Distanz</th>
                    <th class="num">Höhenmeter</th>
                    <th>Tags</th>
                    <th>Erstellt</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($routes as $r): ?>
                <tr>
                    <td><a href="/u/<?= $h($handle) ?>/r/<?= $h($r['id']) ?>"><?= $h($r['title']) ?></a></td>
                    <td class="num"><?= $h($kmFromMeters($r['stats']['distance_m'] ?? null)) ?></td>
                    <td class="num"><?= $h($r['stats']['elevation_gain_m'] ?? '—') ?> m</td>
                    <td>
                        <?php foreach (($r['tags'] ?? []) as $t): ?>
                            <span class="tag">#<?= $h($t) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td><?= $h(substr($r['created_at'] ?? '', 0, 10)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!empty($pagination['total'])): ?>
            <p class="muted">Zeige <?= $h(count($routes)) ?> von <?= $h($pagination['total']) ?></p>
        <?php endif; ?>
    <?php endif; ?>
</section>
