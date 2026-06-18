<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use App\Referral\ReferralService;

/**
 * M7: Empfehlungen — eigene Statistik der App.
 *
 * Endpoint:
 *  - GET /api/v1/referrals/me   Eigener Code/Link + Counts + Geworbenen-Liste
 *
 * Es gibt bewusst KEINE öffentliche Leaderboard-API: die App zeigt nur die
 * eigene Statistik. Die Bestenliste lebt ausschließlich in der Admin-Web-UI.
 */
final class ReferralController
{
    public function __construct(private readonly ReferralService $referrals) {}

    public function me(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        if ($userId <= 0) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }
        Response::json($this->referrals->overviewForUser($userId));
    }
}
