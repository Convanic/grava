<?php
/** @var string $content */
/** @var string $_title */
/** @var string $_csrf */
$_authedUser  = $_authedUser  ?? null;
$_layoutWide  = $_layoutWide  ?? false;
$mainClass    = 'container' . ($_layoutWide ? ' container--wide' : '');
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($_title ?? 'GravelExplorer', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <header class="site-header">
        <a href="/" class="brand">GravelExplorer</a>
        <nav>
        <?php if ($_authedUser !== null): ?>
            <a href="/dashboard">Dashboard</a>
            <a href="/routes">Routen</a>
            <form method="post" action="/logout" class="nav-form">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="nav-button">Abmelden</button>
            </form>
        <?php else: ?>
            <a href="/login">Login</a>
            <a href="/register">Registrieren</a>
        <?php endif; ?>
        </nav>
    </header>
    <main class="<?= $mainClass ?>">
        <?php if (!empty($flash)): ?>
            <div class="flash"><?= htmlspecialchars((string)$flash, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?= $content ?? '' ?>
    </main>
    <footer class="site-footer">
        <small>&copy; <?= date('Y') ?> GravelExplorer</small>
    </footer>
</body>
</html>
