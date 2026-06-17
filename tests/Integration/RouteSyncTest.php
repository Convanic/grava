<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Config;
use App\Routes\GeometryParser;
use App\Routes\GeometryStats;
use App\Routes\RouteRepository;
use App\Routes\RouteService;
use App\Routes\RouteStorage;
use Tests\IntegrationTestCase;

/**
 * M5: Sichert den Routen-Sync-Vertrag für die iOS-App ab.
 *
 * Kernzusage an den Client: Lädt die App eine aufgezeichnete Fahrt als
 * GPX hoch (inkl. `<ge:surfaceScore>`-Extensions), bleibt der Payload
 * **byte-genau** erhalten und kommt beim Download unverändert zurück.
 * Ein erneuter Upload mit demselben `client_route_uuid` erzeugt eine
 * neue Version statt eines Duplikats.
 */
final class RouteSyncTest extends IntegrationTestCase
{
    private RouteService $routes;
    private string $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->routes = new RouteService(
            new RouteRepository(),
            new RouteStorage(Config::instance()),
            new GeometryParser(),
            new GeometryStats(),
        );
        $payload = file_get_contents(__DIR__ . '/../fixtures/ride_app_export.gpx');
        $this->assertNotFalse($payload, 'Fixture konnte nicht gelesen werden.');
        $this->payload = $payload;
    }

    public function testUploadCreatesRouteAndPreservesPayloadByteForByte(): void
    {
        $userId     = $this->createUser();
        $clientUuid = self::uuid4();

        $result = $this->routes->createOrAddVersion(
            userId: $userId,
            title: 'Morgenrunde Kraichgau',
            description: null,
            visibility: 'private',
            source: 'app',
            clientRouteUuid: $clientUuid,
            payload: $this->payload,
        );

        $this->assertSame('created', $result['action']);
        $this->assertSame(1, $result['version']);
        $this->assertSame('app', $result['route']['source']);
        $this->assertSame('private', $result['route']['visibility']);

        $publicId = $result['route']['id'];
        $loaded   = $this->routes->loadPayload($userId, $publicId);

        $this->assertSame('gpx', $loaded['format']);
        $this->assertSame(1, $loaded['version']);
        // Der entscheidende Roundtrip-Beweis: byte-identisch.
        $this->assertSame($this->payload, $loaded['payload']);
        $this->assertStringContainsString('ge:surfaceScore', $loaded['payload']);
    }

    public function testReuploadWithSameClientUuidAddsVersion(): void
    {
        $userId     = $this->createUser();
        $clientUuid = self::uuid4();

        $first = $this->routes->createOrAddVersion(
            userId: $userId,
            title: 'Runde v1',
            description: null,
            visibility: 'private',
            source: 'app',
            clientRouteUuid: $clientUuid,
            payload: $this->payload,
        );

        // Zweiter Upload derselben logischen Route (gleicher client_route_uuid).
        $secondPayload = str_replace('Morgenrunde', 'Mittagsrunde', $this->payload);
        $second = $this->routes->createOrAddVersion(
            userId: $userId,
            title: 'Runde v2',
            description: null,
            visibility: 'public',
            source: 'app',
            clientRouteUuid: $clientUuid,
            payload: $secondPayload,
        );

        $this->assertSame('added_version', $second['action']);
        $this->assertSame(2, $second['version']);
        // Keine zweite Route: gleiche public_id wie beim ersten Upload.
        $this->assertSame($first['route']['id'], $second['route']['id']);

        $publicId = $second['route']['id'];

        // Head zeigt jetzt auf v2 ...
        $head = $this->routes->loadPayload($userId, $publicId);
        $this->assertSame(2, $head['version']);
        $this->assertSame($secondPayload, $head['payload']);

        // ... aber v1 bleibt unverändert abrufbar (Versionen sind immutable).
        $v1 = $this->routes->loadPayload($userId, $publicId, 1);
        $this->assertSame(1, $v1['version']);
        $this->assertSame($this->payload, $v1['payload']);
    }

    public function testUploadedRouteAppearsInListingWithStats(): void
    {
        $userId = $this->createUser();

        $result = $this->routes->createOrAddVersion(
            userId: $userId,
            title: 'Statistik-Runde',
            description: null,
            visibility: 'private',
            source: 'app',
            clientRouteUuid: self::uuid4(),
            payload: $this->payload,
        );
        $publicId = $result['route']['id'];

        $list = $this->routes->listForUser($userId);
        $this->assertCount(1, $list);
        $this->assertSame($publicId, $list[0]['id']);

        $route = $this->routes->get($userId, $publicId);
        $this->assertNotNull($route);
        $this->assertSame(5, $route['stats']['point_count'], 'Fünf Trackpoints aus dem Fixture.');
        $this->assertGreaterThan(0, $route['stats']['distance_m'], 'Distanz wurde berechnet.');
        $this->assertIsArray($route['tags']);
    }
}
