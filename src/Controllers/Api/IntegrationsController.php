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
 *   GET    /api/v1/integrations/strava          Status der Verbindung
 *   POST   /api/v1/integrations/strava/import    importiert Activities
 *   DELETE /api/v1/integrations/strava          trennt die Verbindung
 *
 * Der OAuth-Connect/-Callback läuft über die Web-Routen
 * (Cookie-Session), siehe StravaPagesController.
 */
final class IntegrationsController
{
    public function __construct(private readonly StravaService $strava) {}

    public function stravaStatus(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        Response::json($this->strava->status($userId));
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
