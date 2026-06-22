<?php
declare(strict_types=1);

namespace Tests\Integration\Privacy;

use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Privacy\PrivacyZoneRepository;
use App\Routes\GeometryParser;
use App\Routes\ParsedRoute;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class PrivacyZoneIngestionTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameConfig $config;
    private PrivacyZoneRepository $zones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->config = new GameConfig($this->pdo);
        $this->zones = new PrivacyZoneRepository($this->pdo);
    }

    private function parsedRoute(): ParsedRoute
    {
        return (new GeometryParser())->parse(
            '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13],[9.80,47.30],[9.81,47.31]]}'
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
            new FakeEdgeMatcher($segments, false),
            $this->repo,
            new EdgeRecalculator($this->repo, $this->config),
            $this->config,
            $this->pdo,
            null,
            $this->zones,
        );
    }

    public function testSegmentsInZoneProduceNoEdgesOrPasses(): void
    {
        $uid = $this->createUser('armin');
        // Zone um (47.125, 9.655) — deckt das erste Segment ab, nicht das zweite.
        $this->zones->upsert($uid, 47.125, 9.655, 500, true);

        $segs = [
            $this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]]),   // in Zone
            $this->segment(1002, 12, 13, [[9.80, 47.30], [9.81, 47.31]]),   // außerhalb
        ];
        $res = $this->service($segs)->ingest(
            1, $uid, $this->parsedRoute(), true,
            new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC')),
        );

        $this->assertSame(2, $res['matched']);
        $this->assertSame(1, $res['passes_new']);
        $this->assertSame(1, $res['skipped_privacy_zone']);

        // Keine Kante in der Zone, genau eine außerhalb.
        $this->assertSame([], $this->repo->edgesInBbox(9.64, 47.11, 9.67, 47.14, null, 100));
        $outside = $this->repo->edgesInBbox(9.79, 47.29, 9.82, 47.32, null, 100);
        $this->assertCount(1, $outside);
    }

    public function testNoZoneIngestsAllSegments(): void
    {
        $uid = $this->createUser('armin');
        $segs = [
            $this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]]),
            $this->segment(1002, 12, 13, [[9.80, 47.30], [9.81, 47.31]]),
        ];
        $res = $this->service($segs)->ingest(
            1, $uid, $this->parsedRoute(), true,
            new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC')),
        );
        $this->assertSame(2, $res['passes_new']);
        $this->assertSame(0, $res['skipped_privacy_zone']);
    }
}
