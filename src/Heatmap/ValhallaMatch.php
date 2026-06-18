<?php
declare(strict_types=1);

namespace App\Heatmap;

/**
 * Ergebnis eines Valhalla-`trace_attributes`-Matches: die gematchten
 * Kanten plus die Zuordnung der Eingabepunkte zu Kanten.
 *
 * `matchedPoints[i]` korrespondiert mit dem i-ten Eingabepunkt; `edgeIndex`
 * verweist in {@see $edges}. Damit lässt sich z. B. ein Surface-Score je
 * Eingabepunkt der jeweiligen Kante zuordnen.
 *
 * @see ValhallaClient
 */
final class ValhallaMatch
{
    /**
     * @param list<ValhallaMatchedEdge>                                       $edges
     * @param list<array{edgeIndex:int,type:string,lat:float,lon:float}>      $matchedPoints
     */
    public function __construct(
        public readonly array $edges,
        public readonly array $matchedPoints,
    ) {}
}
