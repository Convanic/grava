<?php
/** @var ?string $status */
/** @var ?string $message */
?>
<section class="card">
    <h1>E-Mail-Bestätigung</h1>
    <?php if ($status === 'success'): ?>
        <div class="alert alert-success"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <p><a href="/dashboard">Weiter zum Dashboard</a></p>
    <?php else: ?>
        <div class="alert alert-error"><?= htmlspecialchars((string)$message, ENT_QUOTES, 'UTF-8') ?></div>
        <p><a href="/login">Zum Login</a></p>
    <?php endif; ?>
</section>
