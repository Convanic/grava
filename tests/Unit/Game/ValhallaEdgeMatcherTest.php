<?php
declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\MatchUnavailableException;
use App\Game\ValhallaEdgeMatcher;
use App\Heatmap\ValhallaClient;
use App\Heatmap\ValhallaMatch;
use App\Heatmap\ValhallaUnavailableException;
use App\Routes\ParsedPoint;
use App\Routes\ParsedRoute;
use PHPUnit\Framework\TestCase;

final class ValhallaEdgeMatcherTest extends TestCase
{
    private function route(): ParsedRoute
    {
        return new ParsedRoute(
            points: [
                new ParsedPoint(lat: 47.12, lon: 9.65, elevationM: null, timestamp: null),
                new ParsedPoint(lat: 47.13, lon: 9.66, elevationM: null, timestamp: null),
            ],
            sourceFormat: 'geojson',
        );
    }

    /**
     * Ist die Routing-Engine NICHT erreichbar (Transport-Fehler/5xx → der Client
     * wirft ValhallaUnavailableException), MUSS der Matcher die domänentypisierte
     * MatchUnavailableException werfen — damit der HTTP-Adapter den Routing-
     * Ausfall als retrybaren 503 (nicht 500) beantworten kann.
     */
    public function testUnreachableEngineThrowsMatchUnavailable(): void
    {
        $client = new class('http://valhalla.invalid') extends ValhallaClient {
            public function matchTrace(array $points): ?ValhallaMatch
            {
                throw new ValhallaUnavailableException('simulierter Transport-Fehler');
            }
        };

        $this->expectException(MatchUnavailableException::class);
        (new ValhallaEdgeMatcher($client))->match($this->route());
    }

    /**
     * Antwortet die Engine zwar, liefert aber kein verwertbares Match (z. B.
     * HTTP 400/444 „map_snap failed" → der Client gibt null zurück), ist das KEIN
     * Routing-Ausfall: der Matcher liefert eine leere Segmentliste, damit die
     * Ingestion mit 0 Treffern normal durchläuft (kein fälschliches 503).
     */
    public function testNoMatchReturnsEmptySegmentsWithoutThrowing(): void
    {
        $client = new class('http://valhalla.invalid') extends ValhallaClient {
            public function matchTrace(array $points): ?ValhallaMatch
            {
                return null;
            }
        };

        $segments = (new ValhallaEdgeMatcher($client))->match($this->route());
        $this->assertSame([], $segments);
    }
}
