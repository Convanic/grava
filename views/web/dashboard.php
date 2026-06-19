<?php
/** @var array<string,mixed> $user */
/** @var string $_csrf */
$name = $user['display_name'] ?? null;
$greeting = $name !== null && $name !== '' ? $name : ($user['email'] ?? 'Fahrer*in');

// L7: ISO-8601 lesbar formatieren. Wenn die intl-Extension geladen
// ist, nutzen wir sie für Locale-korrekte Ausgabe — sonst eine
// einfache, deutsche Standardform.
$createdAt = (string)($user['created_at'] ?? '');
$createdAtDisplay = $createdAt;
if ($createdAt !== '') {
    try {
        $dt = new DateTimeImmutable($createdAt);
        if (class_exists('IntlDateFormatter')) {
            $fmt = new IntlDateFormatter(
                'de-DE',
                IntlDateFormatter::LONG,
                IntlDateFormatter::SHORT,
                $dt->getTimezone(),
            );
            $formatted = $fmt->format($dt);
            if (is_string($formatted) && $formatted !== '') {
                $createdAtDisplay = $formatted;
            }
        } else {
            $months = [
                1=>'Januar', 2=>'Februar', 3=>'März',     4=>'April',
                5=>'Mai',    6=>'Juni',    7=>'Juli',     8=>'August',
                9=>'September', 10=>'Oktober', 11=>'November', 12=>'Dezember',
            ];
            $createdAtDisplay = sprintf(
                '%d. %s %d, %s Uhr',
                (int)$dt->format('j'),
                $months[(int)$dt->format('n')] ?? $dt->format('M'),
                (int)$dt->format('Y'),
                $dt->format('H:i'),
            );
        }
    } catch (Throwable) {
        // Fällt zurück auf den Rohstring, damit ein kaputter Datumswert
        // die Seite nicht killt.
    }
}
?>
<section class="card">
    <h1>Hallo, <?= htmlspecialchars((string)$greeting, ENT_QUOTES, 'UTF-8') ?>!</h1>
    <p>Willkommen im GRAVA Dashboard.</p>

    <dl class="profile">
        <dt>E-Mail</dt>
        <dd><?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            <?php if (empty($user['email_verified'])): ?>
                <span class="badge badge-warn">nicht bestätigt</span>
            <?php else: ?>
                <span class="badge badge-ok">bestätigt</span>
            <?php endif; ?>
        </dd>
        <dt>Profil-Handle</dt>
        <dd>
            <?php $handle = (string)($user['public_handle'] ?? ''); if ($handle !== ''): ?>
                <a href="/u/<?= htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') ?>">@<?= htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') ?></a>
            <?php else: ?>
                <span class="muted">noch nicht gesetzt</span> ·
                <a href="/settings/handle">jetzt festlegen</a>
            <?php endif; ?>
        </dd>
        <dt>Konto seit</dt>
        <dd><?= htmlspecialchars($createdAtDisplay, ENT_QUOTES, 'UTF-8') ?></dd>
        <dt>User-ID</dt>
        <dd><code><?= htmlspecialchars((string)($user['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></dd>
    </dl>

    <form method="post" action="/logout">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="btn-secondary">Abmelden</button>
    </form>
</section>
