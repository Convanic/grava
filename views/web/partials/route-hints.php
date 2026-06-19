<?php
/**
 * Wegpunkt-Hinweise als Liste (km-sortiert, vom Backend so geliefert).
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
    <ul class="hint-list">
        <?php foreach ($hints as $h): ?>
            <?php
            $sentiment = (string)($h['sentiment'] ?? '');
            $isNeg = $sentiment === 'negative';
            $color = $isNeg ? '#e11d48' : '#15803d';
            ?>
            <li class="hint-item">
                <span class="hint-dot" style="background: <?= $color ?>;"
                      title="<?= $isNeg ? 'negativ' : 'positiv' ?>"></span>
                <span class="hint-km-badge"><?= htmlspecialchars($fmtHintKm($h['distance_m'] ?? null), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="hint-body">
                    <span class="hint-label"><?= htmlspecialchars((string)($h['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if (!empty($h['note'])): ?>
                        <span class="hint-note muted"><?= htmlspecialchars((string)$h['note'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
