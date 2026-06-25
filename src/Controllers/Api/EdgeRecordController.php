<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\EdgeRecordService;
use App\Http\Request;
use App\Http\Response;

/**
 * GET /game/edges/{id}/records (OptionalBearer) — Segment-Bestzeiten pro Kante.
 */
final class EdgeRecordController
{
    public function __construct(private readonly EdgeRecordService $service) {}

    public function records(Request $req): void
    {
        $edgeId = (int)($req->routeParams['id'] ?? 0);
        $bike   = strtolower(trim((string)($req->query['bike'] ?? 'muscle')));
        $window = strtolower(trim((string)($req->query['window'] ?? 'all')));
        $limit  = isset($req->query['limit']) ? (int)$req->query['limit'] : null;

        $uid = $this->viewerId($req);
        $res = $this->service->records($edgeId, $bike, $window, $uid, null, $limit);
        if ($res === null) {
            Response::error('not_found', 'Kante nicht gefunden.', 404);
        }
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
