<?php
/** @var bool $verified */
/** @var string $_csrf */
/** @var array<string,mixed> $_authedUser */

$h = static fn(string|int|null $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$handle = $_authedUser['public_handle'] ?? null;
?>
<section class="card">
    <h1>Profilbild</h1>

    <p class="muted">
        Dein Profilbild erscheint auf deinem öffentlichen Profil. Erlaubt
        sind JPEG, PNG oder WebP bis 5 MB; das Bild wird automatisch auf
        max. 512&nbsp;px verkleinert.
    </p>

    <?php if ($handle): ?>
        <div class="avatar-preview">
            <img src="/u/<?= $h($handle) ?>/avatar?cb=<?= time() ?>" alt="Aktuelles Profilbild" width="128" height="128">
            <p class="muted">Aktuelles Bild (oder Platzhalter, falls keins gesetzt).</p>
        </div>
    <?php endif; ?>

    <?php if (!$verified): ?>
        <div class="alert alert-warn">
            Bitte bestätige zuerst deine E-Mail-Adresse, bevor du ein
            Profilbild hochlädst.
        </div>
    <?php else: ?>
        <form method="post" action="/settings/avatar" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
            <label>
                Bild auswählen
                <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required>
            </label>
            <div class="form-actions">
                <button type="submit">Hochladen</button>
            </div>
        </form>

        <form method="post" action="/settings/avatar/delete" onsubmit="return confirm('Profilbild entfernen?');" style="margin-top:12px;">
            <input type="hidden" name="_csrf" value="<?= $h($_csrf) ?>">
            <button type="submit" class="btn-link">Profilbild entfernen</button>
        </form>
    <?php endif; ?>
</section>

<p class="muted" style="text-align:center;">
    <a href="/dashboard">← Zurück zum Dashboard</a>
</p>
