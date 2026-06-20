<?php
declare(strict_types=1);

namespace App\Game;

use App\Routes\ParsedRoute;
use RuntimeException;

/**
 * Liefert eine fest vorgegebene Segment-Folge — unabhaengig von der Route.
 * Damit sind alle nachgelagerten Berechnungen deterministisch testbar.
 * Mit $throw=true simuliert er einen Valhalla-Ausfall (Spec §10.9).
 */
final class FakeEdgeMatcher implements EdgeMatcher
{
    /** @param list<MatchedSegment> $segments */
    public function __construct(
        private readonly array $segments,
        private readonly bool $throw = false,
    ) {}

    public function match(ParsedRoute $route): array
    {
        if ($this->throw) {
            throw new RuntimeException('Fake-Matcher: simulierter Valhalla-Ausfall.');
        }
        return $this->segments;
    }
}
