<?php
declare(strict_types=1);

namespace App\Game;

use App\Routes\ParsedRoute;
use App\Support\Clock;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Orchestriert die Spiel-Ingestion einer Route (Spec §4–§5):
 * Match → Authentizitäts-Filter → Pass (idempotent, tagesgedeckelt) →
 * Pionier-Buchung → Live-Recompute der berührten Kanten.
 *
 * Wirft der Matcher (Valhalla-Ausfall), propagiert die Exception nach
 * oben — der nicht-blockierende Upload-Hook fängt sie (Spec §10.9).
 */
final class GameIngestionService
{
    public function __construct(
        private readonly EdgeMatcher $matcher,
        private readonly GameRepository $repo,
        private readonly EdgeRecalculator $recalc,
        private readonly GameConfig $config,
        private readonly PDO $pdo,
    ) {}

    /**
     * @return array{matched:int,passes_new:int,skipped_day_cap:int,
     *               skipped_auth_speed:int,skipped_auth_hacc:int,skipped_no_motion:int}
     */
    public function ingest(
        int $routeId,
        int $userId,
        ParsedRoute $route,
        bool $sourceHasMotion,
        ?DateTimeImmutable $now = null,
    ): array {
        $now ??= Clock::nowUtc();
        $summary = [
            'matched' => 0, 'passes_new' => 0, 'skipped_day_cap' => 0,
            'skipped_auth_speed' => 0, 'skipped_auth_hacc' => 0, 'skipped_no_motion' => 0,
        ];

        $segments = $this->matcher->match($route);
        $summary['matched'] = count($segments);
        if ($segments === []) {
            return $summary;
        }

        $claimantId = $this->repo->riderClaimantId($userId);
        $minSpeed = $this->config->float('auth_min_speed_kmh');
        $maxHacc = $this->config->float('auth_max_hacc_m');
        $requireMotion = $this->config->bool('auth_require_motion');

        $touched = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($segments as $seg) {
                if ($requireMotion && (!$sourceHasMotion || !$seg->hasMotion)) {
                    $summary['skipped_no_motion']++;
                    continue;
                }
                if ($seg->avgSpeedKmh !== null && $seg->avgSpeedKmh < $minSpeed) {
                    $summary['skipped_auth_speed']++;
                    continue;
                }
                if ($seg->maxHaccM !== null && $seg->maxHaccM > $maxHacc) {
                    $summary['skipped_auth_hacc']++;
                    continue;
                }

                $geom = $seg->geometry;
                $first = $geom[0];
                $last = $geom[count($geom) - 1];
                $aId = $this->repo->upsertNode($seg->nodeARef, (float)$first[1], (float)$first[0]);
                $bId = $this->repo->upsertNode($seg->nodeBRef, (float)$last[1], (float)$last[0]);
                if ($aId > $bId) {
                    [$aId, $bId] = [$bId, $aId];
                    $geom = array_reverse($geom);
                }
                [$minLat, $minLon, $maxLat, $maxLon] = $this->bbox($geom);
                $geomJson = json_encode(
                    ['type' => 'LineString', 'coordinates' => $geom],
                    JSON_THROW_ON_ERROR,
                );
                $edgeId = $this->repo->upsertEdge(
                    $seg->wayId, $aId, $bId, $seg->lengthM, $geomJson, $seg->surface,
                    $minLat, $minLon, $maxLat, $maxLon,
                );

                $riddenOn = $seg->riddenAt->format('Y-m-d');
                $riddenAt = $seg->riddenAt->format('Y-m-d H:i:s.v');
                if ($this->repo->insertPassIfAbsent($edgeId, $claimantId, $userId, $routeId, $riddenOn, $riddenAt)) {
                    $summary['passes_new']++;
                } else {
                    $summary['skipped_day_cap']++;
                }
                $touched[$edgeId] = true;
            }

            foreach (array_keys($touched) as $edgeId) {
                $this->repo->refreshEdgeDiscovery($edgeId);
                $this->recalc->recalculate($edgeId, $now);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $summary;
    }

    /** @param list<array{0:float,1:float}> $geom @return array{0:float,1:float,2:float,3:float} */
    private function bbox(array $geom): array
    {
        $lons = array_map(static fn($c) => (float)$c[0], $geom);
        $lats = array_map(static fn($c) => (float)$c[1], $geom);
        return [min($lats), min($lons), max($lats), max($lons)];
    }
}
