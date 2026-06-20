<?php
/** @var array<string,mixed>|null $inspector */
/** @var mixed $edgeId */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$num = static fn($v): string => number_format((float)$v, 1);
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/game/config">Config</a>
    <a href="/admin/game/ingest">Ingest</a>
    <a href="/admin/game/moderation">Moderation</a>
    <a href="/admin/game/players">Spieler</a>
</nav>
<section class="card">
    <h1>Game · Kanten-Inspector</h1>
    <form method="get" action="/admin/game/edge" class="inline-form">
        <label>Kanten-ID <input type="number" name="id" value="<?= $e($edgeId) ?>"></label>
        <button type="submit" class="btn-primary">Suchen</button>
    </form>
</section>
<?php if ($inspector === null): ?>
<section class="card">
    <p class="muted">Kante nicht gefunden.</p>
</section>
<?php else:
    $edge = $inspector['edge'];
    $owner = $inspector['owner'];
    $value = $inspector['value'];
    $edgeIdInt = (int)($edge['id'] ?? 0);
?>
<section class="card">
    <h2>Kante #<?= (int)($edge['id'] ?? 0) ?></h2>
    <p class="muted">
        Way-ID: <strong><?= $e($edge['way_id'] ?? '') ?></strong> ·
        Länge: <strong><?= $num($edge['length_m'] ?? 0) ?>&nbsp;m</strong> ·
        Owner: <strong><?= $owner !== null ? '@' . $e($owner['handle'] ?? '') : '—' ?></strong> ·
        n: <strong><?= (int)$inspector['n'] ?></strong> ·
        n90: <strong><?= (int)$inspector['n90'] ?></strong>
    </p>
    <p class="muted">
        Pionier: <strong><?= $num($value['pioneer']) ?></strong> ·
        Popularität: <strong><?= $num($value['popularity']) ?></strong> ·
        Kuration: <strong><?= $num($value['curation']) ?></strong> ·
        Gesamt: <strong><?= $num($value['total']) ?></strong> ·
        Frische: <strong><?= $num($edge['freshness_cached'] ?? 0) ?></strong>
    </p>
    <form method="post" action="/admin/game/edge/<?= $edgeIdInt ?>/recalc" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
        <button type="submit" class="btn-accent">Kante neu rechnen</button>
    </form>
</section>
<section class="card">
    <h2>Kohorte (Erst-Pässe)</h2>
    <?php if ($inspector['cohort'] === []): ?>
        <p class="muted">Keine Pässe.</p>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Fahrer</th><th>User-ID</th><th>Erster Pass</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($inspector['cohort'] as $c): ?>
            <tr>
                <td><?= !empty($c['handle']) ? '@' . $e($c['handle']) : '—' ?></td>
                <td><?= (int)($c['user_id'] ?? 0) ?></td>
                <td><?= $e($c['first_ridden_at'] ?? '') ?></td>
                <td>
                    <form method="post" action="/admin/game/user/<?= (int)($c['user_id'] ?? 0) ?>/ban" class="inline-form">
                        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                        <input type="hidden" name="edge_id" value="<?= $edgeIdInt ?>">
                        <input type="text" name="reason" placeholder="Grund">
                        <button type="submit" class="btn-accent">User sperren</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
<section class="card">
    <h2>Pässe</h2>
    <?php if ($inspector['passes'] === []): ?>
        <p class="muted">Keine Pässe.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr><th>ID</th><th>Fahrer</th><th>Geritten am</th><th>Invalidiert</th><th>Aktion</th></tr>
        </thead>
        <tbody>
        <?php foreach ($inspector['passes'] as $p): ?>
            <tr>
                <td><?= (int)($p['id'] ?? 0) ?></td>
                <td><?= !empty($p['handle']) ? '@' . $e($p['handle']) : '—' ?></td>
                <td><?= $e($p['ridden_on'] ?? '') ?></td>
                <td><?= $e($p['invalidated_at'] ?? '') ?></td>
                <td>
                    <?php if (empty($p['invalidated_at'])): ?>
                    <form method="post" action="/admin/game/pass/<?= (int)($p['id'] ?? 0) ?>/invalidate" class="inline-form">
                        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                        <input type="hidden" name="edge_id" value="<?= $edgeIdInt ?>">
                        <input type="text" name="reason" placeholder="Grund">
                        <button type="submit" class="btn-accent">Invalidieren</button>
                    </form>
                    <?php else: ?>
                    <form method="post" action="/admin/game/pass/<?= (int)($p['id'] ?? 0) ?>/reactivate" class="inline-form">
                        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                        <input type="hidden" name="edge_id" value="<?= $edgeIdInt ?>">
                        <button type="submit" class="btn-primary">Reaktivieren</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
<?php endif; ?>
