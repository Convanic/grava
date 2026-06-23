<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameMath;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Routes\GeometryParser;
use App\Routes\ParsedRoute;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Tests\IntegrationTestCase;

final class GameIngestionTest extends IntegrationTestCase
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

    private function segment(int $way, int $a, int $b, array $geom, string $at): MatchedSegment
    {
        return new MatchedSegment(
            wayId: $way, nodeARef: $a, nodeBRef: $b, lengthM: 120.0,
            geometry: $geom, surface: 'gravel', avgSpeedKmh: 18.0, maxHaccM: 8.0,
            hasMotion: true, riddenAt: new DateTimeImmutable($at, new DateTimeZone('UTC')),
        );
    }

    private function service(array $segments, bool $throw = false): GameIngestionService
    {
        return new GameIngestionService(
            new FakeEdgeMatcher($segments, $throw),
            $this->repo,
            new EdgeRecalculator($this->repo, $this->config),
            $this->config,
            $this->pdo,
        );
    }

    private function now(string $iso = '2026-06-20T08:00:00Z'): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public function testIngestGivesOwnershipToFirstRider(): void
    {
        $u1 = $this->createUser('armin');
        $segs = [
            $this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]], '2026-06-20 08:00:00'),
            $this->segment(1002, 11, 12, [[9.66, 47.13], [9.67, 47.14]], '2026-06-20 08:05:00'),
        ];
        $res = $this->service($segs)->ingest(1, $u1, $this->parsedRoute(), true, $this->now());

        $this->assertSame(2, $res['matched']);
        $this->assertSame(2, $res['passes_new']);
        $c1 = $this->repo->riderClaimantId($u1);
        foreach ($this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100) as $edge) {
            $this->assertSame($c1, (int)$edge['owner_claimant_id']);
            $this->assertSame(1, (int)$edge['distinct_riders_total']);
            $this->assertSame($c1, (int)$edge['discoverer_claimant_id']);
            $this->assertEqualsWithDelta(100.0, (float)$edge['value_cached'], 0.1);
            $this->assertEqualsWithDelta(1.0, (float)$edge['freshness_cached'], 0.01);
        }
    }

    public function testReingestSameRouteSameDayCreatesNoDuplicatePasses(): void
    {
        $u1 = $this->createUser('armin');
        $segs = [$this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]], '2026-06-20 08:00:00')];
        $svc = $this->service($segs);
        $first = $svc->ingest(1, $u1, $this->parsedRoute(), true, $this->now());
        $second = $svc->ingest(1, $u1, $this->parsedRoute(), true, $this->now('2026-06-20T09:00:00Z'));

        $this->assertSame(1, $first['passes_new']);
        $this->assertSame(0, $second['passes_new']);
        $this->assertSame(1, $second['skipped_day_cap']);
        $edge = $this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100)[0];
        $this->assertSame(1, (int)$edge['distinct_riders_total']);
    }

    public function testTwelveDistinctRidersDropPioneerToFifty(): void
    {
        $segGeom = [[9.65, 47.12], [9.66, 47.13]];
        for ($i = 1; $i <= 12; $i++) {
            $uid = $this->createUser('rider' . $i);
            $segs = [$this->segment(1001, 10, 11, $segGeom, '2026-06-20 08:00:00')];
            $this->service($segs)->ingest($i, $uid, $this->parsedRoute(), true, $this->now());
        }
        $edge = $this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100)[0];
        $this->assertSame(12, (int)$edge['distinct_riders_total']);
        $pioneer = GameMath::pioneer(12, 100.0, 12.0, 4.0);
        $this->assertEqualsWithDelta(50.0, $pioneer, 0.1);
        $cohort = $this->repo->firstPassPerUser((int)$edge['id'], 10);
        $this->assertCount(10, $cohort, 'Pionier-Kohorte = erste 10 Handles');
        $this->assertSame('rider1', $cohort[0]['handle']);
    }

    public function testAuthFiltersRejectSlowAndInaccuratePasses(): void
    {
        $u1 = $this->createUser('armin');
        $slow = new MatchedSegment(1001, 10, 11, 50.0, [[9.65, 47.12], [9.66, 47.13]], null,
            avgSpeedKmh: 3.0, maxHaccM: 5.0, hasMotion: true, riddenAt: $this->now());
        $inaccurate = new MatchedSegment(1002, 11, 12, 50.0, [[9.66, 47.13], [9.67, 47.14]], null,
            avgSpeedKmh: 18.0, maxHaccM: 45.0, hasMotion: true, riddenAt: $this->now());
        $res = $this->service([$slow, $inaccurate])->ingest(1, $u1, $this->parsedRoute(), true, $this->now());

        $this->assertSame(0, $res['passes_new']);
        $this->assertSame(1, $res['skipped_auth_speed']);
        $this->assertSame(1, $res['skipped_auth_hacc']);
    }

    public function testTrustedSourceBypassesMotionAuthAndCreatesPasses(): void
    {
        // Importierte/Strava-Segmente: keine Motion-/Surface-Samples.
        $seg = new MatchedSegment(
            wayId: 1001, nodeARef: 10, nodeBRef: 11, lengthM: 120.0,
            geometry: [[9.65, 47.12], [9.66, 47.13]], surface: null,
            avgSpeedKmh: null, maxHaccM: null, hasMotion: false,
            riddenAt: $this->now(),
        );

        // Ohne Bypass (z. B. App-Route ohne Motion) → übersprungen, kein Besitz.
        $u1 = $this->createUser('armin');
        $skipped = $this->service([$seg])->ingest(1, $u1, $this->parsedRoute(), false, $this->now());
        $this->assertSame(1, $skipped['matched']);
        $this->assertSame(0, $skipped['passes_new']);
        $this->assertSame(1, $skipped['skipped_no_motion']);
        $this->assertSame([], $this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100));

        // Mit Bypass (Strava/Import gilt als „echt") → Besitz-Pässe entstehen.
        $u2 = $this->createUser('berta');
        $res = $this->service([$seg])->ingest(2, $u2, $this->parsedRoute(), false, $this->now(), null, true);
        $this->assertSame(1, $res['matched']);
        $this->assertSame(1, $res['passes_new']);
        $this->assertSame(0, $res['skipped_no_motion']);
        $c2 = $this->repo->riderClaimantId($u2);
        $edges = $this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100);
        $this->assertCount(1, $edges);
        $this->assertSame($c2, (int)$edges[0]['owner_claimant_id']);
    }

    public function testMatcherFailureLeavesNoDataButReingestRecovers(): void
    {
        $u1 = $this->createUser('armin');
        $segs = [$this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]], '2026-06-20 08:00:00')];

        try {
            $this->service($segs, throw: true)->ingest(1, $u1, $this->parsedRoute(), true, $this->now());
            $this->fail('Matcher-Ausfall muss eine Exception werfen.');
        } catch (RuntimeException) {
        }
        $this->assertSame([], $this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100));

        $res = $this->service($segs)->ingest(1, $u1, $this->parsedRoute(), true, $this->now());
        $this->assertSame(1, $res['passes_new']);
    }
}
