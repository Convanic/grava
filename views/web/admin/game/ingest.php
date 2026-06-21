<?php
/** @var array{ok:int,pending:int,failed:int,match_rate:float} $ingestHealth */
/** @var list<array<string,mixed>> $rows */
/** @var ?string $status */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$active = static fn(?string $s, ?string $cur): string => $s === $cur ? ' style="font-weight:700"' : '';
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
    <h1>Game · Ingest</h1>
    <p class="muted">
        ok: <strong><?= (int)$ingestHealth['ok'] ?></strong> ·
        pending: <strong><?= (int)$ingestHealth['pending'] ?></strong> ·
        failed: <strong><?= (int)$ingestHealth['failed'] ?></strong> ·
        Match-Rate: <strong><?= number_format((float)$ingestHealth['match_rate'] * 100, 1) ?>&nbsp;%</strong>
    </p>
    <form method="post" action="/admin/game/ingest" class="inline-form" style="margin:.5rem 0">
        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
        <label>Route ingestieren
            <input type="text" name="route" placeholder="Route-ID oder Public-ID (UUID)" size="40">
        </label>
        <button type="submit" class="btn-primary">Ingestieren</button>
    </form>
    <p class="muted" style="margin-top:0">
        Holt eine beliebige Route nachträglich ins Spiel — auch ohne bestehenden
        Log-Eintrag. Akzeptiert die interne Route-ID (Zahl) oder die Public-ID.
    </p>
    <p class="inline-form">
        <a href="/admin/game/ingest"<?= $active(null, $status) ?>>Alle</a> ·
        <a href="/admin/game/ingest?status=ok"<?= $active('ok', $status) ?>>ok</a> ·
        <a href="/admin/game/ingest?status=pending"<?= $active('pending', $status) ?>>pending</a> ·
        <a href="/admin/game/ingest?status=failed"<?= $active('failed', $status) ?>>failed</a>
    </p>
    <?php if ($rows === []): ?>
        <p class="muted">Keine Ingest-Einträge.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Zeit</th><th>Route</th><th>User</th><th>Status</th>
                <th>Kanten</th><th>Neue Pässe</th><th>Fehler</th><th>Aktion</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= $e($r['created_at'] ?? '') ?></td>
                <td><?= (int)($r['route_id'] ?? 0) ?></td>
                <td><?= (int)($r['user_id'] ?? 0) ?></td>
                <td><?= $e($r['status'] ?? '') ?></td>
                <td><?= (int)($r['matched_edges'] ?? 0) ?></td>
                <td><?= (int)($r['new_passes'] ?? 0) ?></td>
                <td><?= $e($r['valhalla_error'] ?? '') ?></td>
                <td>
                    <form method="post" action="/admin/game/ingest/<?= (int)($r['route_id'] ?? 0) ?>" class="inline-form">
                        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                        <button type="submit" class="btn-accent">Erneut ingestieren</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
