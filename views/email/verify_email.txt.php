<?php
/** @var ?string $display_name */
/** @var string $verify_url */
/** @var int $hours_valid */
/** @var string $app_name */
$greeting = ($display_name !== null && $display_name !== '')
    ? 'Hallo ' . $display_name
    : 'Hallo';
?><?= $greeting ?>,

willkommen bei <?= $app_name ?>!

Bitte bestätige deine E-Mail-Adresse über folgenden Link:

<?= $verify_url ?>


Der Link ist <?= (int)$hours_valid ?> Stunden gültig.

Wenn du dich nicht bei <?= $app_name ?> registriert hast, kannst du diese E-Mail ignorieren.

— Dein <?= $app_name ?>-Team
