<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MapLod;
use PHPUnit\Framework\TestCase;

final class MapLodTest extends TestCase
{
    /** @param list<array{lon:float,lat:float}> $coords */
    private function pts(array $coords, ?array $scores = null): array
    {
        $out = [];
        foreach ($coords as $i => [$lon, $lat]) {
            $out[] = ['lon' => $lon, 'lat' => $lat, 'score' => $scores[$i] ?? null];
        }
        return $out;
    }

    public function testNoCapAndNoBboxKeepsTrackVerbatim(): void
    {
        $pts = $this->pts([[8.0, 49.0], [8.1, 49.1], [8.2, 49.2]]);
        $res = MapLod::simplifyTrack($pts, null, null);

        $this->assertFalse($res['simplified']);
        $this->assertSame(3, $res['source_points']);
        $this->assertSame(3, $res['returned_points']);
        $this->assertSame($pts, $res['points']);
    }

    public function testBucketAveragingCapsToMaxPoints(): void
    {
        // 18_000 Punkte → ≤ 2000 nach Bucket-Mittelung (Akzeptanzkriterium 2).
        $coords = [];
        for ($i = 0; $i < 18000; $i++) {
            $coords[] = [8.0 + $i * 0.0001, 49.0 + $i * 0.0001];
        }
        $res = MapLod::simplifyTrack($this->pts($coords), null, MapLod::DEFAULT_CAP);

        $this->assertTrue($res['simplified']);
        $this->assertSame(18000, $res['source_points']);
        $this->assertLessThanOrEqual(MapLod::DEFAULT_CAP, $res['returned_points']);
        $this->assertGreaterThan(0, $res['returned_points']);
    }

    public function testBucketAverageScoreIsRoundedMean(): void
    {
        // Vier Punkte, cap=2 → zwei Buckets à zwei Punkten.
        $pts = $this->pts(
            [[0.0, 0.0], [2.0, 2.0], [4.0, 4.0], [6.0, 6.0]],
            [4, 5, 2, 2],
        );
        $res = MapLod::simplifyTrack($pts, null, 2);

        $this->assertCount(2, $res['points']);
        // Bucket 1: lon/lat-Mittel (0,2)→1 ; (0,2)→1 ; Score round((4+5)/2)=5 (4.5→5).
        $this->assertEqualsWithDelta(1.0, $res['points'][0]['lon'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $res['points'][0]['lat'], 1e-9);
        $this->assertSame(5, $res['points'][0]['score']);
        // Bucket 2: (4,6)→5 ; Score round((2+2)/2)=2.
        $this->assertEqualsWithDelta(5.0, $res['points'][1]['lon'], 1e-9);
        $this->assertSame(2, $res['points'][1]['score']);
    }

    public function testBboxClipsToContiguousSpanWithOneOverreach(): void
    {
        // Punkte 0..4; nur Index 2 liegt in der BBox → lo=1, hi=3 (je 1 über Rand).
        $pts = $this->pts([
            [0.0, 0.0],
            [1.0, 1.0],
            [5.0, 5.0],   // in bbox
            [9.0, 9.0],
            [10.0, 10.0],
        ]);
        $bbox = [4.0, 4.0, 6.0, 6.0];
        $res = MapLod::simplifyTrack($pts, $bbox, null);

        $this->assertTrue($res['simplified']);
        $this->assertSame(5, $res['source_points']);
        $this->assertSame(3, $res['returned_points']);
        $this->assertEqualsWithDelta(1.0, $res['points'][0]['lon'], 1e-9);
        $this->assertEqualsWithDelta(9.0, $res['points'][2]['lon'], 1e-9);
    }

    public function testBboxWithNothingInsideReturnsEmpty(): void
    {
        $pts = $this->pts([[0.0, 0.0], [1.0, 1.0]]);
        $res = MapLod::simplifyTrack($pts, [50.0, 50.0, 51.0, 51.0], null);

        $this->assertSame([], $res['points']);
        $this->assertSame(0, $res['returned_points']);
        $this->assertTrue($res['simplified']);
    }

    public function testClusterHeatSumsWeightsPerCell(): void
    {
        $points = [
            ['lon' => 0.01, 'lat' => 0.01, 'weight' => 3],
            ['lon' => 0.02, 'lat' => 0.02, 'weight' => 5],  // gleiche Zelle wie oben bei grid=0.1
            ['lon' => 0.55, 'lat' => 0.55, 'weight' => 2],
        ];
        $res = MapLod::clusterHeat($points, 0.1);

        $this->assertCount(2, $res['cells']);
        $this->assertSame(8, $res['max_weight']);
        // Die erste Zelle (0..0.1) summiert 3+5.
        $weights = array_map(static fn ($c) => $c['weight'], $res['cells']);
        sort($weights);
        $this->assertSame([2, 8], $weights);
    }

    public function testSnap125(): void
    {
        $this->assertEqualsWithDelta(0.01, MapLod::snap125(0.007), 1e-12);
        $this->assertEqualsWithDelta(0.02, MapLod::snap125(0.011), 1e-12);
        $this->assertEqualsWithDelta(0.05, MapLod::snap125(0.03), 1e-12);
        $this->assertEqualsWithDelta(0.1, MapLod::snap125(0.06), 1e-12);
    }

    public function testAdaptiveGridRespectsMinAndSnaps(): void
    {
        $this->assertSame(0.01, MapLod::adaptiveGrid(null, 0.01));
        // span 4° / 40 = 0.1 → snap125(0.1)=0.1.
        $this->assertEqualsWithDelta(0.1, MapLod::adaptiveGrid(4.0, 0.01), 1e-12);
        // span 0.04° / 40 = 0.001 < minGrid 0.01 → 0.01.
        $this->assertEqualsWithDelta(0.01, MapLod::adaptiveGrid(0.04, 0.01), 1e-12);
    }

    public function testParseBbox(): void
    {
        $this->assertSame([8.0, 48.0, 9.0, 49.0], MapLod::parseBbox('8,48,9,49'));
        $this->assertNull(MapLod::parseBbox('8,48,9'));
        $this->assertNull(MapLod::parseBbox('9,48,8,49'));      // min > max lon
        $this->assertNull(MapLod::parseBbox('a,48,9,49'));
        $this->assertNull(MapLod::parseBbox('8,-91,9,49'));     // lat out of range
    }
}
