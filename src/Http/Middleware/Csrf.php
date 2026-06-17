<?php
declare(strict_types=1);

namespace App\Http\Middleware;

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
            @session_start();
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

    public function __invoke(Request $request): void
    {
        if (!in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        self::ensureStarted();
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        $supplied = (string)($request->post['_csrf'] ?? $request->header('X-CSRF-Token', ''));
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

    private static function cookieSecure(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') === 'on'
            || strtolower((string)($_ENV['COOKIE_SECURE'] ?? 'false')) === 'true';
    }
}
