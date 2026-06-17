<?php
/** @var string $token */
/** @var array<string,string[]> $errors */
/** @var string $_csrf */
$f = static function (string $field) use ($errors): string {
    if (empty($errors[$field])) return '';
    $msgs = array_map(fn($m) => htmlspecialchars((string)$m, ENT_QUOTES, 'UTF-8'), $errors[$field]);
    return '<small class="field-error">' . implode(' ', $msgs) . '</small>';
};
?>
<section class="card">
    <h1>Neues Passwort festlegen</h1>
    <?php if ($token === ''): ?>
        <div class="alert alert-error">Kein Token in der URL gefunden.</div>
    <?php else: ?>
        <form method="post" action="/reset-password" novalidate>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <label>
                Neues Passwort
                <input type="password" name="new_password" autocomplete="new-password" required minlength="10" maxlength="200">
                <?= $f('new_password') ?>
                <?= $f('token') ?>
            </label>
            <button type="submit">Passwort setzen</button>
        </form>
    <?php endif; ?>
</section>
