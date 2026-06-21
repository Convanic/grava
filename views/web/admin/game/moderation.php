<?php
/** @var list<array{user_id:int,handle:?string,passes_that_day:int,ridden_on:string}> $highVolume */
/** @var list<array<string,mixed>> $suspiciousSpeed */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/game/config">Config</a>
    <a href="/admin/game/ingest">Ingest</a>
    <a href="/admin/game/moderation">Moderation</a>
    <a href="/admin/game/players">Spieler</a>
    <a href="/admin/game/player">Spieler-Detail</a>
    <a href="/admin/game/crews">Crews</a>
    <a href="/admin/game/edge">Inspector</a>
    <a href="/admin/game/map">Karte</a>
</nav>
<section class="card">
    <h1>Game · Moderation</h1>
    <h2>Hohe Pass-Frequenz</h2>
    <?php if ($highVolume === []): ?>
        <p class="muted">Keine auffälligen Fahrer.</p>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Fahrer</th><th>User-ID</th><th>Datum</th><th>Pässe/Tag</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($highVolume as $r): ?>
            <tr>
                <td><?= $r['handle'] !== null ? '@' . $e($r['handle']) : '—' ?></td>
                <td><?= (int)$r['user_id'] ?></td>
                <td><?= $e($r['ridden_on']) ?></td>
                <td><?= (int)$r['passes_that_day'] ?></td>
                <td>
                    <form method="post" action="/admin/game/user/<?= (int)$r['user_id'] ?>/ban" class="inline-form">
                        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
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
    <h2>Geschwindigkeits-Auffälligkeiten</h2>
    <?php if ($suspiciousSpeed === []): ?>
        <p class="muted">Keine Geschwindigkeits-Auffälligkeiten (Stufe 1).</p>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Fahrer</th><th>User-ID</th><th>Kante</th><th>Datum</th><th>Ø km/h</th></tr></thead>
        <tbody>
        <?php foreach ($suspiciousSpeed as $r): ?>
            <tr>
                <td><?= !empty($r['handle']) ? '@' . $e($r['handle']) : '—' ?></td>
                <td><?= (int)($r['user_id'] ?? 0) ?></td>
                <td><?= (int)($r['edge_id'] ?? 0) ?></td>
                <td><?= $e($r['ridden_on'] ?? '') ?></td>
                <td><?= number_format((float)($r['avg_speed_kmh'] ?? 0), 1) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
