<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthException;
use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Support\Validator;

/**
 * Web-Settings-Bereich. Aktuell nur das One-Time-Setting des
 * public_handle (M3 Phase 0). Weitere Settings-Pages docken hier
 * als zusätzliche Methoden + Views unter `views/web/settings/*`
 * an, statt das immer wieder neue Mini-Controller zu produzieren.
 */
final class SettingsPagesController
{
    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    // ---------------------------------------------------------------------
    // GET /settings/handle
    // ---------------------------------------------------------------------
    public function showHandle(Request $req): void
    {
        [$user] = $this->resolveOrRefresh('/settings/handle');
        $this->render('settings/handle', $user, [
            '_title'  => 'Profil-Handle · GravelExplorer',
            'errors'  => [],
            'value'   => '',
            'flash'   => $this->popFlash(),
            'verified' => (bool)$user['email_verified'],
        ]);
    }

    // ---------------------------------------------------------------------
    // POST /settings/handle
    // ---------------------------------------------------------------------
    public function doHandle(Request $req): void
    {
        [$user] = $this->resolveOrRefresh('/settings/handle');

        if (!$user['email_verified']) {
            $this->setFlash('Bitte bestätige zuerst deine E-Mail-Adresse.');
            Response::redirect('/settings/handle');
        }

        $raw = (string)($req->post['public_handle'] ?? '');
        $v = new Validator();
        $handle = $v->publicHandle('public_handle', $raw);
        if ($v->fails()) {
            $this->render('settings/handle', $user, [
                '_title'   => 'Profil-Handle · GravelExplorer',
                'errors'   => $v->errors(),
                'value'    => $raw,
                'flash'    => null,
                'verified' => true,
            ], 422);
        }

        try {
            $this->auth->setPublicHandle((int)$user['internal_id'], (string)$handle);
        } catch (AuthException $e) {
            $this->render('settings/handle', $user, [
                '_title'   => 'Profil-Handle · GravelExplorer',
                'errors'   => ['public_handle' => [$e->getMessage()]],
                'value'    => $raw,
                'flash'    => null,
                'verified' => true,
            ], $e->httpStatus);
        }

        $this->setFlash('Dein Profil-Handle ist jetzt @' . $handle . '. Profil-URL: /u/' . $handle);
        Response::redirect('/dashboard');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return array{0: array<string,mixed>, 1: int}
     */
    private function resolveOrRefresh(string $next): array
    {
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/auth/web-refresh?next=' . rawurlencode($next));
        }
        $user = $this->auth->loadUserPublic($ctx['user_id']);
        $user['internal_id'] = $ctx['user_id'];
        Csrf::ensureStarted();
        return [$user, $ctx['session_id']];
    }

    /** @param array<string,mixed> $vars */
    private function render(string $view, array $user, array $vars, int $status = 200): never
    {
        $vars['_authedUser'] = $user;
        $this->view->render($view, $vars, $status);
    }

    private function setFlash(string $msg): void
    {
        Csrf::ensureStarted();
        $_SESSION['flash'] = $msg;
    }

    private function popFlash(): ?string
    {
        Csrf::ensureStarted();
        if (isset($_SESSION['flash'])) {
            $msg = (string)$_SESSION['flash'];
            unset($_SESSION['flash']);
            return $msg;
        }
        return null;
    }
}
