<?php
/**
 * Wiederverwendbares Insights-Panel für Routen-Detailseiten.
 *
 * Erwartet im Scope:
 * @var array{
 *   elevation: array{points: list<array{d:int,e:int}>, hasData:bool, minE:int, maxE:int, gain:int, distanceM:int},
 *   surface: array{hasData:bool, totalM:int, buckets: list<array{score:int|null,distanceM:int,percent:float}>}
 * }|null $insights
 *
 * Rein server-seitig gerendert (Inline-SVG + HTML-Balken) — kein JavaScript,
 * damit es unter der strengen CSP läuft.
 */
$ins = $insights ?? null;
if (!is_array($ins)) {
    return;
}
$elev = $ins['elevation'] ?? ['hasData' => false];
$surf = $ins['surface']   ?? ['hasData' => false];
if (empty($elev['hasData']) && empty($surf['hasData'])) {
    return;
}

$hh = static fn(string|int|float|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$fmtDist = static function (int $m): string {
    return $m >= 1000
        ? number_format($m / 1000, 1, ',', '.') . ' km'
        : $m . ' m';
};

// Farbskala identisch zur Karte (map-route.js): niedriger Score = glatt = grün,
// hoher Score = grob/Gravel = rot.
$scoreColors = [0 => '#15803d', 1 => '#84cc16', 2 => '#eab308', 3 => '#f97316', 4 => '#e11d48', 5 => '#b91c1c'];
$scoreLabels = [
    0 => 'sehr glatt',
    1 => 'glatt',
    2 => 'überwiegend fest',
    3 => 'gemischt',
    4 => 'ruppig',
    5 => 'grob / Schotter',
];
?>
<section class="route-insights">
    <h2>Analyse</h2>

    <?php if (!empty($elev['hasData'])):
        $pts   = $elev['points'];
        $W     = 1000;
        $H     = 200;
        $padT  = 12;
        $padB  = 16;
        $plotH = $H - $padT - $padB;
        $maxD  = max(1, (int)$elev['distanceM']);
        $minE  = (int)$elev['minE'];
        $maxE  = (int)$elev['maxE'];
        $spanE = max(1, $maxE - $minE);
        $baseY = $padT + $plotH;

        $coord = static function (array $p) use ($W, $padT, $plotH, $maxD, $minE, $spanE): array {
            $x = $p['d'] / $maxD * $W;
            $y = $padT + (1 - (($p['e'] - $minE) / $spanE)) * $plotH;
            return [round($x, 1), round($y, 1)];
        };

        $linePoints = [];
        foreach ($pts as $p) {
            [$x, $y] = $coord($p);
            $linePoints[] = $x . ',' . $y;
        }
        [$x0] = $coord($pts[0]);
        [$xN] = $coord($pts[count($pts) - 1]);

        $areaPath = 'M ' . $x0 . ' ' . round($baseY, 1);
        foreach ($pts as $p) {
            [$x, $y] = $coord($p);
            $areaPath .= ' L ' . $x . ' ' . $y;
        }
        $areaPath .= ' L ' . $xN . ' ' . round($baseY, 1) . ' Z';
    ?>
        <div class="insight-block">
            <div class="insight-head">
                <h3>Höhenprofil</h3>
                <span class="muted">
                    ↑ <?= $hh($elev['gain']) ?> m · <?= $hh($minE) ?>–<?= $hh($maxE) ?> m ü. NN · <?= $hh($fmtDist((int)$elev['distanceM'])) ?>
                </span>
            </div>
            <svg class="elev-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" preserveAspectRatio="none"
                 role="img" aria-label="Höhenprofil der Route">
                <path d="<?= $hh($areaPath) ?>" class="elev-area"/>
                <polyline points="<?= $hh(implode(' ', $linePoints)) ?>" class="elev-line"
                          vector-effect="non-scaling-stroke"/>
            </svg>
            <div class="elev-axis">
                <span>0</span>
                <span><?= $hh($fmtDist((int)$elev['distanceM'])) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($surf['hasData'])): ?>
        <div class="insight-block">
            <div class="insight-head">
                <h3>Untergrund</h3>
                <span class="muted">bewertet über <?= $hh($fmtDist((int)$surf['totalM'])) ?></span>
            </div>

            <div class="surface-bar" role="img" aria-label="Untergrund-Verteilung">
                <?php foreach ($surf['buckets'] as $b):
                    $score = $b['score'];
                    $color = $score === null ? '#9ca3af' : ($scoreColors[$score] ?? '#9ca3af');
                    $label = $score === null ? 'ohne Bewertung' : ($scoreLabels[$score] ?? ('Score ' . $score));
                ?>
                    <span class="surface-seg"
                          style="width: <?= $hh($b['percent']) ?>%; background: <?= $hh($color) ?>;"
                          title="<?= $hh($label) ?> – <?= $hh($b['percent']) ?>%"></span>
                <?php endforeach; ?>
            </div>

            <ul class="surface-legend">
                <?php foreach ($surf['buckets'] as $b):
                    $score = $b['score'];
                    $color = $score === null ? '#9ca3af' : ($scoreColors[$score] ?? '#9ca3af');
                    $label = $score === null ? 'ohne Bewertung' : ($scoreLabels[$score] ?? ('Score ' . $score));
                ?>
                    <li>
                        <span class="swatch" style="background: <?= $hh($color) ?>;"></span>
                        <span class="surface-label"><?= $hh($label) ?></span>
                        <span class="surface-pct"><?= $hh(number_format((float)$b['percent'], 1, ',', '.')) ?> %</span>
                        <span class="muted surface-km"><?= $hh($fmtDist((int)$b['distanceM'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</section>
