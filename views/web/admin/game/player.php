<?php
/**
 * @var string $q
 * @var array<string,mixed>|null $detail
 */
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<nav class="card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <a href="/admin/game">Health</a>
    <a href="/admin/game/config">Config</a>
    <a href="/admin/game/ingest">Ingest</a>
    <a href="/admin/game/moderation">Moderation</a>
    <a href="/admin/game/players">Spieler</a>
    <a href="/admin/game/player">Spieler-Detail</a>
    <a href="/admin/game/crews">Crews</a>
    <a href="/admin/game/edge">Inspector</a>
    <a href="/admin/game/map">Karte</a>
</nav>

<section class="card">
    <h1>Game · Spieler-Detail</h1>
    <form method="get" action="/admin/game/player" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        <input type="text" name="q" value="<?= $e($q) ?>" placeholder="E-Mail oder @handle"
               style="flex:1;min-width:16rem" autofocus>
        <button type="submit">Suchen</button>
    </form>
    <p class="muted" style="margin-top:.5rem">
        Zeigt, welche Strecken eines Spielers in die Wertung einfliessen — und ob
        sie aktuell <strong>solo</strong> oder für die <strong>Crew</strong> gehalten werden.
    </p>
</section>

<?php if ($q !== '' && $detail === null): ?>
    <section class="card"><p class="muted">Kein Spieler mit „<?= $e($q) ?>" gefunden (E-Mail oder Handle).</p></section>
<?php elseif ($detail !== null): ?>
    <?php
        $u = $detail['user'];
        $crew = $detail['crew'];
        $t = $detail['totals'];
        $isCrew = $detail['is_crew_member'];
    ?>
    <section class="card">
        <h2><?= $u['handle'] !== null ? '@' . $e($u['handle']) : $e($u['display_name'] ?? ('User #' . $u['id'])) ?></h2>
        <table class="data-table">
            <tbody>
                <tr><th>E-Mail</th><td><?= $e($u['email']) ?></td></tr>
                <tr><th>User-ID</th><td><?= (int)$u['id'] ?> · Status: <?= $e($u['status']) ?></td></tr>
                <tr><th>Solo-Claimant (Rider)</th><td><?= $detail['rider_claimant_id'] > 0 ? '#' . (int)$detail['rider_claimant_id'] : '— (noch keiner)' ?></td></tr>
                <tr>
                    <th>Crew</th>
                    <td>
                        <?php if ($crew !== null): ?>
                            <strong><?= $e($crew['name']) ?></strong> /<?= $e($crew['slug']) ?>
                            (Claimant #<?= (int)$crew['claimant_id'] ?>, Rolle: <?= $e($crew['role']) ?>)
                        <?php else: ?>
                            <span class="muted">keine — fährt solo</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Effektive Wertung</th>
                    <td>
                        <?php if ($isCrew): ?>
                            Befahrungen zählen aktuell für die <strong>Crew</strong> (Claimant #<?= (int)$detail['effective_claimant_id'] ?>).
                        <?php else: ?>
                            Befahrungen zählen <strong>solo</strong> (Claimant #<?= (int)$detail['effective_claimant_id'] ?>).
                        <?php endif; ?>
                    </td>
                </tr>
                <tr><th>Präsenz-Fenster</th><td><?= (int)$detail['presence_window_days'] ?> Tage (ältere Befahrungen verlieren ihre Präsenz)</td></tr>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Strecken in der Wertung</h2>
        <?php if ($detail['routes'] === []): ?>
            <p class="muted">Keine gewerteten Befahrungen (keine Passes). Diese:r Spieler:in hält aktuell keine Kanten.</p>
        <?php else: ?>
        <p class="muted">
            „Solo/Crew/Fremd/Frei" = wie viele der vom Spieler befahrenen Kanten aktuell
            vom Solo-Claimant, der Crew, einem fremden Claimant oder von niemandem gehalten werden.
        </p>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Strecke</th><th>km</th><th>Letzte Fahrt</th>
                    <th>Kanten</th><th>im Fenster</th>
                    <th>Solo</th><th>Crew</th><th>Fremd</th><th>Frei</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($detail['routes'] as $r): ?>
                <?php $free = $r['pass_edges'] - $r['held_solo'] - $r['held_crew'] - $r['held_other']; ?>
                <tr>
                    <td>
                        <?php if ($r['public_id'] !== null): ?>
                            <a href="https://grava.world/routes/<?= $e($r['public_id']) ?>" target="_blank" rel="noopener">
                                <?= $e($r['title'] ?? $r['public_id']) ?>
                            </a>
                        <?php else: ?>
                            <span class="muted">Route #<?= (int)$r['route_id'] ?> (gelöscht)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $r['km'] !== null ? number_format($r['km'], 1) : '—' ?></td>
                    <td><?= $r['last_ride'] !== null ? $e($r['last_ride']) : '—' ?></td>
                    <td><?= (int)$r['pass_edges'] ?></td>
                    <td><?= (int)$r['in_window_edges'] ?></td>
                    <td><?= (int)$r['held_solo'] ?></td>
                    <td><strong><?= (int)$r['held_crew'] ?></strong></td>
                    <td><?= (int)$r['held_other'] ?></td>
                    <td class="muted"><?= (int)$free ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <?php $totalFree = $t['pass_edges'] - $t['held_solo'] - $t['held_crew'] - $t['held_other']; ?>
                <tr>
                    <th>Summe</th><th></th><th></th>
                    <th><?= (int)$t['pass_edges'] ?></th>
                    <th><?= (int)$t['in_window_edges'] ?></th>
                    <th><?= (int)$t['held_solo'] ?></th>
                    <th><?= (int)$t['held_crew'] ?></th>
                    <th><?= (int)$t['held_other'] ?></th>
                    <th><?= (int)$totalFree ?></th>
                </tr>
            </tfoot>
        </table>
        <?php endif; ?>
    </section>

    <?php if ($detail['unscored_routes'] !== []): ?>
    <section class="card">
        <h2>Hochgeladen, aber nicht in der Wertung</h2>
        <p class="muted">Routen ohne (gültige) Game-Passes — z. B. nie ins Spiel ingestiert.</p>
        <table class="data-table">
            <thead><tr><th>Strecke</th><th>km</th><th>Sichtbarkeit</th><th>Quelle</th><th>Erstellt</th></tr></thead>
            <tbody>
            <?php foreach ($detail['unscored_routes'] as $r): ?>
                <tr>
                    <td><a href="https://grava.world/routes/<?= $e($r['public_id']) ?>" target="_blank" rel="noopener"><?= $e($r['title']) ?></a></td>
                    <td><?= $r['km'] !== null ? number_format($r['km'], 1) : '—' ?></td>
                    <td><?= $e($r['visibility']) ?></td>
                    <td><?= $e($r['source']) ?></td>
                    <td><?= $e($r['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>
<?php endif; ?>
