<?php
declare(strict_types=1);

namespace Tests\Integration\Discovery;

use App\Discovery\DiscoveryService;
use App\Routes\RouteRepository;
use Tests\IntegrationTestCase;

/**
 * Regression für SQLSTATE[HY093] aus dem Discovery-/Community-Pfad.
 *
 * Der echte Stacktrace zeigte RouteRepository::searchPublic — die
 * Owner-Auflösungs-Query baut die ID-Liste aus `array_unique(...)`.
 * `array_unique` behält die Original-Array-Keys; sobald zwei Routen
 * desselben Owners im Result liegen, wird ein Key entfernt und die
 * Liste ist nicht mehr 0-basiert lückenlos. PDO::execute() mit
 * positionalen `?` quittiert das mit „Invalid parameter number".
 */
final class DiscoveryServiceTest extends IntegrationTestCase
{
    private DiscoveryService $discovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discovery = new DiscoveryService(new RouteRepository());
    }

    public function testSearchWithMultipleRoutesFromSameOwnerResolvesOwners(): void
    {
        $owner = $this->createUser('mapper');
        $this->createRoute($owner, 'public');
        $this->createRoute($owner, 'public');

        // Ein zweiter Owner sorgt dafür, dass die unique-Liste echte
        // Lücken bekommt (Keys 0,1,2,3 → nach unique z. B. 0,2).
        $other = $this->createUser('rider');
        $this->createRoute($other, 'public');
        $this->createRoute($other, 'public');

        $res = $this->discovery->searchRoutes(['limit' => 20, 'offset' => 0], null);

        $this->assertSame(4, $res['pagination']['total']);
        $this->assertCount(4, $res['routes']);
        foreach ($res['routes'] as $route) {
            $this->assertContains($route['owner']['handle'], ['mapper', 'rider']);
        }
    }

    public function testSearchWithNoResultsIsSafe(): void
    {
        $viewer = $this->createUser('seeker');
        $res = $this->discovery->searchRoutes(['limit' => 20, 'offset' => 0], $viewer);
        $this->assertSame([], $res['routes']);
        $this->assertSame(0, $res['pagination']['total']);
    }
}
