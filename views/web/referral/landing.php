<?php
/** @var string $referral_code */
/** @var string $app_store_url */
/** @var string $register_url */
$code = htmlspecialchars($referral_code, ENT_QUOTES, 'UTF-8');
?>
<section class="card">
    <h1>Du wurdest zu GravelExplorer eingeladen</h1>
    <p>GravelExplorer ist deine App für Gravel-Touren: Routen entdecken, aufzeichnen und teilen.</p>

    <p class="muted">Dein Einlade-Code:</p>
    <p style="font-size:1.6rem;font-weight:700;letter-spacing:.04em;">
        <?= $code ?>
    </p>

    <?php if ($app_store_url !== ''): ?>
        <p>
            <a href="<?= htmlspecialchars($app_store_url, ENT_QUOTES, 'UTF-8') ?>" class="button">
                App laden
            </a>
        </p>
    <?php endif; ?>

    <p class="muted">
        App schon installiert? Öffne diesen Link auf deinem iPhone – die App
        übernimmt den Code automatisch. Falls nicht, gib den Code
        <strong><?= $code ?></strong> bei der Registrierung ein.
    </p>

    <p>
        Lieber im Browser? <a href="<?= htmlspecialchars($register_url, ENT_QUOTES, 'UTF-8') ?>">Hier registrieren</a>
        – der Code ist bereits hinterlegt.
    </p>
</section>
