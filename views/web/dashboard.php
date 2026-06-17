<?php
/** @var array<string,mixed> $user */
/** @var string $_csrf */
$name = $user['display_name'] ?? null;
$greeting = $name !== null && $name !== '' ? $name : ($user['email'] ?? 'Fahrer*in');
?>
<section class="card">
    <h1>Hallo, <?= htmlspecialchars((string)$greeting, ENT_QUOTES, 'UTF-8') ?>!</h1>
    <p>Willkommen im GravelExplorer Dashboard.</p>

    <dl class="profile">
        <dt>E-Mail</dt>
        <dd><?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            <?php if (empty($user['email_verified'])): ?>
                <span class="badge badge-warn">nicht bestätigt</span>
            <?php else: ?>
                <span class="badge badge-ok">bestätigt</span>
            <?php endif; ?>
        </dd>
        <dt>Konto seit</dt>
        <dd><?= htmlspecialchars((string)($user['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>User-ID</dt>
        <dd><code><?= htmlspecialchars((string)($user['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></dd>
    </dl>

    <form method="post" action="/logout">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn-secondary">Abmelden</button>
    </form>
</section>
