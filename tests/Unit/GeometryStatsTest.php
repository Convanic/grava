<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Routes\GeometryStats;
use App\Routes\ParsedPoint;
use App\Routes\ParsedRoute;
use PHPUnit\Framework\TestCase;

final class GeometryStatsTest extends TestCase
{
    /**
     * @param list<?float> $elevations
     */
    private function route(array $elevations, ?float $override = null): ParsedRoute
    {
        $points = [];
        $lon = 12.0;
        foreach ($elevations as $ele) {
            $points[] = new ParsedPoint(lat: 48.0, lon: $lon, elevationM: $ele, timestamp: null);
            $lon += 0.001; // sorgt für Distanz > 0
        }
        return new ParsedRoute(
            points: $points,
            sourceFormat: 'gpx',
            elevationGainOverrideM: $override,
        );
    }

    public function testClearAscentSumsGain(): void
    {
        $stats = (new GeometryStats(3.0))->compute($this->route([100.0, 104.0, 108.0, 112.0]));
        $this->assertSame(12, $stats->elevationGainM);
    }

    public function testFlatNoiseBelowThresholdYieldsZero(): void
    {
        // Schwankungen < 3 m gelten als Rauschen.
        $stats = (new GeometryStats(3.0))->compute($this->route([100.0, 101.0, 99.0, 100.5, 101.5, 100.0]));
        $this->assertSame(0, $stats->elevationGainM);
    }

    public function testThresholdIsConfigurable(): void
    {
        $elevations = [100.0, 102.0, 100.0, 102.0, 100.0];
        $this->assertSame(0, (new GeometryStats(3.0))->compute($this->route($elevations))->elevationGainM);
        $this->assertSame(4, (new GeometryStats(1.5))->compute($this->route($elevations))->elevationGainM);
    }

    public function testDefaultThresholdIsThreeMeters(): void
    {
        $this->assertSame(
            (new GeometryStats(3.0))->compute($this->route([100.0, 102.0, 104.0]))->elevationGainM,
            (new GeometryStats())->compute($this->route([100.0, 102.0, 104.0]))->elevationGainM,
        );
        // 0/negativ fällt auf Default zurück (kein "jede Schwankung zählt").
        $this->assertSame(
            (new GeometryStats())->compute($this->route([100.0, 101.0, 100.0]))->elevationGainM,
            (new GeometryStats(0.0))->compute($this->route([100.0, 101.0, 100.0]))->elevationGainM,
        );
    }

    public function testMissingElevationsYieldZeroWithoutError(): void
    {
        $stats = (new GeometryStats())->compute($this->route([null, null, null]));
        $this->assertSame(0, $stats->elevationGainM);
    }

    public function testExtensionOverrideTakesPrecedence(): void
    {
        // Trotz „klarem Anstieg" aus <ele> gewinnt der exakte Override-Wert.
        $stats = (new GeometryStats(3.0))->compute($this->route([100.0, 200.0, 300.0], override: 437.0));
        $this->assertSame(437, $stats->elevationGainM);
    }
}
