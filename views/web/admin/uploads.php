<?php
/** @var list<array<string,mixed>> $rows */
/** @var int $total */
/** @var int $limit */
/** @var int $offset */
/** @var int $page */
/** @var array{total:int,by_source:array<string,int>,deleted:int} $summary */
/** @var array{source:string,q:string,deleted:bool} $filters */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$km = static fn($m): string => $m === null ? '—' : number_format(((int)$m) / 1000, 1, ',', '.') . ' km';
$bytes = static function ($b): string {
    if ($b === null) { return '—'; }
    $b = (int)$b;
    if ($b < 1024) { return $b . ' B'; }
    if ($b < 1024 * 1024) { return number_format($b / 1024, 1, ',', '.') . ' KB'; }
    return number_format($b / (1024 * 1024), 2, ',', '.') . ' MB';
};
$qs = static function (array $over) use ($filters, $page): string {
    $p = ['source' => $filters['source'], 'q' => $filters['q'], 'deleted' => $filters['deleted'] ? '1' : '', 'page' => $page];
    $p = array_merge($p, $over);
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null);
    return $p === [] ? '' : '?' . http_build_query($p);
};
$pages = max(1, (int)ceil($total / max(1, $limit)));
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/game/config">Config</a>
    <a href="/admin/game/ingest">Ingest</a>
    <a href="/admin/uploads" style="font-weight:700">Uploads</a>
    <a href="/admin/game/moderation">Moderation</a>
    <a href="/admin/game/players">Spieler</a>
    <a href="/admin/game/player">Spieler-Detail</a>
    <a href="/admin/game/crews">Crews</a>
    <a href="/admin/game/map">Karte</a>
</nav>
<section class="card">
    <h1>Admin · Uploads</h1>
    <p class="muted">
        Gesamt (aktiv): <strong><?= (int)$summary['total'] ?></strong> ·
        app: <strong><?= (int)$summary['by_source']['app'] ?></strong> ·
        strava: <strong><?= (int)$summary['by_source']['strava'] ?></strong> ·
        import: <strong><?= (int)$summary['by_source']['import'] ?></strong> ·
        manual: <strong><?= (int)$summary['by_source']['manual'] ?></strong> ·
        gelöscht: <strong><?= (int)$summary['deleted'] ?></strong>
    </p>

    <form method="get" action="/admin/uploads" class="inline-form" style="margin:.5rem 0;display:flex;gap:.75rem;flex-wrap:wrap;align-items:end">
        <label>Suche (Titel/User)
            <input type="text" name="q" value="<?= $e($filters['q']) ?>" placeholder="Titel, Handle oder E-Mail" size="28">
        </label>
        <label>Quelle
            <select name="source">
                <option value="">alle</option>
                <?php foreach (['app','strava','import','manual'] as $s): ?>
                    <option value="<?= $s ?>"<?= $filters['source'] === $s ? ' selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="display:flex;gap:.35rem;align-items:center">
            <input type="checkbox" name="deleted" value="1"<?= $filters['deleted'] ? ' checked' : '' ?>>
            gelöschte einblenden
        </label>
        <button type="submit" class="btn-primary">Filtern</button>
        <a class="btn-secondary" href="/admin/uploads">Zurücksetzen</a>
    </form>

    <?php if ($rows === []): ?>
        <p class="muted">Keine Uploads gefunden.</p>
    <?php else: ?>
    <p class="muted">Treffer: <strong><?= (int)$total ?></strong> · Seite <?= (int)$page ?>/<?= (int)$pages ?></p>
    <table class="data-table">
        <thead>
            <tr>
                <th>Hochgeladen</th>
                <th>Route</th>
                <th>User</th>
                <th>Quelle</th>
                <th>Sichtbarkeit</th>
                <th>Distanz</th>
                <th>Datei</th>
                <th>Spiel</th>
                <th>Aktion</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr<?= $r['deleted_at'] !== null ? ' style="opacity:.55"' : '' ?>>
                <td><?= $e($r['created_at']) ?><?= $r['deleted_at'] !== null ? ' <span class="badge" style="background:var(--error-bg);color:var(--error-text)">gelöscht</span>' : '' ?></td>
                <td>
                    <strong><?= $e($r['title']) ?></strong><br>
                    <span class="muted" style="font-size:.8rem"><?= $e($r['public_id']) ?> · #<?= (int)$r['route_id'] ?></span>
                </td>
                <td>
                    <?= $e($r['handle'] ?? ('#' . $r['user_id'])) ?><br>
                    <a class="muted" style="font-size:.8rem" href="/admin/game/player?q=<?= urlencode($r['email']) ?>"><?= $e($r['email']) ?></a>
                    <?= $r['user_status'] !== 'active' ? ' <span class="badge" style="background:var(--warn-bg);color:var(--warn-text)">' . $e($r['user_status']) . '</span>' : '' ?>
                </td>
                <td><?= $e($r['source']) ?></td>
                <td><?= $e($r['visibility']) ?></td>
                <td><?= $e($km($r['distance_m'])) ?></td>
                <td>
                    <?php if ($r['payload_path'] === null): ?>
                        <span class="muted">—</span>
                    <?php else: ?>
                        v<?= (int)$r['version'] ?> · <?= $e(strtoupper((string)$r['format'])) ?> · <?= $e($bytes($r['payload_bytes'])) ?><br>
                        <span class="muted" style="font-size:.75rem" title="<?= $e($r['payload_sha256'] ?? '') ?>"><?= $e($r['payload_path']) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['game_ingested_at'] !== null): ?>
                        <span class="badge badge-ok">im Spiel</span><br>
                        <span class="muted" style="font-size:.75rem"><?= (int)$r['game_edges_count'] ?> Kanten · <?= $e($r['game_ingested_at']) ?></span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['payload_path'] !== null && $r['deleted_at'] === null): ?>
                        <a class="btn-secondary" href="/admin/uploads/<?= $e($r['public_id']) ?>/download">Download</a>
                        <form method="post" action="/admin/game/ingest/<?= (int)$r['route_id'] ?>" class="inline-form" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
                            <button type="submit" class="btn-accent">Re-Ingest</button>
                        </form>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="inline-form" style="margin-top:1rem;display:flex;gap:1rem">
        <?php if ($page > 1): ?><a class="btn-secondary" href="/admin/uploads<?= $qs(['page' => $page - 1]) ?>">← Zurück</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn-secondary" href="/admin/uploads<?= $qs(['page' => $page + 1]) ?>">Weiter →</a><?php endif; ?>
    </p>
    <?php endif; ?>
</section>
