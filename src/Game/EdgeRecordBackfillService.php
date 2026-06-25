<?php
declare(strict_types=1);

namespace App\Game;

use App\Routes\GeometryParseException;
use App\Routes\GeometryParser;
use App\Routes\RouteService;

/**
 * Einmaliger Backfill historischer Rekord-Daten (Spec §6).
 * Re-ingestiert Routen idempotent über den bestehenden Ingest-Pfad.
 */
final class EdgeRecordBackfillService
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameIngestionService $ingest,
        private readonly RouteService $routes,
        private readonly GeometryParser $parser,
    ) {}

    /**
     * @return array{processed:int,errors:int,last_route_id:int}
     */
    public function run(int $limit = 100, int $sleepMs = 500, int $afterRouteId = 0): array
    {
        $processed = 0;
        $errors = 0;
        $lastRouteId = $afterRouteId;
        $routeIds = $this->repo->routeIdsNeedingRecordBackfill($limit, $afterRouteId);

        foreach ($routeIds as $routeId) {
            $lastRouteId = $routeId;
            $meta = $this->repo->routeForIngest($routeId);
            if ($meta === null) {
                $errors++;
                continue;
            }
            try {
                $loaded = $this->routes->loadPayloadByPublicId($meta['public_id']);
                $parsed = $this->parser->parse($loaded['payload']);
            } catch (GeometryParseException) {
                $errors++;
                continue;
            }
            $hasMotion = $parsed->pointCount() > 1;
            try {
                $this->ingest->ingest($routeId, $meta['user_id'], $parsed, $hasMotion);
            } catch (MatchUnavailableException) {
                $errors++;
                continue;
            }
            $processed++;
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        return ['processed' => $processed, 'errors' => $errors, 'last_route_id' => $lastRouteId];
    }
}
