<?php
/** @var string $email */
/** @var string $_csrf */
?>
<section class="card">
    <h1>Passwort vergessen</h1>
    <p>Gib deine E-Mail-Adresse an. Wir senden dir einen Link zum Zurücksetzen.</p>
    <form method="post" action="/forgot-password" novalidate>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">
        <label>
            E-Mail
            <input type="email" name="email" autocomplete="email" required value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
        </label>
        <button type="submit">Link anfordern</button>
    </form>
    <p class="muted"><a href="/login">Zurück zum Login</a></p>
</section>
