<?php
declare(strict_types=1);

namespace App\Game;

use DateTimeImmutable;

/**
 * Ein auf eine OSM-Kante gematchtes Track-Segment inkl. der Auth-
 * Aggregate, die der Pass-Filter (§4.3) braucht. Bewusst vom konkreten
 * Matcher entkoppelt: Fake (Test) und Valhalla (echt) liefern dasselbe VO.
 */
final class MatchedSegment
{
    /**
     * @param list<array{0:float,1:float}> $geometry [lon,lat]-Paare
     */
    public function __construct(
        public readonly int $wayId,
        public readonly int $nodeARef,
        public readonly int $nodeBRef,
        public readonly float $lengthM,
        public readonly array $geometry,
        public readonly ?string $surface,
        public readonly ?float $avgSpeedKmh,
        public readonly ?float $maxHaccM,
        public readonly bool $hasMotion,
        public readonly DateTimeImmutable $riddenAt,
        // Tempo-Wertung (GAME_SEGMENT_SPEED_BACKEND): Ein-/Austritts-Dauer auf der
        // Kante. Null ⇒ wird im Effort-Pfad aus length_m / avg_speed_kmh abgeleitet.
        public readonly ?float $durationS = null,
    ) {}
}
