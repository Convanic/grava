<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Auth\RateLimiter;
use App\Engagement\CommentService;
use App\Engagement\EngagementException;
use App\Http\Request;
use App\Http\Response;

/**
 * M4b: Kommentar-Endpoints.
 *
 *   GET    /api/v1/routes/{id}/comments        OptionalBearer; paginiert
 *   POST   /api/v1/routes/{id}/comments        Bearer+Verified; 201
 *   DELETE /api/v1/routes/{id}/comments/{cid}  Bearer; 204 (Autor/Owner)
 *
 * Nicht-sichtbare/blockierte Routen → 404 (RouteVisibility).
 */
final class CommentController
{
    public function __construct(
        private readonly CommentService $comments,
        private readonly RateLimiter $rate,
    ) {}

    public function list(Request $req): void
    {
        $viewer = isset($req->user->internal_id) ? (int)$req->user->internal_id : null;
        $pid    = (string)($req->routeParams['id'] ?? '');
        try {
            $res = $this->comments->list(
                $pid,
                $viewer,
                (int)($req->query['limit']  ?? 20),
                (int)($req->query['offset'] ?? 0),
            );
        } catch (EngagementException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::json($res);
    }

    public function create(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        $pid    = (string)($req->routeParams['id'] ?? '');

        // Rate-Limit pro User (Spam-Schutz), analog Auth-Aktionen.
        if ($this->rate->hit('comment_create', 'u:' . $viewer, 30)) {
            header('Retry-After: ' . $this->rate->retryAfter());
            Response::error('rate_limited', 'Zu viele Kommentare. Bitte später erneut.', 429);
        }

        $body = (string)($req->json['body'] ?? $req->post['body'] ?? '');
        try {
            $comment = $this->comments->create($pid, $viewer, $body);
        } catch (EngagementException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::json(['comment' => $comment], 201);
    }

    public function delete(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        $pid    = (string)($req->routeParams['id'] ?? '');
        $cid    = (int)($req->routeParams['cid'] ?? 0);
        try {
            $this->comments->delete($pid, $cid, $viewer);
        } catch (EngagementException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::noContent();
    }
}
