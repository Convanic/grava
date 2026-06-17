<?php
/** @var array{connected:bool, athlete_id:?string, scope:?string, connected_at:?string} $status */
/** @var bool $configured */
/** @var string $_csrf */
/** @var array<string,mixed> $_authedUser */

$h = static fn(string|int|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
?>
<section class="card">
    <h1>Integrationen</h1>

    <h2>Strava</h2>
    <p class="muted">
        Verbinde dein Strava-Konto und importiere deine Aktivitäten als
        Routen. Importierte Routen sind zunächst <code>privat</code> —
        du kannst sie danach veröffentlichen.
    </p>

    <?php if (!$configured): ?>
        <div class="alert alert-warn">
            Strava ist serverseitig nicht konfiguriert
            (<code>STRAVA_CLIENT_ID</code> fehlt).
        </div>
    <?php elseif (!empty($status['connected'])): ?>
        <div class="alert alert-success">
            <p><strong>Verbunden</strong> (Athlete <?= $h($status['athlete_id']) ?>)</p>
            <?php if (!empty($status['connected_at'])): ?>
                <p class="muted">Seit <?= $h(substr((string)$status['connected_at'], 0, 10)) ?></p>
            <?php endif; ?>
        </div>
        <div class="form-actions">
            <form method="post" action="/settings/integrations/import" class="inline-form">
                <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
                <button type="submit" class="btn-primary">Aktivitäten importieren</button>
            </form>
            <form method="post" action="/settings/integrations/disconnect" class="inline-form" onsubmit="return confirm('Strava-Verbindung trennen?');">
                <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
                <button type="submit" class="btn-link">Verbindung trennen</button>
            </form>
        </div>
    <?php else: ?>
        <p>
            <a href="/auth/strava/connect" class="btn-primary">Mit Strava verbinden</a>
        </p>
    <?php endif; ?>
</section>

<p class="muted" style="text-align:center;">
    <a href="/dashboard">← Zurück zum Dashboard</a>
</p>
