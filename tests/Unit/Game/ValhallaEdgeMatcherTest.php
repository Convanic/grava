<?php
declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\MatchUnavailableException;
use App\Game\ValhallaEdgeMatcher;
use App\Heatmap\ValhallaClient;
use App\Routes\ParsedPoint;
use App\Routes\ParsedRoute;
use PHPUnit\Framework\TestCase;

final class ValhallaEdgeMatcherTest extends TestCase
{
    /**
     * Liefert die Routing-Engine kein Ergebnis (nicht erreichbar / kein Match),
     * MUSS der Matcher eine typisierte MatchUnavailableException werfen — damit
     * der HTTP-Adapter den Routing-Ausfall als 503 (nicht 500) beantworten kann.
     *
     * ValhallaClient::matchTrace() liefert bei < 2 Punkten null zurück, ohne
     * das Netzwerk zu berühren — so ist der null-Pfad ohne erreichbaren Server
     * deterministisch testbar.
     */
    public function testNullMatchThrowsMatchUnavailable(): void
    {
        $client = new ValhallaClient('http://valhalla.invalid');
        $route = new ParsedRoute(
            points: [new ParsedPoint(lat: 47.12, lon: 9.65, elevationM: null, timestamp: null)],
            sourceFormat: 'geojson',
        );

        $this->expectException(MatchUnavailableException::class);
        (new ValhallaEdgeMatcher($client))->match($route);
    }
}
