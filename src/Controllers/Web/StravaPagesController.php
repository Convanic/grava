<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Integrations\Strava\StravaException;
use App\Integrations\Strava\StravaService;

/**
 * M4e: Web-Flow für die Strava-Integration.
 *
 *   GET  /settings/integrations           Status-Seite
 *   GET  /auth/strava/connect             startet OAuth (Redirect)
 *   GET  /auth/strava/callback            OAuth-Rückkanal
 *   POST /settings/integrations/import    importiert Activities
 *   POST /settings/integrations/disconnect trennt die Verbindung
 */
final class StravaPagesController
{
    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly StravaService $strava,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function settings(Request $req): void
    {
        $user = $this->requireUser('/settings/integrations');
        $status = $this->strava->status((int)$user['internal_id']);
        $this->view->render('settings/integrations', [
            '_title'      => 'Integrationen · GRAVA',
            '_authedUser' => $user,
            'status'      => $status,
            'configured'  => $this->strava->isConfigured(),
            'flash'       => $this->popFlash(),
        ]);
    }

    public function connect(Request $req): void
    {
        $user = $this->requireUser('/settings/integrations');
        if (!$this->strava->isConfigured()) {
            $this->flash('Strava ist serverseitig nicht konfiguriert.');
            Response::redirect('/settings/integrations');
        }
        $url = $this->strava->authorizeUrl((int)$user['internal_id']);
        Response::redirect($url);
    }

    public function callback(Request $req): void
    {
        // Session-Binding gegen OAuth-CSRF/Account-Linking: Der Callback
        // läuft als Top-Level-Redirect im Browser des eingeloggten Users
        // (SameSite=Lax sendet das Session-Cookie mit). Wir verlangen eine
        // Session und koppeln den State an genau diesen User.
        $user  = $this->requireUser('/settings/integrations');
        $state = (string)($req->query['state'] ?? '');
        $code  = (string)($req->query['code'] ?? '');
        $scope = (string)($req->query['scope'] ?? '');
        $err   = (string)($req->query['error'] ?? '');
        if ($err !== '') {
            $this->flash('Strava-Verbindung abgebrochen: ' . $err);
            Response::redirect('/settings/integrations');
        }
        try {
            $this->strava->handleCallback($state, $code, (int)$user['internal_id'], $scope === '' ? null : $scope);
            if ($scope !== '' && !str_contains($scope, 'activity:read_all')) {
                $this->flash('Strava verbunden — aber ohne Zugriff auf private Aktivitäten. '
                    . 'Für den vollständigen Import bitte erneut verbinden und „Alle Aktivitäten" erlauben.');
            } else {
                $this->flash('Strava verbunden. Du kannst jetzt Aktivitäten importieren.');
            }
        } catch (StravaException $e) {
            $this->flash('Fehler: ' . $e->getMessage());
        }
        Response::redirect('/settings/integrations');
    }

    public function import(Request $req): void
    {
        $user = $this->requireUser('/settings/integrations');
        try {
            $res = $this->strava->import((int)$user['internal_id']);
            $this->flash(sprintf(
                '%d Route(n) importiert, %d übersprungen (von %d Aktivitäten).',
                $res['imported'], $res['skipped'], $res['total']
            ));
        } catch (StravaException $e) {
            $this->flash('Fehler: ' . $e->getMessage());
        }
        Response::redirect('/settings/integrations');
    }

    public function disconnect(Request $req): void
    {
        $user = $this->requireUser('/settings/integrations');
        $this->strava->disconnect((int)$user['internal_id']);
        $this->flash('Strava-Verbindung getrennt.');
        Response::redirect('/settings/integrations');
    }

    /** @return array<string,mixed> */
    private function requireUser(string $next): array
    {
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/auth/web-refresh?next=' . rawurlencode($next));
        }
        $user = $this->auth->loadUserPublic($ctx['user_id']);
        $user['internal_id'] = $ctx['user_id'];
        Csrf::ensureStarted();
        return $user;
    }

    private function flash(string $msg): void
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
