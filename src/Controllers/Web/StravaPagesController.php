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
        $state = (string)($req->query['state'] ?? '');
        $code  = (string)($req->query['code'] ?? '');
        $scope = (string)($req->query['scope'] ?? '');
        $err   = (string)($req->query['error'] ?? '');

        // KEIN requireUser() mehr: Der Mobile-Flow (ASWebAuthenticationSession)
        // hat keine Web-Session — ein Pflicht-Redirect auf /auth/web-refresh
        // führte dort zu Login-Seite/500. Die Web-Session ist nur OPTIONAL;
        // beim Web-Flow erzwingt handleCallback() die Session-Bindung anhand
        // des im State hinterlegten flow.
        $ctx = $this->webSession->resolve();
        $expectedUserId = $ctx !== null ? (int)$ctx['user_id'] : null;

        if ($err !== '') {
            $this->finish(null, 'error', 'Strava-Verbindung abgebrochen: ' . $err);
        }

        try {
            $res = $this->strava->handleCallback($state, $code, $expectedUserId, $scope === '' ? null : $scope);
        } catch (StravaException $e) {
            // Flow/return_to sind hier unbekannt → saubere Web-Fehlerseite.
            $this->finish(null, 'error', 'Fehler: ' . $e->getMessage());
        }

        $fullScope = $scope === '' || str_contains($scope, 'activity:read_all');
        $msg = $fullScope
            ? 'Strava verbunden. Du kannst jetzt Aktivitäten importieren.'
            : 'Strava verbunden — aber ohne Zugriff auf private Aktivitäten. '
              . 'Für den vollständigen Import bitte erneut verbinden und „Alle Aktivitäten" erlauben.';
        $this->finish($res['return_to'], $fullScope ? 'connected' : 'limited', $msg);
    }

    /**
     * Schließt den Callback ab: Deep-Link zurück in die App (Mobile-Flow mit
     * return_to) oder Flash + Settings-Seite (Web-Flow).
     *
     * @param string $status connected|limited|error
     */
    private function finish(?string $returnTo, string $status, string $message): never
    {
        if ($returnTo !== null && $returnTo !== '') {
            $sep = str_contains($returnTo, '?') ? '&' : '?';
            Response::redirect($returnTo . $sep . 'strava=' . $status);
        }
        $this->flash($message);
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
