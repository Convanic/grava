<?php
declare(strict_types=1);

namespace Tests\Integration\Heatmap;

use App\Config\Config;
use App\Heatmap\PersonalHeatmapService;
use App\Privacy\PrivacyZoneRepository;
use App\Routes\GeometryParser;
use App\Routes\GeometryStats;
use App\Routes\RouteRepository;
use App\Routes\RouteService;
use App\Routes\RouteStorage;
use Tests\IntegrationTestCase;

final class PersonalHeatmapTest extends IntegrationTestCase
{
    private RouteService $routes;
    private PersonalHeatmapService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $config = Config::instance();
        $this->routes = new RouteService(
            new RouteRepository(),
            new RouteStorage($config),
            new GeometryParser(),
            new GeometryStats(),
        );
        $this->svc = new PersonalHeatmapService(
            new RouteStorage($config),
            new GeometryParser(),
            new PrivacyZoneRepository($this->pdo),
        );
    }

    /** @param list<array{0:float,1:float}> $lonLat */
    private function geojson(array $lonLat): string
    {
        return json_encode([
            'type' => 'LineString',
            'coordinates' => $lonLat,
        ], JSON_THROW_ON_ERROR);
    }

    private function addRoute(int $userId, array $lonLat, string $source = 'app'): void
    {
        $this->routes->createOrAddVersion(
            userId: $userId,
            title: 'R ' . bin2hex(random_bytes(3)),
            description: null,
            visibility: $source === 'strava' ? 'private' : 'public',
            source: $source,
            clientRouteUuid: null,
            payload: $this->geojson($lonLat),
            tags: [],
        );
    }

    public function testAggregatesOwnRoutesIncludingStravaAndSharedCellWeight(): void
    {
        $u1 = $this->createUser('armin');
        // Beide Routen teilen den Startpunkt (9.650,47.120) → gemeinsame Zelle.
        $this->addRoute($u1, [[9.650, 47.120], [9.660, 47.130]], 'app');
        $this->addRoute($u1, [[9.650, 47.120], [9.640, 47.110]], 'strava');

        $fc = $this->svc->queryForUser($u1, null);

        $this->assertSame('FeatureCollection', $fc['type']);
        $this->assertSame(PersonalHeatmapService::GRID, $fc['meta']['grid']);
        $this->assertGreaterThanOrEqual(3, $fc['meta']['cell_count']);
        // Geteilte Startzelle → Gewicht 2 (zwei eigene Routen, inkl. Strava).
        $this->assertSame(2, $fc['meta']['max_weight']);

        $grid = PersonalHeatmapService::GRID;
        $sharedLon = round((int)round(9.650 / $grid) * $grid, 6);
        $sharedLat = round((int)round(47.120 / $grid) * $grid, 6);
        $shared = $this->cellAt($fc, $sharedLon, $sharedLat);
        $this->assertNotNull($shared, 'geteilte Startzelle muss vorhanden sein');
        $this->assertSame(2, $shared['properties']['weight']);
    }

    public function testExcludesOtherUsersRoutes(): void
    {
        $u1 = $this->createUser('armin');
        $u2 = $this->createUser('berta');
        $this->addRoute($u1, [[9.650, 47.120], [9.660, 47.130]]);
        $this->addRoute($u2, [[2.000, 50.000], [2.010, 50.010]]); // weit weg

        $fc = $this->svc->queryForUser($u1, null);
        foreach ($fc['features'] as $f) {
            [$lon, $lat] = $f['geometry']['coordinates'];
            // u1 fährt bei ~47.12; u2 (ausgeschlossen) läge bei ~50.
            $this->assertLessThan(49.0, $lat, 'keine Zellen aus fremden Routen');
        }
        $this->assertGreaterThan(0, $fc['meta']['cell_count']);
    }

    public function testRespectsPrivacyZone(): void
    {
        $u1 = $this->createUser('armin');
        $this->addRoute($u1, [[9.650, 47.120], [9.660, 47.130]]);

        $grid = PersonalHeatmapService::GRID;
        $startLon = round((int)round(9.650 / $grid) * $grid, 6);
        $startLat = round((int)round(47.120 / $grid) * $grid, 6);

        // Ohne Zone: Startzelle vorhanden.
        $this->assertNotNull($this->cellAt($this->svc->queryForUser($u1, null), $startLon, $startLat));

        // Zone um den Startpunkt → Startzelle fällt raus.
        (new PrivacyZoneRepository($this->pdo))->upsert($u1, 47.120, 9.650, 500, true);
        $fc = $this->svc->queryForUser($u1, null);
        $this->assertNull($this->cellAt($fc, $startLon, $startLat), 'Punkte in der Privatzone müssen entfallen');
    }

    public function testBboxFilter(): void
    {
        $u1 = $this->createUser('armin');
        $this->addRoute($u1, [[9.650, 47.120], [9.700, 47.170]]);

        $all = $this->svc->queryForUser($u1, null)['meta']['cell_count'];
        // Enge BBox nur um den Startbereich.
        $bbox = ['min_lat' => 47.118, 'min_lon' => 9.648, 'max_lat' => 47.125, 'max_lon' => 9.655];
        $clipped = $this->svc->queryForUser($u1, $bbox)['meta']['cell_count'];

        $this->assertGreaterThan($clipped, $all, 'BBox muss die Zellzahl reduzieren');
        $this->assertGreaterThan(0, $clipped);
    }

    /** @return array<string,mixed>|null */
    private function cellAt(array $fc, float $lon, float $lat): ?array
    {
        foreach ($fc['features'] as $f) {
            [$flon, $flat] = $f['geometry']['coordinates'];
            if (abs($flon - $lon) < 1e-9 && abs($flat - $lat) < 1e-9) {
                return $f;
            }
        }
        return null;
    }
}
