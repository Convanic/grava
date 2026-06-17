<?php
/** @var list<array<string,mixed>> $routes */
/** @var array<string,mixed> $pagination */
/** @var array<string,mixed>|null $_authedUser */

$h = static fn(string|int|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$kmFromMeters = static fn(?int $m): string => $m === null ? '—' : number_format($m / 1000, 1, ',', '.') . ' km';
?>

<header class="page-header">
    <h1>Dein Feed</h1>
    <p class="muted">Neue öffentliche Routen von Usern, denen du folgst.</p>
</header>

<?php if (empty($routes)): ?>
    <div class="empty-state">
        <p>Noch keine Aktivität. <a href="/discover/users">User entdecken</a> und folgen!</p>
    </div>
<?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Titel</th>
                <th>User</th>
                <th class="num">Distanz</th>
                <th class="num">Höhenmeter</th>
                <th>Tags</th>
                <th>Veröffentlicht</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($routes as $r):
            $owner = $r['owner'] ?? null;
            $oh    = $owner['handle'] ?? null; ?>
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
                    <?php foreach (($r['tags'] ?? []) as $t): ?>
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
    <p class="muted">Zeige <?= $h($offset + 1) ?>–<?= $h(min($offset + $limit, $total)) ?> von <?= $h($total) ?></p>
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
