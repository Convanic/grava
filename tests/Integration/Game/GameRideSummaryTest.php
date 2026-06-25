<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameRepository;
use App\Game\GameRideSummaryService;
use App\Game\MatchedSegment;
use App\Game\RideSummaryNotIngestedException;
use App\Game\Rush\RushRepository;
use App\Privacy\PrivacyZoneRepository;
use App\Privacy\RoutePrivacyTrimmer;
use App\Routes\GeometryParser;
use App\Routes\ParsedRoute;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class GameRideSummaryTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameConfig $config;
    private GameRideSummaryService $summary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->config = new GameConfig($this->pdo);
        $this->summary = new GameRideSummaryService(
            $this->repo,
            new RushRepository($this->pdo),
            new PrivacyZoneRepository($this->pdo),
            new RoutePrivacyTrimmer(),
        );
    }

    private function parsedRoute(): ParsedRoute
    {
        return (new GeometryParser())->parse(
            '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13],[9.67,47.14]]}'
        );
    }

    private function segment(int $way, int $a, int $b, array $geom, string $at): MatchedSegment
    {
        return new MatchedSegment(
            wayId: $way, nodeARef: $a, nodeBRef: $b, lengthM: 120.0,
            geometry: $geom, surface: 'gravel', avgSpeedKmh: 18.0, maxHaccM: 8.0,
            hasMotion: true,
            riddenAt: new DateTimeImmutable($at, new DateTimeZone('UTC')),
        );
    }

    private function ingest(int $routeId, int $userId, array $segments): void
    {
        $svc = new GameIngestionService(
            new FakeEdgeMatcher($segments),
            $this->repo,
            new EdgeRecalculator($this->repo, $this->config),
            $this->config,
            $this->pdo,
        );
        $svc->ingest(
            $routeId,
            $userId,
            $this->parsedRoute(),
            true,
            new DateTimeImmutable('2026-06-20T08:00:00Z'),
        );
    }

    public function testNotIngestedRouteThrows(): void
    {
        $userId = $this->createUser('armin');
        $publicId = $this->createRoute($userId);

        $this->expectException(RideSummaryNotIngestedException::class);
        $this->summary->summary($userId, $publicId);
    }

    public function testSummaryCountsEdgesAfterIngest(): void
    {
        $userId = $this->createUser('armin');
        $publicId = $this->createRoute($userId);
        $routeId = (int)$this->repo->resolveRouteForIngest($publicId)['route_id'];

        $segs = [
            $this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]], '2026-06-20 08:00:00'),
            $this->segment(1002, 11, 12, [[9.66, 47.13], [9.67, 47.14]], '2026-06-20 08:05:00'),
        ];
        $this->ingest($routeId, $userId, $segs);

        $res = $this->summary->summary($userId, $publicId);
        $this->assertSame(2, $res['edges_total']);
        $this->assertSame(2, $res['edges_new']);
        $this->assertSame(0, $res['edges_taken_over']);
        $this->assertSame([], $res['pioneer_names']);
        $this->assertNull($res['rush']);
        $this->assertNull($res['points_awarded']);
        $this->assertCount(2, $res['edges']);
        $this->assertSame('pioneer', $res['edges'][0]['category']);
        $this->assertSame('LineString', $res['edges'][0]['geom']['type']);
    }

    public function testEdgeCategoriesSumToTotal(): void
    {
        $userId = $this->createUser('armin');
        $publicId = $this->createRoute($userId);
        $routeId = (int)$this->repo->resolveRouteForIngest($publicId)['route_id'];

        $segs = [
            $this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]], '2026-06-20 08:00:00'),
            $this->segment(1002, 11, 12, [[9.66, 47.13], [9.67, 47.14]], '2026-06-20 08:05:00'),
        ];
        $this->ingest($routeId, $userId, $segs);

        $res = $this->summary->summary($userId, $publicId);
        $cats = array_column($res['edges'], 'category');
        $this->assertSame(2, count($cats));
        $this->assertSame(2, count(array_filter($cats, static fn($c) => $c === 'pioneer')));
    }

    public function testTakenOverEdgeHasCapturedCategory(): void
    {
        $owner = $this->createUser('owner');
        $taker = $this->createUser('taker');
        $publicTaker = $this->createRoute($taker);
        $routeOwner = (int)$this->repo->resolveRouteForIngest($this->createRoute($owner))['route_id'];
        $routeTaker = (int)$this->repo->resolveRouteForIngest($publicTaker)['route_id'];

        $geom = [[9.65, 47.12], [9.66, 47.13]];
        $this->ingest($routeOwner, $owner, [
            $this->segment(2001, 20, 21, $geom, '2026-06-18 08:00:00'),
        ]);
        $this->ingest($routeTaker, $taker, [
            $this->segment(2001, 20, 21, $geom, '2026-06-20 09:00:00'),
        ]);
        $this->ingest($routeTaker, $taker, [
            $this->segment(2001, 20, 21, $geom, '2026-06-21 09:00:00'),
        ]);

        $res = $this->summary->summary($taker, $publicTaker);
        $this->assertSame('captured', $res['edges'][0]['category']);
    }

    public function testEdgeGeomTrimmedInPrivacyZone(): void
    {
        $userId = $this->createUser('armin');
        (new PrivacyZoneRepository($this->pdo))->upsert($userId, 47.125, 9.655, 800, true);
        $publicId = $this->createRoute($userId);
        $routeId = (int)$this->repo->resolveRouteForIngest($publicId)['route_id'];

        // Kante außerhalb der Zone ingestieren, Geometrie danach künstlich durch die Zone verlängern.
        $this->ingest($routeId, $userId, [
            $this->segment(1001, 10, 11, [[9.80, 47.30], [9.81, 47.31]], '2026-06-20 08:00:00'),
        ]);
        $edgeId = (int)$this->pdo->query('SELECT id FROM game_edge LIMIT 1')->fetchColumn();
        $leaky = json_encode([
            'type' => 'LineString',
            'coordinates' => [[9.655, 47.125], [9.80, 47.30], [9.81, 47.31]],
        ], JSON_THROW_ON_ERROR);
        $this->pdo->prepare('UPDATE game_edge SET geom_geojson = ? WHERE id = ?')->execute([$leaky, $edgeId]);

        $coords = $this->summary->summary($userId, $publicId)['edges'][0]['geom']['coordinates'];
        $this->assertSame([9.80, 47.30], $coords[0]);
        $this->assertStringNotContainsString('9.655', json_encode($coords));
    }

    public function testRepeatedCallsAreIdentical(): void
    {
        $userId = $this->createUser('armin');
        $publicId = $this->createRoute($userId);
        $routeId = (int)$this->repo->resolveRouteForIngest($publicId)['route_id'];
        $segs = [$this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]], '2026-06-20 08:00:00')];
        $this->ingest($routeId, $userId, $segs);

        $a = $this->summary->summary($userId, $publicId);
        $b = $this->summary->summary($userId, $publicId);
        $this->assertSame($a, $b);
    }

    public function testTakenOverEdgeCounted(): void
    {
        $owner = $this->createUser('owner');
        $taker = $this->createUser('taker');
        $publicOwner = $this->createRoute($owner);
        $publicTaker = $this->createRoute($taker);
        $routeOwner = (int)$this->repo->resolveRouteForIngest($publicOwner)['route_id'];
        $routeTaker = (int)$this->repo->resolveRouteForIngest($publicTaker)['route_id'];

        $geom = [[9.65, 47.12], [9.66, 47.13]];
        $this->ingest($routeOwner, $owner, [
            $this->segment(2001, 20, 21, $geom, '2026-06-18 08:00:00'),
        ]);

        // Zwei Tages-Pässe des Übernehmers → genug Präsenz für Hysterese-Übernahme.
        $this->ingest($routeTaker, $taker, [
            $this->segment(2001, 20, 21, $geom, '2026-06-20 09:00:00'),
        ]);
        $this->ingest($routeTaker, $taker, [
            $this->segment(2001, 20, 21, $geom, '2026-06-21 09:00:00'),
        ]);

        $res = $this->summary->summary($taker, $publicTaker);
        $this->assertSame(1, $res['edges_total']);
        $this->assertSame(0, $res['edges_new']);
        $this->assertSame(1, $res['edges_taken_over']);
    }

    public function testForeignRouteReturnsNull(): void
    {
        $owner = $this->createUser('owner');
        $other = $this->createUser('other');
        $publicId = $this->createRoute($owner);
        $routeId = (int)$this->repo->resolveRouteForIngest($publicId)['route_id'];
        $this->ingest($routeId, $owner, [
            $this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]], '2026-06-20 08:00:00'),
        ]);

        $this->assertNull($this->summary->summary($other, $publicId));
    }
}
