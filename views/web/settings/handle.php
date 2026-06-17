<?php
/** @var array<string,list<string>> $errors */
/** @var string $value */
/** @var bool $verified */
/** @var string $_csrf */
/** @var array<string,mixed> $_authedUser */

$existing = $_authedUser['public_handle'] ?? null;
$err = static function (string $field) use ($errors): string {
    if (empty($errors[$field])) { return ''; }
    return '<span class="field-error">' . htmlspecialchars((string)$errors[$field][0], ENT_QUOTES, 'UTF-8') . '</span>';
};
?>
<section class="card">
    <h1>Profil-Handle</h1>

    <p class="muted">
        Dein Handle ist Teil deiner öffentlichen Profil-URL
        (<code>/u/dein-handle</code>) und identifiziert dich in der
        Discovery, wenn deine Routen auf <code>public</code> gestellt
        sind.
    </p>

    <?php if ($existing !== null && $existing !== ''): ?>
        <div class="alert alert-success">
            <p><strong>Dein Handle: @<?= htmlspecialchars((string)$existing, ENT_QUOTES, 'UTF-8') ?></strong></p>
            <p>Profil-URL: <a href="/u/<?= htmlspecialchars((string)$existing, ENT_QUOTES, 'UTF-8') ?>">/u/<?= htmlspecialchars((string)$existing, ENT_QUOTES, 'UTF-8') ?></a></p>
            <p class="muted">
                Der Handle ist endgültig. Falls du ihn ändern willst,
                schreib uns über die Support-Mail.
            </p>
        </div>
    <?php else: ?>

        <?php if (!$verified): ?>
            <div class="alert alert-warn">
                Bitte bestätige zuerst deine E-Mail-Adresse, bevor du einen
                Handle setzt.
            </div>
        <?php else: ?>

            <p>
                <strong>Achtung:</strong> Der Handle kann aktuell nur einmal
                gesetzt werden. Wähl ihn mit Bedacht.
            </p>

            <form method="post" action="/settings/handle" novalidate>
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">

                <label>
                    Dein Handle
                    <input type="text" name="public_handle"
                           pattern="[a-z0-9_]{3,30}"
                           minlength="3" maxlength="30"
                           required autocomplete="off"
                           placeholder="z. B. gravelfan"
                           value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>">
                    <small class="muted">
                        3–30 Zeichen, nur a–z, 0–9 und _. Kein Doppel-_.
                    </small>
                    <?= $err('public_handle') ?>
                </label>

                <div class="form-actions">
                    <button type="submit">Handle festlegen</button>
                    <a href="/dashboard" class="btn-link">Abbrechen</a>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>

<p class="muted" style="text-align:center;">
    <a href="/dashboard">← Zurück zum Dashboard</a>
</p>
