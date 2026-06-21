<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameReadService;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Routes\GeometryParser;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class GameReadServiceTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameReadService $read;
    private GameConfig $config;
    private int $u1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->config = new GameConfig($this->pdo);
        $this->read = new GameReadService($this->repo, $this->config);
        $this->u1 = $this->createUser('armin');

        $now = new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));
        $route = (new GeometryParser())->parse('{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13]]}');
        $segs = [new MatchedSegment(1001, 10, 11, 120.0, [[9.65, 47.12], [9.66, 47.13]], 'gravel', 18.0, 8.0, true, $now)];
        (new GameIngestionService(
            new FakeEdgeMatcher($segs), $this->repo,
            new EdgeRecalculator($this->repo, $this->config), $this->config, $this->pdo,
        ))->ingest(1, $this->u1, $route, true, $now);
    }

    public function testBboxReturnsEdgeInsideAndOmitsOutside(): void
    {
        $now = new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));
        $mine = $this->repo->riderClaimantId($this->u1);

        $inside = $this->read->edgesInBbox('9.6,47.1,9.7,47.2', $mine, $now, 100);
        $this->assertCount(1, $inside);
        $e = $inside[0];
        $this->assertSame('LineString', $e['geom']['type']);
        $this->assertSame('armin', $e['owner']['handle']);
        $this->assertTrue($e['owner_is_me']);
        $this->assertEqualsWithDelta(100.0, $e['value'], 0.1);
        $this->assertEqualsWithDelta(1.0, $e['freshness'], 0.01);
        $this->assertSame(1, $e['distinct_riders_total']);
        $this->assertSame('gravel', $e['surface_character']);

        $outside = $this->read->edgesInBbox('10.0,48.0,10.1,48.1', null, $now, 100);
        $this->assertSame([], $outside);
    }

    public function testEdgeDetailHasValueBreakdownAndCohort(): void
    {
        $now = new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));
        $mine = $this->repo->riderClaimantId($this->u1);
        $id = $this->read->edgesInBbox('9.6,47.1,9.7,47.2', null, $now, 100)[0]['id'];

        $detail = $this->read->edgeDetail((int)$id, $mine, $now);
        $this->assertNotNull($detail);
        $this->assertEqualsWithDelta(100.0, $detail['value']['pioneer'], 0.1);
        $this->assertGreaterThanOrEqual($detail['value']['pioneer'], $detail['value']['total']);
        $this->assertSame(0.0, $detail['value']['curation']);
        $this->assertCount(1, $detail['pioneer_cohort']);
        $this->assertSame('armin', $detail['pioneer_cohort'][0]['handle']);
        $this->assertSame(1, $detail['pioneer_cohort'][0]['rank']);
    }

    public function testFreshnessDecaysOnRead(): void
    {
        // 45 Tage später gelesen → Frische ~0.5, ohne neuen Pass/Recompute.
        $later = new DateTimeImmutable('2026-08-04T08:00:00Z', new DateTimeZone('UTC'));
        $e = $this->read->edgesInBbox('9.6,47.1,9.7,47.2', null, $later, 100)[0];
        $this->assertEqualsWithDelta(0.5, $e['freshness'], 0.02);
    }

    public function testFreshnessCappedAtOneForFuturePass(): void
    {
        // Lesen 10 Tage VOR dem (gespeicherten) last_pass → ageDays < 0 →
        // presenceWeight > 1.0. Server muss kosmetisch auf 1.0 kappen (iOS #3).
        $before = new DateTimeImmutable('2026-06-10T08:00:00Z', new DateTimeZone('UTC'));
        $e = $this->read->edgesInBbox('9.6,47.1,9.7,47.2', null, $before, 100)[0];
        $this->assertLessThanOrEqual(1.0, $e['freshness']);
        $this->assertSame(1.0, $e['freshness']);
    }
}
