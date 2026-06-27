<?php
declare(strict_types=1);

namespace App\Community;

use App\Game\GameRepository;
use App\Presence\PresenceService;
use App\Routes\RouteRepository;
use App\Support\Clock;
use DateTimeImmutable;

/**
 * Community-weites Tages-Aggregat (COMMUNITY_TODAY_BACKEND.md).
 * „Heute" = Kalendertag UTC (Server-Zeitzone in public/index.php).
 */
final class CommunityTodayService
{
    public function __construct(
        private readonly RouteRepository $routes,
        private readonly PresenceService $presence,
        private readonly GameRepository $game,
    ) {}

    /**
     * @return array{rides_today:int,distance_today_m:int,active_now:int,edges_today:int}
     */
    public function today(?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::nowUtc();
        $start = $now->setTime(0, 0, 0);
        $end = $start->modify('+1 day');
        $startUtc = $start->format('Y-m-d H:i:s');
        $endUtc = $end->format('Y-m-d H:i:s');

        $agg = $this->routes->aggregateUploadedBetween($startUtc, $endUtc);

        return [
            'rides_today'      => $agg['rides_today'],
            'distance_today_m' => $agg['distance_today_m'],
            'active_now'       => $this->presence->activeCount(),
            'edges_today'      => $this->game->distinctEdgesPassedBetween($startUtc, $endUtc),
        ];
    }
}
