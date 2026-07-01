<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Routes\SensorMetricsParser;
use PHPUnit\Framework\TestCase;

final class SensorMetricsParserTest extends TestCase
{
    private function gpx(string $metaExt): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <gpx version="1.1" creator="test"
             xmlns="http://www.topografix.com/GPX/1/1"
             xmlns:ge="https://gravelexplorer.benx.de/gpx/v1">
          <metadata><extensions>{$metaExt}</extensions></metadata>
          <trk><trkseg>
            <trkpt lat="47.12" lon="9.65"><time>2026-06-20T08:00:00Z</time></trkpt>
            <trkpt lat="47.13" lon="9.66"><time>2026-06-20T08:05:00Z</time></trkpt>
          </trkseg></trk>
        </gpx>
        XML;
    }

    public function testParsesAllAggregates(): void
    {
        $meta = '<ge:avgPower>210</ge:avgPower>'
              . '<ge:maxPower>540</ge:maxPower>'
              . '<ge:avgCadence>88</ge:avgCadence>'
              . '<ge:avgPedalBalance>52.0</ge:avgPedalBalance>'
              . '<ge:avgHeartRate>142</ge:avgHeartRate>'
              . '<ge:maxHeartRate>176</ge:maxHeartRate>';
        $m = SensorMetricsParser::parse($this->gpx($meta));

        $this->assertSame(210, $m->avgPowerW);
        $this->assertSame(540, $m->maxPowerW);
        $this->assertSame(88, $m->avgCadenceRpm);
        $this->assertSame(52.0, $m->avgPedalBalancePct);
        $this->assertSame(142, $m->avgHeartRateBpm);
        $this->assertSame(176, $m->maxHeartRateBpm);
        $this->assertTrue($m->hasAny());
    }

    public function testPartialAggregates(): void
    {
        // Nur Puls (HR-Gurt ohne Powermeter).
        $m = SensorMetricsParser::parse($this->gpx('<ge:avgHeartRate>138</ge:avgHeartRate>'));
        $this->assertSame(138, $m->avgHeartRateBpm);
        $this->assertNull($m->avgPowerW);
        $this->assertNull($m->avgPedalBalancePct);
        $this->assertTrue($m->hasAny());
    }

    public function testNoAggregatesIsEmpty(): void
    {
        $m = SensorMetricsParser::parse($this->gpx(''));
        $this->assertFalse($m->hasAny());
        $this->assertNull($m->avgPowerW);
    }

    public function testRejectsOutOfRangeBalanceAndNegativePower(): void
    {
        $meta = '<ge:avgPower>-5</ge:avgPower><ge:avgPedalBalance>150</ge:avgPedalBalance>';
        $m = SensorMetricsParser::parse($this->gpx($meta));
        $this->assertNull($m->avgPowerW);          // negativ verworfen
        $this->assertNull($m->avgPedalBalancePct); // > 100 verworfen
    }

    public function testGeoJsonHasNoSensorMetrics(): void
    {
        $m = SensorMetricsParser::parse('{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13]]}');
        $this->assertFalse($m->hasAny());
    }

    public function testBrokenXmlIsNeutral(): void
    {
        $m = SensorMetricsParser::parse('<gpx><metadata><not closed');
        $this->assertFalse($m->hasAny());
    }
}
