<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Heatmap\ValhallaClient;
use PHPUnit\Framework\TestCase;

final class ValhallaClientTest extends TestCase
{
    private function fixture(): string
    {
        $path = __DIR__ . '/../fixtures/valhalla_trace_attributes.json';
        $json = file_get_contents($path);
        $this->assertIsString($json, 'Fixture nicht lesbar: ' . $path);
        return $json;
    }

    public function testParseEdges(): void
    {
        $match = ValhallaClient::parse($this->fixture());
        $this->assertNotNull($match);
        $this->assertCount(24, $match->edges);

        $first = $match->edges[0];
        $this->assertSame(28325705, $first->wayId);
        $this->assertSame('paved_smooth', $first->surface);
        // 0.021 km -> 21 m
        $this->assertEqualsWithDelta(21.0, $first->lengthM, 0.5);
        // Teilgeometrie vorhanden und als [lon, lat] im Kraichgau.
        $this->assertNotEmpty($first->geometry);
        [$lon, $lat] = $first->geometry[0];
        $this->assertEqualsWithDelta(8.6, $lon, 0.1);
        $this->assertEqualsWithDelta(49.12, $lat, 0.1);
    }

    public function testParseMatchedPoints(): void
    {
        $match = ValhallaClient::parse($this->fixture());
        $this->assertNotNull($match);
        $this->assertCount(4, $match->matchedPoints);

        $mp = $match->matchedPoints[0];
        $this->assertSame(0, $mp['edgeIndex']);
        $this->assertSame('matched', $mp['type']);
        $this->assertEqualsWithDelta(49.12, $mp['lat'], 0.1);
        // edgeIndex muss in die edges-Liste zeigen.
        $this->assertArrayHasKey($mp['edgeIndex'], $match->edges);
    }

    public function testDecodePolylinePrecision6(): void
    {
        // Punkt im Kraichgau (lat, lon) -> encode -> decode liefert [lon, lat].
        // "wstmsAxqkhE" ist nicht stabil per Hand; daher round-trip über
        // die Fixture-Shape: erster dekodierter Punkt ~ (8.6, 49.12).
        $match = ValhallaClient::parse($this->fixture());
        $this->assertNotNull($match);
        // Eine mittlere Kante hat eine voll aufgelöste Geometrie (>= 2 Punkte).
        $hasMultiPointEdge = false;
        foreach ($match->edges as $edge) {
            if (count($edge->geometry) >= 2) {
                $hasMultiPointEdge = true;
                foreach ($edge->geometry as [$lon, $lat]) {
                    $this->assertEqualsWithDelta(8.6, $lon, 0.2);
                    $this->assertEqualsWithDelta(49.13, $lat, 0.2);
                }
            }
        }
        $this->assertTrue($hasMultiPointEdge, 'Keine Kante mit mehrpunktiger Geometrie gefunden.');
    }

    public function testParseRejectsErrorResponse(): void
    {
        $err = '{"error_code":171,"error":"No suitable edges near location","status_code":400}';
        $this->assertNull(ValhallaClient::parse($err));
    }

    public function testParseRejectsGarbage(): void
    {
        $this->assertNull(ValhallaClient::parse('not json'));
        $this->assertNull(ValhallaClient::parse('{}'));
    }

    public function testParseStatusReadsVersionAndTileset(): void
    {
        $json = '{"version":"3.5.1","tileset_last_modified":1781813950,'
              . '"available_actions":["status","trace_attributes"]}';
        $s = ValhallaClient::parseStatus($json);

        $this->assertTrue($s['reachable']);
        $this->assertSame('3.5.1', $s['version']);
        // 1781813950 -> 2026-06-18T... (UTC, Sekunden-Präzision, mit Z).
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1781813950), $s['tileset_last_modified']);
        $this->assertNull($s['error']);
    }

    public function testParseStatusHandlesMissingFields(): void
    {
        $s = ValhallaClient::parseStatus('{}');
        $this->assertTrue($s['reachable']); // gültige JSON-Antwort => Dienst da
        $this->assertNull($s['version']);
        $this->assertNull($s['tileset_last_modified']);
    }

    public function testParseStatusRejectsGarbage(): void
    {
        $s = ValhallaClient::parseStatus('not json');
        $this->assertFalse($s['reachable']);
        $this->assertNotNull($s['error']);
    }

    public function testStatusAgainstUnreachableHostIsNotReachable(): void
    {
        // Nicht auflösbarer Host → kurzer Connect-Timeout, reachable=false,
        // ohne Exception. base_url wird mitgeliefert.
        $client = new ValhallaClient('http://valhalla.invalid:8002');
        $s = $client->status();

        $this->assertFalse($s['reachable']);
        $this->assertSame('http://valhalla.invalid:8002', $s['base_url']);
        $this->assertNotNull($s['error']);
        $this->assertIsInt($s['latency_ms']);
    }

    public function testBaseUrlGetter(): void
    {
        $this->assertSame('http://localhost:8002', (new ValhallaClient('http://localhost:8002'))->baseUrl());
    }
}
