<?php
/** @var array{nodes:int,edges:int,passes_total:int,passes_24h:int,active_riders_90d:int} $metrics */
/** @var array{ok:int,pending:int,failed:int,match_rate:float} $ingestHealth */
/** @var list<array<string,mixed>> $audits */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/game/config">Config</a>
    <a href="/admin/game/ingest">Ingest</a>
    <a href="/admin/game/moderation">Moderation</a>
    <a href="/admin/game/players">Spieler</a>
    <a href="/admin/game/crews">Crews</a>
    <a href="/admin/game/edge">Inspector</a>
    <a href="/admin/game/map">Karte</a>
</nav>
<section class="card">
    <h1>Game · Health</h1>
    <p class="muted">
        Knoten: <strong><?= (int)$metrics['nodes'] ?></strong> ·
        Kanten: <strong><?= (int)$metrics['edges'] ?></strong> ·
        Pässe gesamt: <strong><?= (int)$metrics['passes_total'] ?></strong> ·
        Pässe 24h: <strong><?= (int)$metrics['passes_24h'] ?></strong> ·
        Aktive Fahrer 90T: <strong><?= (int)$metrics['active_riders_90d'] ?></strong>
    </p>
    <p class="muted">
        Ingest — ok: <strong><?= (int)$ingestHealth['ok'] ?></strong> ·
        pending: <strong><?= (int)$ingestHealth['pending'] ?></strong> ·
        failed: <strong><?= (int)$ingestHealth['failed'] ?></strong> ·
        Match-Rate: <strong><?= number_format((float)$ingestHealth['match_rate'] * 100, 1) ?>&nbsp;%</strong>
    </p>
</section>
<section class="card">
    <h2>Letzte Admin-Aktionen</h2>
    <?php if ($audits === []): ?>
        <p class="muted">Keine Aktionen protokolliert.</p>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Zeit</th><th>Admin</th><th>Aktion</th><th>Ziel</th></tr></thead>
        <tbody>
        <?php foreach ($audits as $a): ?>
            <tr>
                <td><?= $e($a['created_at'] ?? '') ?></td>
                <td><?= (int)($a['admin_user_id'] ?? 0) ?></td>
                <td><?= $e($a['action'] ?? '') ?></td>
                <td><?= $e($a['target'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
