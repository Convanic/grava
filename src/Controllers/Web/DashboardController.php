<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\CookieAuth;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;

final class DashboardController
{
    public function __construct(
        private readonly CookieAuth $cookieAuth,
        private readonly string $viewsPath,
    ) {}

    public function show(Request $req): void
    {
        $ctx = $this->cookieAuth->resolve($req);
        if ($ctx === null) {
            Response::redirect('/login');
        }

        Csrf::ensureStarted();
        $user = $ctx['user'];
        $csrf = Csrf::token();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');

        $_view = 'dashboard';
        $_title = 'Dashboard · GravelExplorer';
        $_csrf  = $csrf;
        $flash  = $_SESSION['flash'] ?? null;
        if (isset($_SESSION['flash'])) unset($_SESSION['flash']);

        ob_start();
        include $this->viewsPath . '/web/dashboard.php';
        $content = (string)ob_get_clean();

        include $this->viewsPath . '/web/layout.php';
        exit;
    }
}
