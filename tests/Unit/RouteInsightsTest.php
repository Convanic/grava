<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Routes\GeometryParser;
use App\Routes\RouteInsights;
use App\Routes\SurfaceTrack;
use PHPUnit\Framework\TestCase;

final class RouteInsightsTest extends TestCase
{
    private function service(): RouteInsights
    {
        return new RouteInsights(new GeometryParser(), new SurfaceTrack());
    }

    public function testElevationProfileGainAndRange(): void
    {
        $gpx = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
        <trk><trkseg>
        <trkpt lat="49.00" lon="8.0"><ele>100</ele></trkpt>
        <trkpt lat="49.01" lon="8.0"><ele>110</ele></trkpt>
        <trkpt lat="49.02" lon="8.0"><ele>105</ele></trkpt>
        <trkpt lat="49.03" lon="8.0"><ele>130</ele></trkpt>
        </trkseg></trk>
        </gpx>
        XML;

        $insights = $this->service()->compute($gpx);

        $this->assertNotNull($insights);
        $elev = $insights['elevation'];
        $this->assertTrue($elev['hasData']);
        $this->assertSame(100, $elev['minE']);
        $this->assertSame(130, $elev['maxE']);
        // Nur positive Anstiege: (110-100) + (130-105) = 35.
        $this->assertSame(35, $elev['gain']);
        $this->assertGreaterThan(0, $elev['distanceM']);
        $this->assertCount(4, $elev['points']);
        $this->assertSame(0, $elev['points'][0]['d'], 'Erster Punkt startet bei Distanz 0.');
    }

    public function testSurfaceDistributionSumsTo100(): void
    {
        $gpx = file_get_contents(__DIR__ . '/../fixtures/ride_app_export.gpx');
        $this->assertNotFalse($gpx);

        $insights = $this->service()->compute($gpx);

        $this->assertNotNull($insights);
        $surf = $insights['surface'];
        $this->assertTrue($surf['hasData']);
        $this->assertGreaterThan(0, $surf['totalM']);

        // Fixture-Scores 4,4,2,2,5 → SurfaceTrack bildet Läufe für 4 und 2;
        // der einzelne 5er-Endpunkt fällt raus (Segment braucht ≥2 Punkte).
        $scores = array_map(static fn(array $b): ?int => $b['score'], $surf['buckets']);
        $this->assertContains(2, $scores);
        $this->assertContains(4, $scores);

        $sum = array_sum(array_map(static fn(array $b): float => $b['percent'], $surf['buckets']));
        $this->assertEqualsWithDelta(100.0, $sum, 0.5, 'Prozentanteile summieren sich auf ~100 %.');
    }

    public function testDownsampleRespectsMaxPoints(): void
    {
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $lat = 49.0 + $i * 0.0001;
            $ele = 100 + ($i % 50);
            $rows[] = "<trkpt lat=\"{$lat}\" lon=\"8.0\"><ele>{$ele}</ele></trkpt>";
        }
        $gpx = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">'
            . '<trk><trkseg>' . implode('', $rows) . '</trkseg></trk></gpx>';

        $insights = $this->service()->compute($gpx, 50);

        $this->assertNotNull($insights);
        $this->assertLessThanOrEqual(50, count($insights['elevation']['points']));
        $this->assertSame(0, $insights['elevation']['points'][0]['d']);
    }

    public function testGarbagePayloadReturnsNull(): void
    {
        $this->assertNull($this->service()->compute('not a route at all'));
    }

    public function testPlainGpxWithoutElevationOrScoresReturnsNull(): void
    {
        $gpx = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
        <trk><trkseg>
        <trkpt lat="49.5" lon="8.5"></trkpt>
        <trkpt lat="49.51" lon="8.51"></trkpt>
        </trkseg></trk>
        </gpx>
        XML;

        // Weder Höhe noch Surface-Scores → kein Panel.
        $this->assertNull($this->service()->compute($gpx));
    }
}
