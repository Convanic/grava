<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;

/**
 * Nutzerseitige „Funktionen & Neuigkeiten"-Seite (eingeloggt). Veröffentlicht
 * den Funktionsumfang + Changelog + Roadmap-Status für Nutzer. Inhalt ist
 * bewusst statisch in der View gepflegt (MVP) und sicherheitsbereinigt — keine
 * internen Endpunkte, Tokens, Infra- oder Build-Details.
 */
final class FeaturesPagesController
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
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/auth/web-refresh?next=' . rawurlencode('/features'));
        }
        $user = $this->auth->loadUserPublic($ctx['user_id']);
        Csrf::ensureStarted();

        $this->view->render('features', [
            '_title'      => 'Funktionen & Neuigkeiten · GRAVA',
            '_authedUser' => $user,
        ]);
    }
}
