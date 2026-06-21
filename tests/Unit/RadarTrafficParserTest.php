<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Routes\RadarTrafficParser;
use PHPUnit\Framework\TestCase;

final class RadarTrafficParserTest extends TestCase
{
    private function gpx(string $metaExt, string $wpts): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <gpx version="1.1" creator="test"
             xmlns="http://www.topografix.com/GPX/1/1"
             xmlns:ge="https://gravelexplorer.benx.de/gpx/v1">
          <metadata><extensions>{$metaExt}</extensions></metadata>
          {$wpts}
          <trk><trkseg>
            <trkpt lat="47.12" lon="9.65"><time>2026-06-20T08:00:00Z</time></trkpt>
            <trkpt lat="47.13" lon="9.66"><time>2026-06-20T08:05:00Z</time></trkpt>
          </trkseg></trk>
        </gpx>
        XML;
    }

    public function testParsesPassesPerKmAndVehiclePasses(): void
    {
        $wpts = '<wpt lat="48.20" lon="12.41"><extensions><ge:vehiclePass>1</ge:vehiclePass></extensions></wpt>'
              . '<wpt lat="48.21" lon="12.42"><extensions><ge:vehiclePass>1</ge:vehiclePass></extensions></wpt>';
        $radar = RadarTrafficParser::parse($this->gpx('<ge:trafficPassesPerKm>3.4</ge:trafficPassesPerKm>', $wpts));

        $this->assertSame(3.4, $radar->passesPerKm);
        $this->assertCount(2, $radar->passes);
        $this->assertSame([48.20, 12.41], $radar->passes[0]);
        $this->assertTrue($radar->hasRadar());
    }

    public function testIgnoresForeignWaypointsWithoutVehiclePass(): void
    {
        $wpts = '<wpt lat="48.20" lon="12.41"><name>POI</name></wpt>'
              . '<wpt lat="48.21" lon="12.42"><extensions><ge:hintReason>unrideable</ge:hintReason></extensions></wpt>';
        $radar = RadarTrafficParser::parse($this->gpx('', $wpts));

        $this->assertNull($radar->passesPerKm);
        $this->assertSame([], $radar->passes);
        $this->assertFalse($radar->hasRadar());
    }

    public function testQuietRideAggregateOnlyStillCountsAsRadar(): void
    {
        // Radar an, 0 Pässe → nur das Aggregat, keine Wegpunkte.
        $radar = RadarTrafficParser::parse($this->gpx('<ge:trafficPassesPerKm>0</ge:trafficPassesPerKm>', ''));
        $this->assertSame(0.0, $radar->passesPerKm);
        $this->assertSame([], $radar->passes);
        $this->assertTrue($radar->hasRadar());
    }

    public function testGeoJsonHasNoRadarData(): void
    {
        $radar = RadarTrafficParser::parse('{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13]]}');
        $this->assertNull($radar->passesPerKm);
        $this->assertSame([], $radar->passes);
    }

    public function testBrokenXmlIsNeutral(): void
    {
        $radar = RadarTrafficParser::parse('<gpx><metadata><not closed');
        $this->assertFalse($radar->hasRadar());
    }
}
