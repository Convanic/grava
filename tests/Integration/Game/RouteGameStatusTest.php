<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Routes\GeometryParser;
use App\Routes\ParsedRoute;
use App\Routes\RouteRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/**
 * Verifiziert den geräteübergreifenden Route-Spielstatus in der Public-Form:
 * `game_ingested_at` (gesetzt, sobald ≥1 Pass des Eigentümers aus der Route
 * existiert) und `game_edges_count` (Anzahl beanspruchter Kanten).
 */
final class RouteGameStatusTest extends IntegrationTestCase
{
    private RouteRepository $routes;
    private GameRepository $repo;
    private GameConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->routes = new RouteRepository();
        $this->repo   = new GameRepository($this->pdo);
        $this->config = new GameConfig($this->pdo);
    }

    private function ingestion(array $segments): GameIngestionService
    {
        return new GameIngestionService(
            new FakeEdgeMatcher($segments),
            $this->repo,
            new EdgeRecalculator($this->repo, $this->config),
            $this->config,
            $this->pdo,
        );
    }

    private function parsedRoute(): ParsedRoute
    {
        return (new GeometryParser())->parse(
            '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13],[9.67,47.14]]}'
        );
    }

    private function segment(int $way, int $a, int $b, array $geom): MatchedSegment
    {
        return new MatchedSegment(
            wayId: $way, nodeARef: $a, nodeBRef: $b, lengthM: 120.0,
            geometry: $geom, surface: 'gravel', avgSpeedKmh: 18.0, maxHaccM: 8.0,
            hasMotion: true,
            riddenAt: new DateTimeImmutable('2026-06-20 08:00:00', new DateTimeZone('UTC')),
        );
    }

    public function testFreshRouteHasNullGameStatus(): void
    {
        $userId   = $this->createUser('armin');
        $publicId = $this->createRoute($userId);

        $route = $this->routes->findByPublicId($publicId);
        $this->assertNotNull($route);
        $this->assertNull($route['game_ingested_at']);
        $this->assertSame(0, $route['game_edges_count']);
    }

    public function testGameIngestedAtSetAfterPasses(): void
    {
        $userId   = $this->createUser('armin');
        $publicId = $this->createRoute($userId);
        $routeId  = (int)$this->routes->findByPublicId($publicId)['_internal']['route_id'];

        $segs = [
            $this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]]),
            $this->segment(1002, 11, 12, [[9.66, 47.13], [9.67, 47.14]]),
        ];
        $res = $this->ingestion($segs)->ingest(
            $routeId,
            $userId,
            $this->parsedRoute(),
            true,
            new DateTimeImmutable('2026-06-20T08:00:00Z'),
        );
        $this->assertSame(2, $res['passes_new']);

        $route = $this->routes->findByPublicId($publicId);
        $this->assertNotNull($route['game_ingested_at']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $route['game_ingested_at'],
            'game_ingested_at muss ISO-8601 in UTC (Sekunden-Präzision) sein.',
        );
        $this->assertSame(2, $route['game_edges_count']);
    }

    public function testGameStatusScopedToOwner(): void
    {
        // Pässe eines ANDEREN Users auf derselben route_id dürfen den Status
        // des Eigentümers nicht setzen (Subquery ist an r.user_id gebunden).
        $owner    = $this->createUser('owner');
        $other    = $this->createUser('other');
        $publicId = $this->createRoute($owner);
        $routeId  = (int)$this->routes->findByPublicId($publicId)['_internal']['route_id'];

        $segs = [$this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]])];
        $this->ingestion($segs)->ingest(
            $routeId,
            $other,
            $this->parsedRoute(),
            true,
            new DateTimeImmutable('2026-06-20T08:00:00Z'),
        );

        $route = $this->routes->findByPublicId($publicId);
        $this->assertNull($route['game_ingested_at']);
        $this->assertSame(0, $route['game_edges_count']);
    }
}
