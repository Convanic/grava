<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Community\CommunityTodayService;
use App\Http\Request;
use App\Http\Response;

/**
 * GET /community/today — öffentliches Tages-Aggregat (COMMUNITY_TODAY_BACKEND.md).
 */
final class CommunityTodayController
{
    public function __construct(private readonly CommunityTodayService $community) {}

    public function today(Request $req): void
    {
        Response::json(
            $this->community->today(),
            200,
            ['Cache-Control' => 'public, max-age=60'],
        );
    }
}
