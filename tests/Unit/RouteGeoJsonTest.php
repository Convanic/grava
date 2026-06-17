<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Routes\GeometryParser;
use App\Routes\RouteGeoJson;
use App\Routes\SurfaceTrack;
use PHPUnit\Framework\TestCase;

final class RouteGeoJsonTest extends TestCase
{
    private RouteGeoJson $converter;

    protected function setUp(): void
    {
        // Ohne SurfaceTrack: rohe Linie testen, deterministisch.
        $this->converter = new RouteGeoJson(new GeometryParser());
    }

    public function testGeoJsonLineStringRoundTripsToFeatureCollection(): void
    {
        $json = '{"type":"LineString","coordinates":[[8.5,49.5],[8.51,49.51],[8.52,49.52]]}';

        $fc = $this->converter->toFeatureCollection($json);

        $this->assertSame('FeatureCollection', $fc['type']);
        $this->assertCount(1, $fc['features']);
        $feature = $fc['features'][0];
        $this->assertSame('Feature', $feature['type']);
        $this->assertSame('LineString', $feature['geometry']['type']);
        $this->assertSame(
            [[8.5, 49.5], [8.51, 49.51], [8.52, 49.52]],
            $feature['geometry']['coordinates'],
            'Koordinaten bleiben in RFC-7946-Reihenfolge [lon, lat].',
        );
    }

    public function testGpxIsConvertedToLonLatOrder(): void
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

        $fc = $this->converter->toFeatureCollection($gpx);

        $coords = $fc['features'][0]['geometry']['coordinates'];
        $this->assertCount(2, $coords);
        // Erstes Element ist die Länge (lon), zweites die Breite (lat).
        $this->assertEqualsWithDelta(8.5, $coords[0][0], 1e-9);
        $this->assertEqualsWithDelta(49.5, $coords[0][1], 1e-9);
    }

    public function testPropertiesArePassedThrough(): void
    {
        $json = '{"type":"LineString","coordinates":[[1,2],[3,4]]}';

        $fc = $this->converter->toFeatureCollection($json, ['title' => 'Meine Tour']);

        $props = (array) $fc['features'][0]['properties'];
        $this->assertSame('Meine Tour', $props['title']);
    }

    public function testSurfaceScoreGpxYieldsColoredSegments(): void
    {
        $converter = new RouteGeoJson(new GeometryParser(), new SurfaceTrack());
        $gpx = file_get_contents(__DIR__ . '/../fixtures/ride_app_export.gpx');
        $this->assertNotFalse($gpx, 'Fixture konnte nicht gelesen werden.');

        $fc = $converter->toFeatureCollection($gpx);

        $this->assertSame('FeatureCollection', $fc['type']);
        // Scores im Fixture: 4,4,2,2,5 → drei Läufe (4er, 2er, 5er-Übergang).
        $this->assertGreaterThanOrEqual(2, count($fc['features']));

        $scores = [];
        foreach ($fc['features'] as $feature) {
            $this->assertSame('LineString', $feature['geometry']['type']);
            $this->assertGreaterThanOrEqual(2, count($feature['geometry']['coordinates']));
            $props = (array) $feature['properties'];
            if (isset($props['score'])) {
                $scores[] = $props['score'];
            }
        }
        $this->assertContains(4, $scores);
        $this->assertContains(2, $scores);
    }

    public function testGpxWithoutScoresFallsBackToSingleLine(): void
    {
        $converter = new RouteGeoJson(new GeometryParser(), new SurfaceTrack());
        $gpx = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <gpx version="1.1" creator="test" xmlns="http://www.topografix.com/GPX/1/1">
        <trk><trkseg>
        <trkpt lat="49.5" lon="8.5"></trkpt>
        <trkpt lat="49.51" lon="8.51"></trkpt>
        </trkseg></trk>
        </gpx>
        XML;

        $fc = $converter->toFeatureCollection($gpx);

        $this->assertCount(1, $fc['features'], 'Ohne Scores nur eine einfache Linie.');
    }
}
