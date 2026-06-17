<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Routes\GeometryParseException;
use App\Routes\GeometryParser;
use PHPUnit\Framework\TestCase;

final class GeometryParserTest extends TestCase
{
    private GeometryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GeometryParser();
    }

    public function testSniffFormat(): void
    {
        $this->assertSame('gpx', GeometryParser::sniffFormat('  <gpx></gpx>'));
        $this->assertSame('geojson', GeometryParser::sniffFormat('{"type":"LineString"}'));
        $this->assertNull(GeometryParser::sniffFormat('plain text'));
        $this->assertNull(GeometryParser::sniffFormat('   '));
    }

    public function testParseGeoJsonLineString(): void
    {
        $json = '{"type":"LineString","coordinates":[[8.5,49.5,100],[8.51,49.51,110],[8.52,49.52,120]]}';
        $parsed = $this->parser->parse($json);

        $this->assertSame('geojson', $parsed->sourceFormat);
        $this->assertSame(3, $parsed->pointCount());
    }

    public function testParseGeoJsonFeatureCollection(): void
    {
        $json = '{"type":"FeatureCollection","features":[{"type":"Feature","geometry":'
              . '{"type":"LineString","coordinates":[[8.5,49.5],[8.51,49.51]]},"properties":{}}]}';
        $parsed = $this->parser->parse($json);
        $this->assertSame(2, $parsed->pointCount());
    }

    public function testRejectsUnknownFormat(): void
    {
        $this->expectException(GeometryParseException::class);
        $this->parser->parse('just some text');
    }

    public function testRejectsSinglePointLineString(): void
    {
        $this->expectException(GeometryParseException::class);
        $this->parser->parse('{"type":"LineString","coordinates":[[8.5,49.5]]}');
    }

    public function testRejectsOutOfRangeCoordinates(): void
    {
        $this->expectException(GeometryParseException::class);
        $this->parser->parse('{"type":"LineString","coordinates":[[200,49.5],[8.5,49.6]]}');
    }
}
