<?php
declare(strict_types=1);

namespace App\Routes;

use DateTimeImmutable;

/**
 * Aus {@see GeometryStats::compute()} berechnete Aggregate über eine
 * geparste Route. Alle Werte sind plain-PHP — der RouteRepository
 * kann sie direkt in die DB schreiben (mit POINT-Construction für
 * den Centroid, siehe Phase 3).
 */
final class RouteStats
{
    public function __construct(
        public readonly int $pointCount,
        public readonly int $distanceM,
        public readonly int $elevationGainM,
        public readonly float $bboxMinLat,
        public readonly float $bboxMinLon,
        public readonly float $bboxMaxLat,
        public readonly float $bboxMaxLon,
        public readonly float $centroidLat,
        public readonly float $centroidLon,
        public readonly ?DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $endedAt,
    ) {
    }
}
