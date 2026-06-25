<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Config;
use App\Game\GameRepository;
use App\Integrations\Strava\FakeStravaClient;
use App\Integrations\Strava\StravaException;
use App\Integrations\Strava\StravaService;
use App\Privacy\PrivacyZoneRepository;
use App\Privacy\RoutePrivacyTrimmer;
use App\Routes\GeometryParser;
use App\Routes\GeometryStats;
use App\Routes\RouteGeoJson;
use App\Routes\RouteGpxExportService;
use App\Routes\RouteRepository;
use App\Routes\RouteService;
use App\Routes\RouteStorage;
use App\Support\Crypto;
use Tests\IntegrationTestCase;

final class StravaShareTest extends IntegrationTestCase
{
    private FakeStravaClient $client;
    private StravaService $strava;
    private RouteService $routes;
    private RouteRepository $routeRepo;
    private Crypto $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        $config = Config::instance();
        $this->routeRepo = new RouteRepository();
        $this->routes = new RouteService(
            new RouteRepository(),
            new RouteStorage($config),
            new GeometryParser(),
            new GeometryStats(),
        );
        $this->crypto = new Crypto(base64_encode(str_repeat("\x07", 32)));
        $this->client = new FakeStravaClient();
        $this->strava = $this->makeService($this->client);
    }

    private function makeService(FakeStravaClient $client): StravaService
    {
        $gpxExport = new RouteGpxExportService(
            $this->routes,
            new RouteGeoJson(new GeometryParser()),
            new RoutePrivacyTrimmer(),
            new PrivacyZoneRepository($this->pdo),
        );

        return new StravaService(
            $client,
            $this->crypto,
            $this->routes,
            'fake-client-id',
            'http://localhost/auth/strava/callback',
            true,
            'http://localhost',
            $gpxExport,
            $this->routeRepo,
            new GameRepository($this->pdo),
        );
    }

    private function connect(int $userId, string $scope = 'read,activity:read_all,activity:write'): void
    {
        $url = $this->strava->authorizeUrl($userId, 'mobile');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->strava->handleCallback((string)$q['state'], 'fake-auth-code', null, $scope);
    }

    private function uploadRoute(int $userId, string $payload): string
    {
        $res = $this->routes->createOrAddVersion(
            userId: $userId,
            title: 'Gravel-Runde',
            description: null,
            visibility: 'private',
            source: 'app',
            clientRouteUuid: self::uuid4(),
            payload: $payload,
        );
        return $res['route']['id'];
    }

    public function testConnectUrlIncludesActivityWrite(): void
    {
        $userId = $this->createUser();
        $svc = new StravaService(
            new FakeStravaClient(),
            $this->crypto,
            $this->routes,
            'real-client-id',
            'https://grava.world/auth/strava/callback',
            false,
            'https://grava.world',
            new RouteGpxExportService(
                $this->routes,
                new RouteGeoJson(new GeometryParser()),
                new RoutePrivacyTrimmer(),
                new PrivacyZoneRepository($this->pdo),
            ),
            $this->routeRepo,
            new GameRepository($this->pdo),
        );
        $url = $svc->authorizeUrl($userId);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('read,activity:read_all,activity:write', $q['scope'] ?? null);
    }

    public function testShareUploadsAndPersistsActivityId(): void
    {
        $userId = $this->createUser();
        $this->connect($userId);
        $payload = '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13],[9.67,47.14]]}';
        $publicId = $this->uploadRoute($userId, $payload);
        $desc = "🏆 GRAVA Revier-Report\n40,2 km";

        $res = $this->strava->share($userId, $publicId, $desc);
        $this->assertFalse($res['updated']);
        $this->assertSame($res['strava_activity_id'], $this->routeRepo->stravaActivityId(
            (int)$this->pdo->query("SELECT id FROM routes WHERE public_id = " . $this->pdo->quote($publicId))->fetchColumn()
        ));
        $this->assertStringContainsString($res['strava_activity_id'], $res['activity_url']);
        $this->assertSame($desc, $this->client->fakeDescriptionForExternal('grava-' . $publicId));
    }

    public function testSecondShareUpdatesWithoutNewUpload(): void
    {
        $userId = $this->createUser();
        $this->connect($userId);
        $publicId = $this->uploadRoute($userId, '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13]]}');

        $first = $this->strava->share($userId, $publicId, 'Erste Beschreibung');
        $second = $this->strava->share($userId, $publicId, 'Aktualisierte Beschreibung');

        $this->assertFalse($first['updated']);
        $this->assertTrue($second['updated']);
        $this->assertSame($first['strava_activity_id'], $second['strava_activity_id']);
        $this->assertSame('Aktualisierte Beschreibung', $this->client->fakeDescriptionForExternal('grava-' . $publicId));
    }

    public function testShareWithoutWriteScopeForbidden(): void
    {
        $userId = $this->createUser();
        $this->connect($userId, 'read,activity:read_all');
        $publicId = $this->uploadRoute($userId, '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13]]}');

        try {
            $this->strava->share($userId, $publicId, 'Test');
            $this->fail('403 erwartet');
        } catch (StravaException $e) {
            $this->assertSame(403, $e->httpStatus);
            $this->assertStringContainsString('neu verbinden', $e->getMessage());
        }
    }

    public function testShareForeignRouteForbidden(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $this->connect($other);
        $publicId = $this->uploadRoute($owner, '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13]]}');

        try {
            $this->strava->share($other, $publicId, 'Test');
            $this->fail('403 erwartet');
        } catch (StravaException $e) {
            $this->assertSame(403, $e->httpStatus);
        }
    }

    public function testShareEmptyTrackUnprocessable(): void
    {
        $userId = $this->createUser();
        $this->connect($userId);
        $publicId = $this->uploadRoute($userId, '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13]]}');
        // Zone überdeckt den gesamten Track → nach Trim kein GPX mehr.
        (new PrivacyZoneRepository($this->pdo))->upsert($userId, 47.125, 9.655, 5000, true);

        try {
            $this->strava->share($userId, $publicId, 'Test');
            $this->fail('422 erwartet');
        } catch (StravaException $e) {
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function testGpxTrimmedAtPrivacyZoneStart(): void
    {
        $userId = $this->createUser();
        (new PrivacyZoneRepository($this->pdo))->upsert($userId, 47.125, 9.655, 800, true);
        $payload = '{"type":"LineString","coordinates":[[9.655,47.125],[9.656,47.126],[9.80,47.30],[9.81,47.31]]}';
        $publicId = $this->uploadRoute($userId, $payload);

        $export = new RouteGpxExportService(
            $this->routes,
            new RouteGeoJson(new GeometryParser()),
            new RoutePrivacyTrimmer(),
            new PrivacyZoneRepository($this->pdo),
        );
        $res = $export->exportForStrava($userId, $publicId);

        $this->assertStringContainsString('lat="47.300000"', $res['gpx']);
        $this->assertStringNotContainsString('lat="47.125000"', $res['gpx']);
    }
}
