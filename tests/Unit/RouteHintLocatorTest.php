<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Routes\ParsedPoint;
use App\Routes\RouteHintLocator;
use PHPUnit\Framework\TestCase;

final class RouteHintLocatorTest extends TestCase
{
    /** @return list<ParsedPoint> Gerade Linie entlang eines Breitengrads. */
    private function track(): array
    {
        // 5 Punkte auf 47.0°N, lon 9.0000 → 9.0040 (~0.0001° ≈ 7.6 m hier;
        // genaue Werte sind unkritisch, nur die Reihenfolge/Monotonie zählt).
        $pts = [];
        for ($i = 0; $i < 5; $i++) {
            $pts[] = new ParsedPoint(47.0, 9.0 + $i * 0.001, null, null);
        }
        return $pts;
    }

    public function testEmptyHintsReturnEmpty(): void
    {
        $this->assertSame([], RouteHintLocator::withDistances($this->track(), []));
    }

    public function testAssignsDistanceFromNearestTrackPointAndSorts(): void
    {
        $hints = [
            // näher am letzten Punkt (lon 9.004)
            ['reason_key' => 'great_view', 'sentiment' => 'positive', 'lat' => 47.0, 'lon' => 9.0039],
            // näher am ersten Punkt (lon 9.000)
            ['reason_key' => 'unrideable', 'sentiment' => 'negative', 'lat' => 47.0, 'lon' => 9.0001],
        ];

        $out = RouteHintLocator::withDistances($this->track(), $hints);

        $this->assertCount(2, $out);
        // Beide haben jetzt eine numerische distance_m.
        $this->assertIsInt($out[0]['distance_m']);
        $this->assertIsInt($out[1]['distance_m']);
        // Sortiert nach km aufsteigend → der Start-nahe Hinweis zuerst.
        $this->assertSame('unrideable', $out[0]['reason_key']);
        $this->assertSame('great_view', $out[1]['reason_key']);
        $this->assertLessThan($out[1]['distance_m'], $out[0]['distance_m']);
        // Start-naher Hinweis ist nahe 0 m.
        $this->assertLessThan(20, $out[0]['distance_m']);
    }

    public function testWithoutGeometryDistanceIsNull(): void
    {
        $hints = [['reason_key' => 'gate', 'sentiment' => 'negative', 'lat' => 47.0, 'lon' => 9.0]];
        $out = RouteHintLocator::withDistances([], $hints);
        $this->assertCount(1, $out);
        $this->assertNull($out[0]['distance_m']);
    }
}
