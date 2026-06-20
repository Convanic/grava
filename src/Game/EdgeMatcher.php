<?php
declare(strict_types=1);

namespace App\Game;

use App\Routes\ParsedRoute;

/**
 * Map-Matching-Abstraktion (Spec §9.1). Bildet eine geparste Route auf
 * eine Folge von OSM-Segmenten ab. Implementierungen: ValhallaEdgeMatcher
 * (echt) und FakeEdgeMatcher (deterministische Tests).
 */
interface EdgeMatcher
{
    /**
     * @return list<MatchedSegment> leere Liste = kein Match
     * @throws \RuntimeException wenn der Matcher hart ausfaellt (Spec §10.9)
     */
    public function match(ParsedRoute $route): array;
}
