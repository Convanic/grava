<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;

final class DashboardController
{
    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function show(Request $req): void
    {
        // H5: Primär-Auth für Web ist jetzt die WebSession. Wenn die
        // abgelaufen oder revoked ist, schicken wir den User über den
        // Refresh-Hop — das ist der einzige Punkt, an dem das
        // path-scoped `ge_refresh`-Cookie noch in Spiel kommt. Schlägt
        // der Hop fehl (kein/abgelaufener Refresh-Token), landen wir auf
        // /login mit einer freundlichen Meldung.
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/auth/web-refresh?next=' . rawurlencode('/dashboard'));
        }

        $user = $this->auth->loadUserPublic($ctx['user_id']);

        Csrf::ensureStarted();
        $flash = $_SESSION['flash'] ?? null;
        if (isset($_SESSION['flash'])) {
            unset($_SESSION['flash']);
        }

        $this->view->render('dashboard', [
            '_title'      => 'Dashboard · GRAVA',
            '_authedUser' => $user,
            'user'        => $user,
            'flash'       => $flash,
        ]);
    }
}
