<?php
/** @var array<string,mixed> $route */
/** @var list<array<string,mixed>> $shares */
/** @var ?string $newShareToken */
/** @var string $shareBaseUrl */
/** @var string $_csrf */

$id = (string)$route['id'];

$fmtKm  = static fn(?float $m): string => $m === null ? '—' : number_format($m / 1000, 1, ',', '.') . ' km';
$fmtElev = static fn(?float $e): string => $e === null ? '—' : number_format($e, 0, ',', '.') . ' m';
$fmtDate = static function (?string $iso): string {
    if (!$iso) { return '—'; }
    try {
        $dt = new DateTimeImmutable($iso);
        return $dt->format('d.m.Y H:i');
    } catch (Throwable) {
        return $iso;
    }
};
$bbox = $route['bbox'] ?? null;
$centroid = $route['centroid'] ?? null;
?>
<section class="card">
    <header class="page-header">
        <h1><?= htmlspecialchars((string)$route['title'], ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="header-actions">
            <a href="/routes/<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>/edit" class="btn-secondary">Bearbeiten</a>
            <a href="/routes/<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>/download" class="btn-secondary">Download</a>
        </div>
    </header>

    <?php if (!empty($route['description'])): ?>
        <p class="route-description"><?= nl2br(htmlspecialchars((string)$route['description'], ENT_QUOTES, 'UTF-8')) ?></p>
    <?php endif; ?>

    <dl class="profile profile--wide">
        <dt>Distanz</dt>           <dd><?= $fmtKm($route['distance_meters'] ?? null) ?></dd>
        <dt>Höhenmeter</dt>        <dd>↑ <?= $fmtElev($route['elevation_gain_meters'] ?? null) ?></dd>
        <dt>Punkte</dt>            <dd><?= (int)($route['point_count'] ?? 0) ?></dd>
        <dt>Versionen</dt>         <dd><?= (int)($route['version_count'] ?? 1) ?> (head v<?= (int)($route['head_version'] ?? 1) ?>)</dd>
        <dt>Sichtbarkeit</dt>      <dd>
            <span class="badge badge-<?= htmlspecialchars((string)$route['visibility'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars((string)$route['visibility'], ENT_QUOTES, 'UTF-8') ?>
            </span>
        </dd>
        <?php if (!empty($route['tags'])): ?>
            <dt>Tags</dt>
            <dd>
                <div class="tag-list">
                    <?php foreach ($route['tags'] as $t): ?>
                        <span class="tag"><?= htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endforeach; ?>
                </div>
            </dd>
        <?php endif; ?>
        <?php if ($bbox !== null): ?>
            <dt>Bounding-Box</dt>
            <dd class="muted">
                <?= number_format((float)$bbox['min_lat'], 4, ',', '.') ?>, <?= number_format((float)$bbox['min_lon'], 4, ',', '.') ?>
                  – <?= number_format((float)$bbox['max_lat'], 4, ',', '.') ?>, <?= number_format((float)$bbox['max_lon'], 4, ',', '.') ?>
            </dd>
        <?php endif; ?>
        <?php if ($centroid !== null): ?>
            <dt>Mittelpunkt</dt>
            <dd class="muted">
                <?= number_format((float)$centroid['lat'], 5, ',', '.') ?>, <?= number_format((float)$centroid['lon'], 5, ',', '.') ?>
            </dd>
        <?php endif; ?>
        <dt>Hochgeladen</dt>
        <dd class="muted"><?= $fmtDate($route['created_at'] ?? null) ?></dd>
        <?php if (!empty($route['updated_at']) && $route['updated_at'] !== $route['created_at']): ?>
            <dt>Aktualisiert</dt>
            <dd class="muted"><?= $fmtDate($route['updated_at'] ?? null) ?></dd>
        <?php endif; ?>
    </dl>

    <form method="post" action="/routes/<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>/delete"
          onsubmit="return confirm('Diese Route wirklich löschen? Sie kann durch den Cleanup nach 30 Tagen endgültig entfernt werden.');"
          class="inline-form">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn-danger">Route löschen</button>
    </form>
</section>

<section class="card">
    <h2>Teilen</h2>
    <p class="muted">
        Erzeuge einen Link, mit dem du diese Route an andere weitergeben kannst.
        Der Token wird nach der Erzeugung nur einmal angezeigt — kopiere ihn dir
        sofort weg, falls du ihn brauchst.
    </p>

    <?php if ($newShareToken !== null): ?>
        <div class="alert alert-success">
            <p><strong>Neuer Share-Link:</strong></p>
            <p><code class="share-url"><?= htmlspecialchars($shareBaseUrl . $newShareToken, ENT_QUOTES, 'UTF-8') ?></code></p>
            <p class="muted">Diese Anzeige verschwindet beim nächsten Reload.</p>
        </div>
    <?php endif; ?>

    <form method="post" action="/routes/<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>/shares" class="inline-form">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">+ Neuen Share-Link erzeugen</button>
    </form>

    <?php if (empty($shares)): ?>
        <p class="muted" style="margin-top:16px;">Noch keine Share-Links erzeugt.</p>
    <?php else: ?>
        <div class="table-wrap" style="margin-top:16px;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Erstellt</th>
                        <th>Aufrufe</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shares as $s): ?>
                        <?php
                        $isRevoked = !empty($s['revoked_at']);
                        $isExpired = !empty($s['expires_at']) && strtotime((string)$s['expires_at']) < time();
                        $status = $isRevoked ? 'revoked' : ($isExpired ? 'expired' : 'active');
                        ?>
                        <tr>
                            <td class="muted"><?= $fmtDate($s['created_at'] ?? null) ?></td>
                            <td><?= (int)($s['view_count'] ?? 0) ?></td>
                            <td>
                                <span class="badge badge-<?= $status ?>"><?= $status ?></span>
                                <?php if (!empty($s['expires_at'])): ?>
                                    <span class="muted"> – läuft <?= $fmtDate($s['expires_at']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="row-actions">
                                <?php if ($status === 'active'): ?>
                                    <form method="post"
                                          action="/routes/<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>/shares/<?= (int)$s['id'] ?>/revoke"
                                          onsubmit="return confirm('Diesen Share-Link wirklich zurückziehen?');"
                                          class="inline-form">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="btn-link">Zurückziehen</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<p class="muted" style="text-align:center;">
    <a href="/routes">← Zurück zur Liste</a>
</p>
