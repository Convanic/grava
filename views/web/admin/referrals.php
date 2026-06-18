<?php
/** @var list<array<string,mixed>> $rows */
/** @var ?string $from */
/** @var ?string $to */
/** @var array{referrers:int,registered:int,verified:int,activated:int} $totals */
/** @var string $_csrf */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fromV = $from !== null ? $e($from) : '';
$toV   = $to !== null ? $e($to) : '';
$csvQuery = http_build_query(array_filter(['from' => $from, 'to' => $to], static fn($v) => $v !== null && $v !== ''));
?>
<section class="card">
    <h1>Empfehlungen – Auswertung</h1>

    <form method="get" action="/admin/referrals" class="inline-form">
        <label>Von <input type="date" name="from" value="<?= $fromV ?>"></label>
        <label>Bis <input type="date" name="to" value="<?= $toV ?>"></label>
        <button type="submit">Filtern</button>
        <a class="button" href="/admin/referrals.csv<?= $csvQuery !== '' ? '?' . $e($csvQuery) : '' ?>">CSV-Export</a>
    </form>

    <p class="muted">
        Werber: <strong><?= (int)$totals['referrers'] ?></strong> ·
        Registriert: <strong><?= (int)$totals['registered'] ?></strong> ·
        Verifiziert: <strong><?= (int)$totals['verified'] ?></strong> ·
        Aktiviert: <strong><?= (int)$totals['activated'] ?></strong>
    </p>

    <?php if ($rows === []): ?>
        <p>Keine Empfehlungen im gewählten Zeitraum.</p>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Werber</th>
                <th>E-Mail</th>
                <th>Registriert</th>
                <th>Verifiziert</th>
                <th>Aktiviert</th>
                <th>Conversion</th>
                <th>Letzte</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $r): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td>
                    <?php if (!empty($r['handle'])): ?>
                        <a href="/u/<?= $e($r['handle']) ?>">@<?= $e($r['handle']) ?></a>
                    <?php else: ?>
                        <?= $e($r['display_name'] ?? '—') ?>
                    <?php endif; ?>
                </td>
                <td><?= $e($r['email']) ?></td>
                <td><?= (int)$r['registered'] ?></td>
                <td><?= (int)$r['verified'] ?></td>
                <td><?= (int)$r['activated'] ?></td>
                <td><?= number_format((float)$r['conversion'] * 100, 1) ?>&nbsp;%</td>
                <td><?= $e($r['last_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>
