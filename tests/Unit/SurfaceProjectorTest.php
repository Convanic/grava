<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Heatmap\SurfaceProjector;
use PHPUnit\Framework\TestCase;

final class SurfaceProjectorTest extends TestCase
{
    /** Route entlang konstanter Breite, ~36 m Punktabstand (bleibt beim Resample erhalten). */
    private function route(): array
    {
        return [
            ['lat' => 49.0, 'lon' => 8.0000],
            ['lat' => 49.0, 'lon' => 8.0005],
            ['lat' => 49.0, 'lon' => 8.0010],
            ['lat' => 49.0, 'lon' => 8.0015],
            ['lat' => 49.0, 'lon' => 8.0020],
        ];
    }

    /**
     * @param list<array{0:float,1:float}> $geom
     * @return array{geom:list<array{0:float,1:float}>,avg_score:?float,surface:?string}
     */
    private function edge(array $geom, ?float $avg, ?string $surface): array
    {
        return ['geom' => $geom, 'avg_score' => $avg, 'surface' => $surface];
    }

    public function testFullCoverageAssignsScoreAndSurface(): void
    {
        $proj = new SurfaceProjector(thresholdM: 25.0, resampleM: 20);
        // Kante deckt die ganze Route ab (deckungsgleich).
        $edges = [$this->edge([[8.0000, 49.0], [8.0020, 49.0]], 4.0, 'gravel')];

        $res = $proj->project($this->route(), $edges);
        $sum = $res['summary'];

        $this->assertEqualsWithDelta(100.0, $sum['coverage_pct'], 0.01);
        $this->assertEqualsWithDelta(4.0, (float)$sum['avg_score'], 0.001);
        $this->assertEqualsWithDelta(100.0, $sum['by_bucket']['gravel'], 0.01);
        $this->assertEqualsWithDelta(0.0, $sum['by_bucket']['paved'], 0.01);

        // Alle Features tragen Crowd-Daten.
        foreach ($res['geojson']['features'] as $f) {
            $this->assertSame('crowd', $f['properties']['source']);
            $this->assertSame(4, $f['properties']['score']);
            $this->assertSame('gravel', $f['properties']['surface']);
        }
    }

    public function testEdgeBeyondThresholdIsNotMatched(): void
    {
        $proj = new SurfaceProjector(thresholdM: 25.0, resampleM: 20);
        // Kante ~1,1 km nördlich → außerhalb des Schwellwerts.
        $edges = [$this->edge([[8.0000, 49.01], [8.0020, 49.01]], 4.0, 'gravel')];

        $res = $proj->project($this->route(), $edges);

        $this->assertEqualsWithDelta(0.0, $res['summary']['coverage_pct'], 0.01);
        $this->assertNull($res['summary']['avg_score']);
        foreach ($res['geojson']['features'] as $f) {
            $this->assertSame('none', $f['properties']['source']);
        }
    }

    public function testPartialCoverage(): void
    {
        $proj = new SurfaceProjector(thresholdM: 25.0, resampleM: 20);
        // Kante deckt nur die erste Routenhälfte (bis lon 8.0010) ab.
        $edges = [$this->edge([[8.0000, 49.0], [8.0010, 49.0]], 1.0, 'paved_smooth')];

        $res = $proj->project($this->route(), $edges);
        $sum = $res['summary'];

        $this->assertGreaterThan(0.0, $sum['coverage_pct']);
        $this->assertLessThan(100.0, $sum['coverage_pct']);
        // ~75 % (3 von 4 Segmenten).
        $this->assertEqualsWithDelta(75.0, $sum['coverage_pct'], 5.0);
        $this->assertEqualsWithDelta(1.0, (float)$sum['avg_score'], 0.001);
        // Buckets sind Anteil der Gesamtlänge → paved == coverage (alles paved).
        $this->assertEqualsWithDelta($sum['coverage_pct'], $sum['by_bucket']['paved'], 0.01);
    }

    public function testNoEdgesYieldsZeroCoverage(): void
    {
        $proj = new SurfaceProjector();
        $res = $proj->project($this->route(), []);

        $this->assertSame(0.0, $res['summary']['coverage_pct']);
        $this->assertNull($res['summary']['avg_score']);
        $this->assertGreaterThan(0, $res['summary']['total_length_m']);
    }

    public function testResampleThinsDensePoints(): void
    {
        $proj = new SurfaceProjector(resampleM: 20);
        $dense = [];
        for ($i = 0; $i <= 100; $i++) {
            // ~1,1 m Schritte → fast alle werden ausgedünnt.
            $dense[] = ['lat' => 49.0, 'lon' => 8.0 + $i * 0.00001];
        }
        $out = $proj->resample($dense);
        $this->assertLessThan(count($dense), count($out));
        // Erster + letzter Punkt bleiben erhalten.
        $this->assertSame(8.0, $out[0]['lon']);
        $this->assertEqualsWithDelta(8.001, $out[count($out) - 1]['lon'], 1e-9);
    }
}
