<?php
/** @var ?string $error */
/** @var string $email */
/** @var string $_csrf */
?>
<section class="card">
    <h1>Anmelden</h1>
    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="post" action="/login" novalidate>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">
        <label>
            E-Mail
            <input type="email" name="email" autocomplete="email" required value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <label>
            Passwort
            <input type="password" name="password" autocomplete="current-password" required minlength="10">
        </label>
        <button type="submit">Anmelden</button>
    </form>
    <p class="muted"><a href="/forgot-password">Passwort vergessen?</a> · <a href="/register">Neues Konto erstellen</a></p>
</section>
