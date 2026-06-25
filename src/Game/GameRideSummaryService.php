<?php
declare(strict_types=1);

namespace App\Game;

use App\Game\Rush\RushRepository;

/**
 * Per-Ride Eroberungs-Zusammenfassung (STRAVA_SHARE_BACKEND.md §2).
 * Read-only, idempotent — reine Ableitung aus game_edge_pass + Besitz.
 */
final class GameRideSummaryService
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly RushRepository $rushes,
    ) {}

    /**
     * @return array<string,mixed>|null null = Route unbekannt
     * @throws RideSummaryNotIngestedException wenn noch keine Pässe
     */
    public function summary(int $userId, string $routePublicId): ?array
    {
        $route = $this->repo->resolveRouteForIngest($routePublicId);
        if ($route === null || $route['user_id'] !== $userId) {
            return null;
        }

        $routeId = (int)$route['route_id'];
        $claimantId = $this->repo->effectiveClaimantId($userId);
        $stats = $this->repo->rideSummaryStats($routeId, $userId, $claimantId);

        if ($stats['edges_total'] === 0) {
            throw new RideSummaryNotIngestedException();
        }

        return [
            'edges_total'        => $stats['edges_total'],
            'edges_new'          => $stats['edges_new'],
            'edges_taken_over'   => $stats['edges_taken_over'],
            'pioneer_names'      => $stats['pioneer_names'],
            'territories_new'    => 0,
            'territory_area_sqm' => 0,
            'points_awarded'     => null,
            'rank_after'         => null,
            'rush'               => $this->rushBlock($routeId, $userId),
        ];
    }

    /** @return array<string,mixed>|null */
    private function rushBlock(int $routeId, int $userId): ?array
    {
        $agg = $this->repo->rideRushAggregate($routeId, $userId);
        if ($agg === null) {
            return null;
        }
        $rush = $this->rushes->byId((int)$agg['rush_id']);
        if ($rush === null) {
            return null;
        }
        $crewName = $this->repo->crewNameById((int)$rush['crew_id']);

        return [
            'type'         => 'crew',
            'crew_name'    => $crewName ?? 'Crew',
            'multiplier'   => round((float)$rush['multiplier'], 1),
            'edges_rushed' => (int)$agg['edges_rushed'],
        ];
    }
}
