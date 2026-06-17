<?php
/** @var list<array<string,mixed>> $items */
/** @var array<string,mixed> $pagination */
/** @var array<string,mixed>|null $_authedUser */

$h = static fn(string|int|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$items = $items ?? [];
$pagination = $pagination ?? ['total' => 0, 'has_more' => false, 'limit' => 30, 'offset' => 0];

$label = static function (array $n) use ($h): string {
    $actor = $n['actor']['handle'] ?? $n['actor']['display_name'] ?? 'Jemand';
    $actorHtml = $n['actor']['handle']
        ? '<a href="/u/' . $h($n['actor']['handle']) . '">@' . $h($n['actor']['handle']) . '</a>'
        : '<strong>' . $h($actor) . '</strong>';
    return match ((string)$n['type']) {
        'follow'  => $actorHtml . ' folgt dir jetzt.',
        'like'    => $actorHtml . ' hat deine Route geliked.',
        'comment' => $actorHtml . ' hat deine Route kommentiert.',
        default   => $actorHtml . ' hat interagiert.',
    };
};
?>
<section class="notifications-page">
    <h1>Mitteilungen</h1>

    <?php if (empty($items)): ?>
        <div class="empty-state">
            <p>Noch keine Mitteilungen. Folge anderen oder teile Routen, dann tut sich hier was.</p>
        </div>
    <?php else: ?>
        <ul class="notif-list">
            <?php foreach ($items as $n): ?>
                <li class="notif-item<?= empty($n['read']) ? ' notif-unread' : '' ?>">
                    <div class="notif-text"><?= $label($n) ?></div>
                    <?php if (!empty($n['route'])): ?>
                        <div class="notif-sub">
                            <?php $rh = $_authedUser['public_handle'] ?? null; ?>
                            <?php if ($rh): ?>
                                <a href="/u/<?= $h($rh) ?>/r/<?= $h($n['route']['id']) ?>"><?= $h($n['route']['title']) ?></a>
                            <?php else: ?>
                                <?= $h($n['route']['title']) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="notif-date muted"><?= $h(substr((string)$n['created_at'], 0, 10)) ?></div>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if (!empty($pagination['has_more'])): ?>
            <div class="pagination">
                <a href="/notifications?offset=<?= (int)$pagination['offset'] + (int)$pagination['limit'] ?>">Ältere →</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
