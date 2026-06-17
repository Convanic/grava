<?php
/** @var ?string $display_name */
/** @var string $reset_url */
/** @var int $minutes_valid */
/** @var string $app_name */
$greeting = ($display_name !== null && $display_name !== '')
    ? 'Hallo ' . $display_name
    : 'Hallo';
?><?= $greeting ?>,

du (oder jemand mit Zugriff auf dein <?= $app_name ?>-Konto) hat ein neues
Passwort angefordert. Öffne den folgenden Link, um ein neues Passwort
festzulegen:

<?= $reset_url ?>


Der Link ist <?= (int)$minutes_valid ?> Minuten gültig und kann nur einmal verwendet werden.

Wenn du diese Anfrage nicht gestellt hast, ignoriere diese E-Mail. Dein
Passwort bleibt unverändert.

— Dein <?= $app_name ?>-Team
