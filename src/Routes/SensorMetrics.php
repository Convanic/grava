<?php
declare(strict_types=1);

namespace App\Routes;

/**
 * Ride-Aggregate aus gekoppelten BLE-Sensoren, geparst aus dem GPX-`<metadata>`
 * ({@see SensorMetricsParser}). Alle Werte optional — `null` = vom Client nicht
 * gesendet (kein Sensor gekoppelt bzw. Aufzeichnung ohne diese Größe).
 *
 *  - Powermeter: `$avgPowerW`, `$maxPowerW`, `$avgCadenceRpm`,
 *    `$avgPedalBalancePct` (Anteil linkes Pedal).
 *  - Herzfrequenz: `$avgHeartRateBpm`, `$maxHeartRateBpm`.
 *
 * Reine Anzeigedaten — fließen (wie im iOS-Client) nicht in Scoring oder Spiel.
 */
final class SensorMetrics
{
    public function __construct(
        public readonly ?int $avgPowerW,
        public readonly ?int $maxPowerW,
        public readonly ?int $avgCadenceRpm,
        public readonly ?float $avgPedalBalancePct,
        public readonly ?int $avgHeartRateBpm,
        public readonly ?int $maxHeartRateBpm,
    ) {
    }

    public function hasAny(): bool
    {
        return $this->avgPowerW !== null
            || $this->maxPowerW !== null
            || $this->avgCadenceRpm !== null
            || $this->avgPedalBalancePct !== null
            || $this->avgHeartRateBpm !== null
            || $this->maxHeartRateBpm !== null;
    }

    public static function empty(): self
    {
        return new self(null, null, null, null, null, null);
    }
}
