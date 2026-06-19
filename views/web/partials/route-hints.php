<?php
/**
 * Wegpunkt-Hinweise als Tabelle (km-sortiert, vom Backend so geliefert).
 *
 * @var list<array<string,mixed>> $hints
 */
if (empty($hints)) {
    return;
}

$fmtHintKm = static function ($m): string {
    if ($m === null || !is_numeric($m)) {
        return '—';
    }
    return number_format((float)$m / 1000, 1, ',', '.') . ' km';
};
?>
<section class="route-hints">
    <h2>Hinweise zur Strecke</h2>
    <div class="table-wrap">
        <table class="data-table hint-table">
            <thead>
                <tr>
                    <th>km</th>
                    <th>Typ</th>
                    <th>Bezeichnung</th>
                    <th>Notiz</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hints as $h): ?>
                    <?php
                    $sentiment = (string)($h['sentiment'] ?? '');
                    $isNeg = $sentiment === 'negative';
                    $color = $isNeg ? '#e11d48' : '#15803d';
                    ?>
                    <tr>
                        <td class="hint-km-cell"><?= htmlspecialchars($fmtHintKm($h['distance_m'] ?? null), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span class="hint-dot" style="background: <?= $color ?>;"></span>
                            <?= $isNeg ? 'negativ' : 'positiv' ?>
                        </td>
                        <td><?= htmlspecialchars((string)($h['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="muted"><?= empty($h['note']) ? '—' : htmlspecialchars((string)$h['note'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
