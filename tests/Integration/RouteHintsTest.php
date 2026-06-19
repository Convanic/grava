<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Config;
use App\Routes\GeometryParser;
use App\Routes\GeometryStats;
use App\Routes\RouteHintParser;
use App\Routes\RouteHintRepository;
use App\Routes\RouteHintService;
use App\Routes\RouteRepository;
use App\Routes\RouteService;
use App\Routes\RouteStorage;
use PDO;
use Tests\IntegrationTestCase;

/**
 * M8: Akzeptanzkriterien für Wegpunkt-Hinweise (ROUTE_HINTS_BACKEND.md).
 *
 *  1. Upload GPX mit 2 Hinweisen (1 negativ, 1 positiv) → 2 Zeilen, korrekt gemappt.
 *  2. get() liefert die Hinweise im `hints`-Array.
 *  3. Re-Upload derselben Route mit unveränderten Hinweisen → keine Duplikate (Upsert).
 *  4. GPX ohne unsere Extension → Upload ok, keine Hinweis-Zeilen, kein Fehler.
 *  5. Löschen der Route → route_hints per FK-Cascade weg.
 */
final class RouteHintsTest extends IntegrationTestCase
{
    private RouteService $routes;
    private string $hintsPayload;
    private string $plainPayload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->routes = new RouteService(
            new RouteRepository(),
            new RouteStorage(Config::instance()),
            new GeometryParser(),
            new GeometryStats(),
            new RouteHintService(new RouteHintParser(), new RouteHintRepository()),
        );

