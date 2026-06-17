<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\CookieAuth;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;

final class DashboardController
{
    private readonly WebView $view;

    public function __construct(
        private readonly CookieAuth $cookieAuth,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function show(Request $req): void
    {
        $ctx = $this->cookieAuth->resolve($req);
        if ($ctx === null) {
            Response::redirect('/login');
        }

        Csrf::ensureStarted();
        $flash = $_SESSION['flash'] ?? null;
        if (isset($_SESSION['flash'])) unset($_SESSION['flash']);

        $this->view->render('dashboard', [
            '_title' => 'Dashboard · GravelExplorer',
            'user'   => $ctx['user'],
            'flash'  => $flash,
        ]);
    }
}
