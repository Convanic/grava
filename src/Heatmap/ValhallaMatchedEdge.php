<?php
declare(strict_types=1);

namespace App\Heatmap;

/**
 * Eine vom Valhalla-`trace_attributes`-Match gelieferte Graph-Kante.
 *
 * @see ValhallaClient
 */
final class ValhallaMatchedEdge
{
    /**
     * @param int                              $valhallaId Stabile Graph-Edge-ID (innerhalb eines Tile-Stands)
     * @param int|null                         $wayId      OSM way_id (gleich für Hin-/Rückrichtung)
     * @param float                            $lengthM    Kantenlänge in Metern
     * @param list<array{0:float,1:float}>     $geometry   gesnappte Teil-Polyline als [lon, lat]
     * @param string|null                      $surface    OSM-Surface (z. B. "paved_smooth", "gravel")
     */
    public function __construct(
        public readonly int $valhallaId,
        public readonly ?int $wayId,
        public readonly float $lengthM,
        public readonly array $geometry,
        public readonly ?string $surface = null,
    ) {}
}