        $hints = file_get_contents(__DIR__ . '/../fixtures/ride_with_hints.gpx');
        $plain = file_get_contents(__DIR__ . '/../fixtures/ride_app_export.gpx');
        $this->assertNotFalse($hints, 'Hint-Fixture fehlt.');
        $this->assertNotFalse($plain, 'Plain-Fixture fehlt.');
        $this->hintsPayload = $hints;
        $this->plainPayload = $plain;
    }

    public function testUploadPersistsTwoHintsMappedCorrectly(): void
    {
        $userId = $this->createUser();
        $result = $this->routes->createOrAddVersion(
            userId: $userId,
            title: 'Tour mit Hinweisen',
            description: null,
            visibility: 'private',
            source: 'app',
            clientRouteUuid: self::uuid4(),
            payload: $this->hintsPayload,
        );
        $publicId = $result['route']['id'];

        $rows = $this->pdo->query(
            'SELECT reason_key, sentiment, label, note, lat, lon, recorded_at
               FROM route_hints
               JOIN routes ON routes.id = route_hints.route_id
              WHERE routes.public_id = ' . $this->pdo->quote($publicId) . '
              ORDER BY reason_key'
        )->fetchAll(PDO::FETCH_ASSOC);

        // Kriterium 1 + 4: genau 2 Hinweise (der Fremd-Waypoint ohne
        // Extension wird ignoriert).
        $this->assertCount(2, $rows);

        // great_view (positiv) kommt alphabetisch zuerst.
        $this->assertSame('great_view', $rows[0]['reason_key']);
        $this->assertSame('positive', $rows[0]['sentiment']);
        $this->assertSame('Tolle Aussicht', $rows[0]['label']);
        $this->assertNull($rows[0]['note']);

        $this->assertSame('unrideable', $rows[1]['reason_key']);
        $this->assertSame('negative', $rows[1]['sentiment']);
        $this->assertSame('Unfahrbar / Umkehren', $rows[1]['label']);
        $this->assertSame('Brücke weggespült', $rows[1]['note']);
        $this->assertEqualsWithDelta(47.123456, (float)$rows[1]['lat'], 0.0000001);
        $this->assertEqualsWithDelta(9.654321, (float)$rows[1]['lon'], 0.0000001);
        $this->assertNotNull($rows[1]['recorded_at']);
    }

    public function testGetReturnsHintsArray(): void
    {
        $userId = $this->createUser();
        $result = $this->routes->createOrAddVersion(
            userId: $userId,
            title: 'Tour',
            description: null,
            visibility: 'private',
            source: 'app',
            clientRouteUuid: self::uuid4(),
            payload: $this->hintsPayload,
        );
        $route = $this->routes->get($userId, $result['route']['id']);

        $this->assertNotNull($route);
        $this->assertArrayHasKey('hints', $route);
        $this->assertCount(2, $route['hints']);

        $byKey = [];
        foreach ($route['hints'] as $h) {
            $byKey[$h['reason_key']] = $h;
        }
        $this->assertSame('negative', $byKey['unrideable']['sentiment']);
        $this->assertSame('positive', $byKey['great_view']['sentiment']);
        // Millisekunden-ISO mit Z-Suffix.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $byKey['unrideable']['recorded_at'],
        );
    }

    public function testReuploadSameHintsDoesNotDuplicate(): void
    {
        $userId     = $this->createUser();
        $clientUuid = self::uuid4();

        $this->routes->createOrAddVersion(
            userId: $userId, title: 'v1', description: null, visibility: 'private',
            source: 'app', clientRouteUuid: $clientUuid, payload: $this->hintsPayload,
        );
        $result = $this->routes->createOrAddVersion(
            userId: $userId, title: 'v2', description: null, visibility: 'private',
            source: 'app', clientRouteUuid: $clientUuid, payload: $this->hintsPayload,
        );
        $this->assertSame('added_version', $result['action']);

        $count = (int)$this->pdo->query(
            'SELECT COUNT(*) FROM route_hints
               JOIN routes ON routes.id = route_hints.route_id
              WHERE routes.public_id = ' . $this->pdo->quote($result['route']['id'])
        )->fetchColumn();

        // Kriterium 3: Upsert greift — weiterhin genau 2 Zeilen.
        $this->assertSame(2, $count);
    }

    public function testReuploadWithoutHintsRemovesThem(): void
    {
        $userId     = $this->createUser();
        $clientUuid = self::uuid4();

        $this->routes->createOrAddVersion(
            userId: $userId, title: 'v1', description: null, visibility: 'private',
            source: 'app', clientRouteUuid: $clientUuid, payload: $this->hintsPayload,
        );
        // v2 ohne Hinweise (gleiche logische Route) → Hinweise verschwinden.
        $result = $this->routes->createOrAddVersion(
            userId: $userId, title: 'v2', description: null, visibility: 'private',
            source: 'app', clientRouteUuid: $clientUuid, payload: $this->plainPayload,
        );

        $count = (int)$this->pdo->query(
            'SELECT COUNT(*) FROM route_hints
               JOIN routes ON routes.id = route_hints.route_id
              WHERE routes.public_id = ' . $this->pdo->quote($result['route']['id'])
        )->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testPlainGpxUploadHasNoHintsAndNoError(): void
    {
        $userId = $this->createUser();
        $result = $this->routes->createOrAddVersion(
            userId: $userId, title: 'Ohne Hinweise', description: null, visibility: 'private',
            source: 'app', clientRouteUuid: self::uuid4(), payload: $this->plainPayload,
        );

        $route = $this->routes->get($userId, $result['route']['id']);
        $this->assertNotNull($route);
        $this->assertSame([], $route['hints']);
    }

    public function testDeletingRouteCascadesHints(): void
    {
        $userId = $this->createUser();
        $result = $this->routes->createOrAddVersion(
            userId: $userId, title: 'Tour', description: null, visibility: 'private',
            source: 'app', clientRouteUuid: self::uuid4(), payload: $this->hintsPayload,
        );
        $publicId = $result['route']['id'];

        $routeId = (int)$this->pdo->query(
            'SELECT id FROM routes WHERE public_id = ' . $this->pdo->quote($publicId)
        )->fetchColumn();

        // Hartes Löschen der Route → FK-Cascade muss route_hints mitnehmen.
        $this->pdo->prepare('DELETE FROM routes WHERE id = ?')->execute([$routeId]);

        $count = (int)$this->pdo->query(
            'SELECT COUNT(*) FROM route_hints WHERE route_id = ' . $routeId
        )->fetchColumn();
        $this->assertSame(0, $count);
    }
}
