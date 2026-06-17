<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Discovery\FeedService;
use App\Http\Request;
use App\Http\Response;

/**
 * M3 Phase 5: GET /api/v1/feed (auth required).
 *
 * Liefert die letzten public Routen der gefolgten User.
 */
final class FeedController
{
    public function __construct(private readonly FeedService $feed) {}

    public function show(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        $limit  = (int)($req->query['limit']  ?? 20);
        $offset = (int)($req->query['offset'] ?? 0);
        Response::json($this->feed->getFeed($viewer, $limit, $offset));
    }
}
