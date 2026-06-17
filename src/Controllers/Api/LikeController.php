<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Engagement\EngagementException;
use App\Engagement\LikeService;
use App\Http\Request;
use App\Http\Response;

/**
 * M4a: Like-Endpoints.
 *
 *   POST   /api/v1/routes/{id}/like    Bearer; 201 (neu) / 200 (idempotent)
 *   DELETE /api/v1/routes/{id}/like    Bearer; 204
 *   GET    /api/v1/routes/{id}/likes   OptionalBearer; Summary
 *
 * Nicht-sichtbare/blockierte Routen liefern 404 (siehe RouteVisibility).
 */
final class LikeController
{
    public function __construct(private readonly LikeService $likes) {}

    public function like(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        $pid    = (string)($req->routeParams['id'] ?? '');
        try {
            $isNew = $this->likes->like($pid, $viewer);
        } catch (EngagementException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::json(['ok' => true], $isNew ? 201 : 200);
    }

    public function unlike(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        $pid    = (string)($req->routeParams['id'] ?? '');
        try {
            $this->likes->unlike($pid, $viewer);
        } catch (EngagementException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::noContent();
    }

    public function summary(Request $req): void
    {
        $viewer = isset($req->user->internal_id) ? (int)$req->user->internal_id : null;
        $pid    = (string)($req->routeParams['id'] ?? '');
        try {
            $summary = $this->likes->summary($pid, $viewer);
        } catch (EngagementException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::json($summary);
    }
}
