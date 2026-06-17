<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;

final class Csrf
{
    public const SESSION_KEY = 'csrf_token';

    public static function ensureStarted(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => self::cookieSecure(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_name('ge_session');
            // H7: kein @ — wenn session_start() fehlschlägt, ist das ein
            // ernster Fehler (Permissions, Disk voll, …). Wir loggen und
            // antworten mit 500 statt mit einer halb-funktionalen Session.
            if (!session_start()) {
                error_log('Csrf::ensureStarted: session_start() returned false.');
                Response::html('<!doctype html><meta charset="utf-8"><title>500</title><h1>Sitzung kann nicht gestartet werden.</h1>', 500);
            }
        }
    }

    public static function token(): string
    {
        self::ensureStarted();
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION[self::SESSION_KEY];
    }

    /**
     * Nach jedem Auth-State-Wechsel (Login, Register, Logout, Passwort-Reset)
     * aufrufen: rollt die PHP-Session-ID neu (verhindert Session-Fixation)
     * und vergibt einen frischen CSRF-Token. Der Flash-Wert wird durchgereicht,
     * damit Redirect-Messages nicht verloren gehen.
     */
    public static function rotateForAuthState(): void
    {
        self::ensureStarted();
        $flash = $_SESSION['flash'] ?? null;
        // Komplett saubern, damit kein State der vorigen Session überdauert.
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        if ($flash !== null) {
            $_SESSION['flash'] = $flash;
        }
    }

    public function __invoke(Request $request): void
    {
        if (!in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        self::ensureStarted();
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        // M10: auch JSON-Body akzeptieren — ein zukünftiger Client kann
        // Web-Formulare per fetch() mit application/json absenden.
        $supplied = (string)$request->input('_csrf', $request->header('X-CSRF-Token', ''));
        if ($expected === '' || !hash_equals((string)$expected, $supplied)) {
            Response::html(self::renderError(), 419);
        }
    }

    private static function renderError(): string
    {
        return '<!doctype html><meta charset="utf-8"><title>419</title>'
             . '<h1>Sicherheits-Token abgelaufen</h1>'
             . '<p>Bitte gehe zurück und versuche es erneut.</p>';
    }

    /**
     * H6: konsistent über `Config::instance()` (statt direkt `$_ENV`),
     * damit Test-Overrides oder ein zukünftiges Re-Boot greifen.
     * H3: zusätzlich Reverse-Proxy-HTTPS via X-Forwarded-Proto.
     */
    private static function cookieSecure(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') === 'on') {
            return true;
        }
        $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $first = trim(explode(',', $proto)[0] ?? '');
        if ($first === 'https') {
            return true;
        }
        return Config::instance()->bool('COOKIE_SECURE', false);
    }
}
