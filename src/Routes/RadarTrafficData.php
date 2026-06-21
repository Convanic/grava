<?php
declare(strict_types=1);

namespace App\Routes;

/**
 * Aus dem GPX geparste Radar-/Verkehrsdaten einer Fahrt
 * ({@see RadarTrafficParser}).
 *
 *  - `$passesPerKm`: Ride-Aggregat aus `<metadata><ge:trafficPassesPerKm>`
 *    (für die Routen-Anzeige ohne Map-Matching). `null` = nicht gesendet.
 *  - `$passes`: einzelne Vorbeifahrten als `[lat, lon]` aus den
 *    `<wpt><ge:vehiclePass>`-Wegpunkten (für das Map-Matching auf Kanten).
 */
final class RadarTrafficData
{
    /** @param list<array{0:float,1:float}> $passes [lat, lon] je Vorbeifahrt */
    public function __construct(
        public readonly ?float $passesPerKm,
        public readonly array $passes,
    ) {}

    /**
     * War bei dieser Fahrt das Radar aktiv? Ja, wenn entweder das
     * Ride-Aggregat vorhanden ist ODER einzelne Vorbeifahrten gesendet
     * wurden. Eine leise Fahrt (Radar an, 0 Pässe) sendet nur das
     * Aggregat — die befahrenen Kanten zählen dann als Beobachtung mit
     * 0 Pässen (= Evidenz für "leise").
     */
    public function hasRadar(): bool
    {
        return $this->passesPerKm !== null || $this->passes !== [];
    }

    public static function empty(): self
    {
        return new self(null, []);
    }
}
