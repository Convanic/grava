<?php
/** @var ?string $display_name */
/** @var string $verify_url */
/** @var int $hours_valid */
/** @var string $app_name */
// L8: getrennte Begrüßung, sonst entstand bei leerem display_name
// die Doppelung „Hallo Hallo,".
$greeting = ($display_name !== null && $display_name !== '')
    ? 'Hallo ' . $display_name
    : 'Hallo';
?>
<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><title>E-Mail bestätigen</title></head>
<body style="margin:0;padding:0;background:#f6f7f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1f2421;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6f7f4;padding:24px 0;">
    <tr><td align="center">
        <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="background:#ffffff;border:1px solid #e3e6df;border-radius:10px;overflow:hidden;">
            <tr><td style="padding:24px 24px 12px;">
                <h1 style="margin:0 0 8px;font-size:22px;color:#1f2421;"><?= htmlspecialchars((string)$app_name, ENT_QUOTES, 'UTF-8') ?></h1>
                <p style="margin:0 0 16px;color:#6b7268;">Bestätige deine E-Mail-Adresse</p>
            </td></tr>
            <tr><td style="padding:0 24px 16px;">
                <p><?= htmlspecialchars($greeting, ENT_QUOTES, 'UTF-8') ?>,</p>
                <p>willkommen bei GravelExplorer! Bitte bestätige deine E-Mail-Adresse, indem du auf den folgenden Button klickst:</p>
                <p style="text-align:center;margin:24px 0;">
                    <a href="<?= htmlspecialchars($verify_url, ENT_QUOTES, 'UTF-8') ?>"
                       style="display:inline-block;background:#4a7c2a;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600;">
                        E-Mail-Adresse bestätigen
                    </a>
                </p>
                <p style="font-size:13px;color:#6b7268;">Dieser Link ist <?= (int)$hours_valid ?> Stunden gültig.</p>
                <p style="font-size:13px;color:#6b7268;">Falls der Button nicht funktioniert, kopiere folgende URL in deinen Browser:</p>
                <p style="font-size:13px;word-break:break-all;color:#4a7c2a;"><?= htmlspecialchars($verify_url, ENT_QUOTES, 'UTF-8') ?></p>
                <p style="font-size:13px;color:#6b7268;">Wenn du dich nicht bei GravelExplorer registriert hast, kannst du diese E-Mail ignorieren.</p>
            </td></tr>
            <tr><td style="padding:16px 24px 24px;border-top:1px solid #e3e6df;color:#6b7268;font-size:12px;">
                &copy; <?= date('Y') ?> GravelExplorer
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
