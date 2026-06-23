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

    public function testGeoJsonWithoutStartedAtHasNullTimeBounds(): void
    {
        $json = '{"type":"LineString","coordinates":[[8.5,49.5],[8.51,49.51]]}';
        $parsed = $this->parser->parse($json);
        $this->assertNull($parsed->startedAt);
        $this->assertNull($parsed->endedAt);
    }

    public function testGeoJsonFeatureStartedAtBecomesDeterministicAnchor(): void
    {
        // Strava-Import-Form: Feature mit eingebettetem startedAt, aber ohne
        // Per-Punkt-Zeitstempel. startedAt ist der stabile Dedup-Anker.
        $json = '{"type":"Feature","properties":{"startedAt":"2026-05-01T07:30:00Z"},'
              . '"geometry":{"type":"LineString","coordinates":[[8.5,49.5],[8.51,49.51]]}}';
        $parsed = $this->parser->parse($json);

        $this->assertNotNull($parsed->startedAt);
        $this->assertSame('2026-05-01T07:30:00+00:00', $parsed->startedAt->format('c'));
        // Per-Punkt-Zeitstempel bleiben null (kein avg-Speed → keine Abweisung).
        $this->assertNull($parsed->points[0]->timestamp);
    }

    public function testGeoJsonStartedAtIsNormalizedToUtc(): void
    {
        $json = '{"type":"Feature","properties":{"startedAt":"2026-05-01T09:30:00+02:00"},'
              . '"geometry":{"type":"LineString","coordinates":[[8.5,49.5],[8.51,49.51]]}}';
        $parsed = $this->parser->parse($json);
        $this->assertSame('2026-05-01T07:30:00+00:00', $parsed->startedAt?->format('c'));
    }

    public function testGeoJsonInvalidStartedAtIsIgnored(): void
    {
        $json = '{"type":"Feature","properties":{"startedAt":"not-a-date"},'
              . '"geometry":{"type":"LineString","coordinates":[[8.5,49.5],[8.51,49.51]]}}';
        $parsed = $this->parser->parse($json);
        $this->assertNull($parsed->startedAt);
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

    /**
     * M5: Das exakte GPX-Format der iOS-App (GPXExport.swift) trägt pro
     * Trackpoint einen `<ge:surfaceScore>` in einem `<extensions>`-Block.
     * Der Parser muss die Geometrie sauber lesen und die unbekannte
     * Extension ignorieren (nicht ablehnen).
     */
    public function testParsesAppGpxWithSurfaceScoreExtensions(): void
    {
        $gpx = file_get_contents(__DIR__ . '/../fixtures/ride_app_export.gpx');
        $this->assertNotFalse($gpx, 'Fixture konnte nicht gelesen werden.');

        $parsed = $this->parser->parse($gpx);

        $this->assertSame('gpx', $parsed->sourceFormat);
        $this->assertSame(5, $parsed->pointCount(), 'Alle Trackpoints trotz Extensions erkannt.');
        $this->assertNotNull($parsed->startedAt, 'startedAt aus <time> abgeleitet.');
        $this->assertNotNull($parsed->endedAt, 'endedAt aus <time> abgeleitet.');
        $this->assertSame('2026-05-01T07:30:00+00:00', $parsed->startedAt->format('c'));
        $this->assertSame('2026-05-01T07:30:20+00:00', $parsed->endedAt->format('c'));
    }

    public function testReadsElevationGainExtension(): void
    {
        $gpx = '<?xml version="1.0" encoding="UTF-8"?>'
             . '<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1" '
             . 'xmlns:ge="https://gravelexplorer.benx.de/gpx/v1">'
             . '<metadata><extensions><ge:elevationGain>437</ge:elevationGain></extensions></metadata>'
             . '<trk><trkseg>'
             . '<trkpt lat="48.0" lon="12.0"><ele>100</ele></trkpt>'
             . '<trkpt lat="48.001" lon="12.001"><ele>110</ele></trkpt>'
             . '</trkseg></trk></gpx>';

        $parsed = $this->parser->parse($gpx);

        $this->assertSame(437.0, $parsed->elevationGainOverrideM);
    }

    public function testNoElevationGainExtensionLeavesOverrideNull(): void
    {
        $gpx = '<?xml version="1.0" encoding="UTF-8"?>'
             . '<gpx version="1.1" xmlns="http://www.topografix.com/GPX/1/1">'
             . '<trk><trkseg>'
             . '<trkpt lat="48.0" lon="12.0"><ele>100</ele></trkpt>'
             . '<trkpt lat="48.001" lon="12.001"><ele>110</ele></trkpt>'
             . '</trkseg></trk></gpx>';

        $parsed = $this->parser->parse($gpx);

        $this->assertNull($parsed->elevationGainOverrideM);
    }
}
