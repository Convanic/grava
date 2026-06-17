<?php
declare(strict_types=1);

/*
 * GravelExplorer — front controller for API, web and CLI.
 *
 * Routes:
 *   /api/v1/auth/*            JSON API (Bearer auth)
 *   /api/v1/users/*           JSON API (Bearer auth)
 *   /, /login, /register, ... server-rendered web pages (cookie + CSRF)
 *
 * Also doubles as a CLI entry point:
 *   php public/index.php cli:migrate
 *   php public/index.php cron:cleanup
 */

use App\Auth\AuthService;
use App\Auth\CookieAuth;
use App\Auth\PasswordService;
use App\Auth\RateLimiter;
use App\Auth\TokenService;
use App\Cli\Commands;
use App\Config\Config;
use App\Controllers\Api\AuthController;
use App\Controllers\Api\UserController;
use App\Controllers\Web\AuthPagesController;
use App\Controllers\Web\DashboardController;
use App\Http\Middleware\Csrf;
use App\Http\Middleware\RequireBearer;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Mail\MailService;

$basePath = dirname(__DIR__);

// Beim PHP built-in server (`php -S ... public/index.php`) übernimmt dieses
// Script auch das Routing. Statische Dateien unter public/ wollen wir aber
// direkt vom Server ausliefern lassen (Apache/.htaccess macht das produktiv).
if (PHP_SAPI === 'cli-server') {
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $uri;
    if ($uri !== '/' && is_file($file)) {
        return false;
    }
}

require_once $basePath . '/vendor/autoload.php';

Config::boot($basePath);
$config = Config::instance();

date_default_timezone_set('UTC');

// In production we never leak details. In dev we surface them to speed up
// iteration. Logs always go to storage/logs/php.log via custom handler.
$isProd = $config->isProduction();
ini_set('display_errors', $isProd ? '0' : '1');
ini_set('log_errors', '1');
ini_set('error_log', $basePath . '/storage/logs/php.log');
error_reporting($isProd ? (E_ALL & ~E_DEPRECATED & ~E_NOTICE) : E_ALL);

set_exception_handler(function (\Throwable $e) use ($isProd, $basePath): void {
    @file_put_contents(
        $basePath . '/storage/logs/php.log',
        sprintf("[%s] %s in %s:%d\n%s\n", gmdate('Y-m-d H:i:s'), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()),
        FILE_APPEND
    );
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error' => [
            'code'    => 'server_error',
            'message' => $isProd ? 'Interner Serverfehler.' : $e->getMessage(),
        ],
    ]);
    exit;
});

// Wire up services (poor-man's DI).
$passwords  = new PasswordService();
$tokens     = new TokenService($config);
$rate       = new RateLimiter($config);
$mailer     = new MailService($config, $basePath, $basePath . '/views/email');
$auth       = new AuthService($config, $passwords, $tokens, $mailer);
$cookieAuth = new CookieAuth($config, $tokens);

// ---------------------------------------------------------------------------
// CLI dispatch
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli') {
    $cli = new Commands($basePath, $tokens);
    exit($cli->run($_SERVER['argv'] ?? []));
}

// ---------------------------------------------------------------------------
// HTTP dispatch
// ---------------------------------------------------------------------------
$request = Request::fromGlobals();
$router  = new Router();

$apiBase = rtrim((string)$config->get('API_BASE_PATH', '/api/v1'), '/');
$requireBearer = new RequireBearer($tokens);
$csrf = new Csrf();

$apiAuth  = new AuthController($auth, $rate);
$apiUsers = new UserController($auth);
$webAuth  = new AuthPagesController($auth, $cookieAuth, $rate, $basePath . '/views');
$webHome  = new DashboardController($cookieAuth, $basePath . '/views');

// ---- JSON API ----
$router->post("{$apiBase}/auth/register",                fn($r) => $apiAuth->register($r));
$router->post("{$apiBase}/auth/login",                   fn($r) => $apiAuth->login($r));
$router->post("{$apiBase}/auth/refresh",                 fn($r) => $apiAuth->refresh($r));
$router->post("{$apiBase}/auth/logout",                  fn($r) => $apiAuth->logout($r),           [$requireBearer]);
$router->post("{$apiBase}/auth/logout-all",              fn($r) => $apiAuth->logoutAll($r),        [$requireBearer]);
$router->post("{$apiBase}/auth/password/change",         fn($r) => $apiAuth->changePassword($r),   [$requireBearer]);
$router->post("{$apiBase}/auth/password/forgot",         fn($r) => $apiAuth->forgotPassword($r));
$router->post("{$apiBase}/auth/password/reset",          fn($r) => $apiAuth->resetPassword($r));
$router->post("{$apiBase}/auth/email/verify",            fn($r) => $apiAuth->verifyEmail($r));
$router->post("{$apiBase}/auth/email/verify/resend",     fn($r) => $apiAuth->resendVerification($r));

$router->get("{$apiBase}/users/me",                       fn($r) => $apiUsers->me($r),     [$requireBearer]);
$router->patch("{$apiBase}/users/me",                     fn($r) => $apiUsers->updateMe($r), [$requireBearer]);
$router->delete("{$apiBase}/users/me",                    fn($r) => $apiUsers->deleteMe($r), [$requireBearer]);

// ---- Web pages ----
$router->get('/',                  fn($r) => Response::redirect('/dashboard'));
$router->get('/login',             fn($r) => $webAuth->showLogin($r));
$router->post('/login',            fn($r) => $webAuth->doLogin($r),           [$csrf]);
$router->get('/register',          fn($r) => $webAuth->showRegister($r));
$router->post('/register',         fn($r) => $webAuth->doRegister($r),        [$csrf]);
$router->get('/forgot-password',   fn($r) => $webAuth->showForgot($r));
$router->post('/forgot-password',  fn($r) => $webAuth->doForgot($r),          [$csrf]);
$router->get('/reset-password',    fn($r) => $webAuth->showReset($r));
$router->post('/reset-password',   fn($r) => $webAuth->doReset($r),           [$csrf]);
$router->get('/verify-email',      fn($r) => $webAuth->showVerify($r));
$router->get('/dashboard',         fn($r) => $webHome->show($r));
$router->post('/logout',           fn($r) => $webAuth->doLogout($r),          [$csrf]);

// Healthcheck
$router->get('/healthz', function ($r): void {
    Response::json(['status' => 'ok', 'time' => gmdate('Y-m-d\TH:i:s\Z')]);
});

$router->dispatch($request);
