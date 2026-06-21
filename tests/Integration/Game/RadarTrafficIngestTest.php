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
use App\Routes\RadarTrafficData;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/**
 * Akzeptanzkriterien RADAR_TRAFFIC_BACKEND.md §B6.
 */
final class RadarTrafficIngestTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->config = new GameConfig($this->pdo);
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
            hasMotion: true, riddenAt: new DateTimeImmutable('2026-06-20 08:00:00', new DateTimeZone('UTC')),
        );
    }

    private function service(array $segments): GameIngestionService
    {
        return new GameIngestionService(
            new FakeEdgeMatcher($segments),
            $this->repo,
            new EdgeRecalculator($this->repo, $this->config),
            $this->config,
            $this->pdo,
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} [edgeA(busy), edgeB(quiet)] */
    private function ingestWithRadar(): array
    {
        $u1 = $this->createUser('armin');
        // Edge A: [9.65,47.12]→[9.66,47.13]; Edge B: [9.66,47.13]→[9.67,47.14]
        $segs = [
            $this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]]),
            $this->segment(1002, 11, 12, [[9.66, 47.13], [9.67, 47.14]]),
        ];
        // 10 Vorbeifahrten am Mittelpunkt von Edge A (≈ auf der Linie) → alle
        // matchen Edge A. Edge B wird mit Radar befahren, bekommt aber 0 Pässe.
        $passes = array_fill(0, 10, [47.125, 9.655]); // [lat, lon]
        $radar = new RadarTrafficData(3.4, $passes);

        $this->service($segs)->ingest(1, $u1, $this->parsedRoute(), true, $this->now(), $radar);

        $edges = $this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100);
        // nach way_id/id sortiert: erste = Edge A (1001), zweite = Edge B (1002)
        usort($edges, static fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
        return [$edges[0], $edges[1]];
    }

    public function testBusyEdgeGetsPassesAndFactorBelowOne(): void
    {
        [$busy] = $this->ingestWithRadar();

        $this->assertSame(10, (int)$busy['traffic_pass_count']);
        $this->assertSame(1, (int)$busy['traffic_observations']);
        $this->assertLessThan(1.0, (float)$busy['traffic_factor_cached']);
        // value = (max(pioneer,popularity)) * factor → unter dem ungedämpften Wert.
        $this->assertLessThan(100.0, (float)$busy['value_cached']);
    }

    public function testQuietEdgeObservedWithZeroPassesGetsFactorAboveOne(): void
    {
        [, $quiet] = $this->ingestWithRadar();

        $this->assertSame(0, (int)$quiet['traffic_pass_count']);
        $this->assertSame(1, (int)$quiet['traffic_observations']);
        $this->assertGreaterThan(1.0, (float)$quiet['traffic_factor_cached']);
        $this->assertGreaterThan(100.0, (float)$quiet['value_cached']);
    }

    public function testNoRadarDataKeepsFactorExactlyOne(): void
    {
        $u1 = $this->createUser('armin');
        $segs = [$this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]])];
        // Ohne Radar (null) → keine Beobachtungen → Faktor exakt 1.0.
        $this->service($segs)->ingest(1, $u1, $this->parsedRoute(), true, $this->now(), null);

        $edge = $this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100)[0];
        $this->assertSame(0, (int)$edge['traffic_observations']);
        $this->assertSame(1.0, (float)$edge['traffic_factor_cached']);
    }

    public function testFullRecomputeReproducesTrafficFactorIdentically(): void
    {
        [$busy] = $this->ingestWithRadar();
        $factorBefore = (float)$busy['traffic_factor_cached'];
        $valueBefore  = (float)$busy['value_cached'];

        // Voll-Recompute (liest game_edge_traffic neu) muss identisch sein.
        (new EdgeRecalculator($this->repo, $this->config))->recalculate((int)$busy['id'], $this->now());

        $after = $this->repo->edgeById((int)$busy['id']);
        $this->assertEqualsWithDelta($factorBefore, (float)$after['traffic_factor_cached'], 1e-9);
        $this->assertEqualsWithDelta($valueBefore, (float)$after['value_cached'], 1e-9);
    }

    public function testReingestSameRouteDoesNotDoubleCountPasses(): void
    {
        $u1 = $this->createUser('armin');
        $segs = [$this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]])];
        $radar = new RadarTrafficData(3.4, array_fill(0, 5, [47.125, 9.655]));
        $svc = $this->service($segs);
        $svc->ingest(1, $u1, $this->parsedRoute(), true, $this->now(), $radar);
        $svc->ingest(1, $u1, $this->parsedRoute(), true, $this->now(), $radar);

        $edge = $this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100)[0];
        $this->assertSame(5, (int)$edge['traffic_pass_count'], 'idempotent: kein Doppelzählen');
        $this->assertSame(1, (int)$edge['traffic_observations']);
    }
}
