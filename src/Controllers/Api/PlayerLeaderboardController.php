<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\PlayerLeaderboardService;
use App\Http\Request;
use App\Http\Response;

/**
 * HTTP-Adapter für GET /game/leaderboard (S7, OptionalBearer).
 * scope=world anonym; scope=friends sowie is_me/me brauchen Bearer.
 * Logik liegt in {@see PlayerLeaderboardService}.
 */
final class PlayerLeaderboardController
{
    public function __construct(private readonly PlayerLeaderboardService $service) {}

    public function index(Request $req): void
    {
        $scope  = strtolower(trim((string)($req->query['scope'] ?? 'world')));
        $window = strtolower(trim((string)($req->query['window'] ?? 'season')));
        $metric = strtolower(trim((string)($req->query['metric'] ?? 'area')));

        $uid = null;
        if ($req->user !== null) {
            $candidate = (int)($req->user->internal_id ?? 0);
            $uid = $candidate > 0 ? $candidate : null;
        }

        // friends ist persönlich → Bearer Pflicht (Akzeptanzkriterium 3).
        if ($scope === 'friends' && $uid === null) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }

        Response::json($this->service->leaderboard($scope, $window, $metric, $uid));
    }
}
