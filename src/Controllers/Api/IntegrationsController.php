<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use App\Integrations\Strava\StravaException;
use App\Integrations\Strava\StravaService;

/**
 * M4e: Strava-Integration (API, Bearer-required).
 *
 *   GET    /api/v1/integrations/strava             Status der Verbindung
 *   GET    /api/v1/integrations/strava/connect-url  Mobile-Connect (Authorize-URL)
 *   POST   /api/v1/integrations/strava/import       importiert Activities
 *   DELETE /api/v1/integrations/strava             trennt die Verbindung
 *
 * Der eigentliche OAuth-Callback läuft weiterhin über die Web-Route
 * /auth/strava/callback (Strava-Redirect-Ziel), siehe StravaPagesController —
 * für den Mobile-Flow aber session-los, mit Deep-Link-Rückkehr in die App.
 */
final class IntegrationsController
{
    public function __construct(private readonly StravaService $strava) {}

    public function stravaStatus(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        Response::json($this->strava->status($userId));
    }

    /**
     * Token-basierter Mobile-Connect: liefert eine kurzlebige, an den Bearer-
     * User gebundene Strava-Authorize-URL (State statt Web-Cookie). Die App
     * öffnet sie via ASWebAuthenticationSession; nach dem Callback führt ein
     * Deep-Link (return_to) zurück in die App.
     */
    public function stravaConnectUrl(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        if (!$this->strava->isConfigured()) {
            Response::error('not_configured', 'Strava ist serverseitig nicht konfiguriert.', 503);
        }
        $returnTo = self::sanitizeReturnTo((string)($req->query['return_to'] ?? ''));
        $url = $this->strava->authorizeUrl($userId, 'mobile', $returnTo);
        Response::json(['authorize_url' => $url, 'return_to' => $returnTo]);
    }

    /**
     * Nur eigene App-Schemes / die eigene Domain als Rückkehrziel erlauben
     * (Open-Redirect-Schutz). Default: Custom-Scheme-Deep-Link.
     */
    private static function sanitizeReturnTo(string $v): string
    {
        $v = trim($v);
        if ($v !== '' && (str_starts_with($v, 'grava://') || str_starts_with($v, 'https://grava.world/'))) {
            return mb_substr($v, 0, 255);
        }
        return 'grava://strava-connected';
    }

    public function stravaImport(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        try {
            $res = $this->strava->import($userId);
        } catch (StravaException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::json($res);
    }

    public function stravaDisconnect(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        $this->strava->disconnect($userId);
        Response::noContent();
    }
}
