<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;

/**
 * Öffentliche „Funktionen & Neuigkeiten"-Seite. Veröffentlicht
 * den Funktionsumfang + Changelog + Roadmap-Status für alle Besucher.
 * Inhalt ist bewusst statisch in der View gepflegt (MVP) und
 * sicherheitsbereinigt — keine internen Endpunkte, Tokens, Infra- oder
 * Build-Details.
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
        // Optional: Load user if logged in, otherwise null
        $user = null;
        $ctx = $this->webSession->resolve();
        if ($ctx !== null) {
            $user = $this->auth->loadUserPublic($ctx['user_id']);
            Csrf::ensureStarted();
        }

        $this->view->render('features', [
            '_title'      => 'Funktionen & Neuigkeiten · GRAVA',
            '_authedUser' => $user,
            '_pageStyles' => ['/assets/landing/landing.css'],
            '_layoutWide' => true,
        ]);
    }
}
