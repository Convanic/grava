<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\SegmentSpeedService;
use App\Http\Request;
use App\Http\Response;

/**
 * HTTP-Adapter für die Tempo-Wertung (GAME_SEGMENT_SPEED_BACKEND.md):
 *  - GET /game/segments/{id}/leaderboard (OptionalBearer): scope=world anonym,
 *    scope=friends sowie is_me/me brauchen einen Bearer.
 *  - GET /game/me/segments (Bearer): persönliche Bestzeiten.
 * Logik liegt in {@see SegmentSpeedService}.
 */
final class SegmentSpeedController
{
    public function __construct(private readonly SegmentSpeedService $service) {}

    public function leaderboard(Request $req): void
    {
        $edgeId = (int)($req->routeParams['id'] ?? 0);
        $scope  = strtolower(trim((string)($req->query['scope'] ?? 'world')));
        $window = strtolower(trim((string)($req->query['window'] ?? 'season')));

        $uid = $this->viewerId($req);
        if ($scope === 'friends' && $uid === null) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }

        $res = $this->service->leaderboard($edgeId, $scope, $window, $uid);
        if ($res === null) {
            Response::error('not_found', 'Segment existiert nicht.', 404);
        }
        Response::json($res);
    }

    public function mySegments(Request $req): void
    {
        $uid = $this->viewerId($req);
        if ($uid === null) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }
        $window = strtolower(trim((string)($req->query['window'] ?? 'season')));
        $res = $this->service->mySegments(
            $uid,
            $window,
            (int)($req->query['limit']  ?? 50),
            (int)($req->query['offset'] ?? 0),
        );
        Response::json($res);
    }

    private function viewerId(Request $req): ?int
    {
        if ($req->user === null) {
            return null;
        }
        $id = (int)($req->user->internal_id ?? 0);
        return $id > 0 ? $id : null;
    }
}
