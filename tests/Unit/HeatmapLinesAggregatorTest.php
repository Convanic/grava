<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Heatmap\HeatmapLinesService;
use App\Heatmap\ValhallaMatch;
use App\Heatmap\ValhallaMatchedEdge;
use PHPUnit\Framework\TestCase;

final class HeatmapLinesAggregatorTest extends TestCase
{
    /** Geteilte Kante X (way 100) zwischen zwei Routen. */
    private function edgeX(bool $reversed = false): ValhallaMatchedEdge
    {
        $geom = [[8.60, 49.12], [8.605, 49.125], [8.61, 49.13]];
        if ($reversed) {
            $geom = array_reverse($geom);
        }
        return new ValhallaMatchedEdge(1, 100, 120.0, $geom, 'paved_smooth');
    }

    private function mp(int $edgeIndex): array
    {
        return ['edgeIndex' => $edgeIndex, 'type' => 'matched', 'lat' => 0.0, 'lon' => 0.0];
    }

    public function testSharedEdgeAccumulatesCountAndAveragesScore(): void
    {
        $svc = new HeatmapLinesService(minRoutes: 1);

        // Route A: Kante X (score 2,2) + Kante Y (score 5).
        $edgeY = new ValhallaMatchedEdge(2, 101, 80.0, [[8.61, 49.13], [8.62, 49.14]], 'gravel');
        $matchA = new ValhallaMatch(
            [$this->edgeX(), $edgeY],
            [$this->mp(0), $this->mp(0), $this->mp(1)],
        );
        $pointsA = [
            ['lat' => 49.12, 'lon' => 8.60, 'score' => 2],
            ['lat' => 49.125, 'lon' => 8.605, 'score' => 2],
            ['lat' => 49.14, 'lon' => 8.62, 'score' => 5],
        ];

        // Route B: Kante Z (score 1) + Kante X RÜCKWÄRTS (score 4,4).
        $edgeZ = new ValhallaMatchedEdge(3, 102, 90.0, [[8.59, 49.11], [8.60, 49.12]], null);
        $matchB = new ValhallaMatch(
            [$edgeZ, $this->edgeX(reversed: true)],
            [$this->mp(0), $this->mp(1), $this->mp(1)],
        );
        $pointsB = [
            ['lat' => 49.11, 'lon' => 8.59, 'score' => 1],
            ['lat' => 49.13, 'lon' => 8.61, 'score' => 4],
            ['lat' => 49.125, 'lon' => 8.605, 'score' => 4],
        ];

        $acc = [];
        $svc->accumulate($acc, $pointsA, $matchA);
        $svc->accumulate($acc, $pointsB, $matchB);
        $rows = $svc->finalize($acc);

        $this->assertCount(3, $rows, 'X, Y, Z erwartet');

        $byWay = [];
        foreach ($rows as $r) {
            $byWay[$r['way_id']] = $r;
        }

        // Geteilte Kante X: 2 Routen, Ø-Score (2 + 4) / 2 = 3.
        $this->assertArrayHasKey(100, $byWay);
        $this->assertSame(2, $byWay[100]['route_count']);
        $this->assertEqualsWithDelta(3.0, (float)$byWay[100]['avg_score'], 0.001);

        // Nicht geteilte Kanten: count 1.
        $this->assertSame(1, $byWay[101]['route_count']);
        $this->assertEqualsWithDelta(5.0, (float)$byWay[101]['avg_score'], 0.001);
        $this->assertSame(1, $byWay[102]['route_count']);
        // Kante Z hatte einen Score (1).
        $this->assertEqualsWithDelta(1.0, (float)$byWay[102]['avg_score'], 0.001);
    }

    public function testMinRoutesFilter(): void
    {
        $svc = new HeatmapLinesService(minRoutes: 2);

        $matchA = new ValhallaMatch([$this->edgeX()], [$this->mp(0), $this->mp(0)]);
        $points = [
            ['lat' => 49.12, 'lon' => 8.60, 'score' => 3],
            ['lat' => 49.13, 'lon' => 8.61, 'score' => 3],
        ];

        $acc = [];
        $svc->accumulate($acc, $points, $matchA);
        // Nur eine Route → unter dem Schwellwert 2 → gefiltert.
        $this->assertCount(0, $svc->finalize($acc));

        // Zweite Route über dieselbe Kante → jetzt sichtbar.
        $svc->accumulate($acc, $points, $matchA);
        $rows = $svc->finalize($acc);
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['route_count']);
    }

    public function testEdgeWithoutScoresHasNullAvg(): void
    {
        $svc = new HeatmapLinesService(minRoutes: 1);
        // Punkte ohne Score (z. B. GeoJSON-Route).
        $match = new ValhallaMatch([$this->edgeX()], [$this->mp(0), $this->mp(0)]);
        $points = [
            ['lat' => 49.12, 'lon' => 8.60, 'score' => null],
            ['lat' => 49.13, 'lon' => 8.61, 'score' => null],
        ];
        $acc = [];
        $svc->accumulate($acc, $points, $match);
        $rows = $svc->finalize($acc);

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]['route_count']);
        $this->assertNull($rows[0]['avg_score']);
        $this->assertSame('paved_smooth', $rows[0]['osm_surface']);
    }

    public function testNullMatchIsIgnored(): void
    {
        $svc = new HeatmapLinesService(minRoutes: 1);
        $acc = [];
        $svc->accumulate($acc, [], null);
        $this->assertSame([], $svc->finalize($acc));
    }
}
