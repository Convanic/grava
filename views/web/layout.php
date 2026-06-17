<?php
/** @var string $content */
/** @var string $_title */
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
            <a href="/login">Login</a>
            <a href="/register">Registrieren</a>
        </nav>
    </header>
    <main class="container">
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
