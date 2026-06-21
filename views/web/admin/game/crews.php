<?php
/** @var list<array{crew_id:int,name:string,slug:string,members:int,held_edges:int,held_length_m:float,pioneered:int,captain_handle:?string}> $rows */
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
    <h1>Game · Crews</h1>
    <?php if ($rows === []): ?>
        <p class="muted">Noch keine Crews angelegt.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr><th>#</th><th>Crew</th><th>Captain</th><th>Mitglieder</th><th>Kanten</th><th>Länge (km)</th><th>Pioniert</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td><?= $e($r['name']) ?> <span class="muted">/<?= $e($r['slug']) ?></span></td>
                <td><?= $r['captain_handle'] !== null ? '@' . $e($r['captain_handle']) : '—' ?></td>
                <td><?= (int)$r['members'] ?></td>
                <td><?= (int)$r['held_edges'] ?></td>
                <td><?= number_format((float)$r['held_length_m'] / 1000, 1) ?></td>
                <td><?= (int)$r['pioneered'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
