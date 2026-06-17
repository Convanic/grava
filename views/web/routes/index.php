<?php
/** @var list<array<string,mixed>> $routes */
/** @var bool $verified */
/** @var string $_csrf */

$fmtKm  = static fn(?float $m): string => $m === null ? '—' : number_format($m / 1000, 1, ',', '.') . ' km';
$fmtElev = static fn(?float $e): string => $e === null ? '—' : number_format($e, 0, ',', '.') . ' m';
$fmtDate = static function (?string $iso): string {
    if (!$iso) { return '—'; }
    try {
        $dt = new DateTimeImmutable($iso);
        return $dt->format('d.m.Y');
    } catch (Throwable) {
        return $iso;
    }
};
?>
<section class="card">
    <header class="page-header">
        <h1>Meine Routen</h1>
        <a href="/routes/new" class="btn-primary">+ Neue Route</a>
    </header>

    <?php if (!$verified): ?>
        <div class="alert alert-warn">
            Deine E-Mail-Adresse ist noch nicht bestätigt. Du kannst bestehende Routen
            einsehen und verwalten, aber erst nach der Bestätigung neue hochladen.
        </div>
    <?php endif; ?>

    <?php if (empty($routes)): ?>
        <p class="muted">Du hast noch keine Routen angelegt.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Titel</th>
                        <th>Distanz</th>
                        <th>Höhenmeter</th>
                        <th>Sichtbarkeit</th>
                        <th>Aktualisiert</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($routes as $r): ?>
                        <tr>
                            <td>
                                <a href="/routes/<?= htmlspecialchars((string)$r['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string)$r['title'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                                <?php if (!empty($r['tags'])): ?>
                                    <div class="tag-list">
                                        <?php foreach ($r['tags'] as $t): ?>
                                            <span class="tag"><?= htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?= $fmtKm($r['distance_meters'] ?? null) ?></td>
                            <td>
                                ↑ <?= $fmtElev($r['elevation_gain_meters'] ?? null) ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= htmlspecialchars((string)$r['visibility'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars((string)$r['visibility'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td class="muted"><?= $fmtDate($r['updated_at'] ?? $r['created_at'] ?? null) ?></td>
                            <td class="row-actions">
                                <a href="/routes/<?= htmlspecialchars((string)$r['id'], ENT_QUOTES, 'UTF-8') ?>/edit" class="btn-link">Bearbeiten</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
