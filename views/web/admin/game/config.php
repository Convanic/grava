<?php
/** @var array<string,string> $config */
/** @var array<string,string> $errors */
/** @var list<array{n:int,pioneer:float}> $pioneerPreview */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/game/config">Config</a>
    <a href="/admin/game/ingest">Ingest</a>
    <a href="/admin/game/moderation">Moderation</a>
    <a href="/admin/game/players">Spieler</a>
</nav>
<section class="card">
    <h1>Game · Config</h1>
    <form method="post" action="/admin/game/config">
        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
        <table class="data-table">
            <thead><tr><th>Parameter</th><th>Wert</th></tr></thead>
            <tbody>
            <?php foreach ($config as $key => $val): ?>
                <tr>
                    <td><label for="cfg_<?= $e($key) ?>"><?= $e($key) ?></label></td>
                    <td>
                        <input type="text" id="cfg_<?= $e($key) ?>" name="<?= $e($key) ?>" value="<?= $e($val) ?>">
                        <?php if (isset($errors[$key])): ?>
                            <span class="muted"><?= $e($errors[$key]) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" class="btn-primary">Speichern</button>
    </form>
</section>
<section class="card">
    <h2>Recompute</h2>
    <p class="muted">Leeres BBox-Feld → voller Recompute. Format: minLon,minLat,maxLon,maxLat.</p>
    <form method="post" action="/admin/game/recompute" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= $e($_csrf) ?>">
        <label>BBox <input type="text" name="bbox" placeholder="minLon,minLat,maxLon,maxLat"></label>
        <button type="submit" class="btn-accent">Recompute</button>
    </form>
</section>
<section class="card">
    <h2>Pionier-Vorschau</h2>
    <table class="data-table">
        <thead><tr><th>n (distinct riders)</th><th>Pionier-Wert</th></tr></thead>
        <tbody>
        <?php foreach ($pioneerPreview as $row): ?>
            <tr>
                <td><?= (int)$row['n'] ?></td>
                <td><?= number_format((float)$row['pioneer'], 1) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
