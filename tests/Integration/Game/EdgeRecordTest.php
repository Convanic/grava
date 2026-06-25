<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecordMath;
use App\Game\EdgeRecordService;
use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameReadService;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Game\PlayerLeaderboardService;
use App\Routes\GeometryParser;
use App\Routes\ParsedRoute;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/**
 * Akzeptanzkriterien §9 aus backend/GAME_SEGMENT_SPEED_BACKEND.md (2026-06-24).
 */
final class EdgeRecordTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameConfig $config;
    private EdgeRecordService $records;
    private GameReadService $read;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->config = new GameConfig($this->pdo);
        $this->records = new EdgeRecordService($this->repo, $this->config);
        $this->read = new GameReadService($this->repo, $this->config, $this->records);
    }

    private function parsedRoute(string $bikeClass = 'muscle', bool $recordingMarkers = true): ParsedRoute
    {
        $base = (new GeometryParser())->parse(
            '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13],[9.67,47.14]]}'
        );
        return new ParsedRoute(
            points: $base->points,
            sourceFormat: $base->sourceFormat,
            startedAt: $base->startedAt,
            endedAt: $base->endedAt,
            elevationGainOverrideM: $base->elevationGainOverrideM,
            bikeClass: $bikeClass,
            hasRecordingMarkers: $recordingMarkers,
        );
    }

    private function segment(
        float $lengthM = 200.0,
        ?float $durationS = 30.0,
        string $at = '2026-06-20 08:00:00',
        ?float $maxHaccM = 8.0,
        int $way = 1001,
    ): MatchedSegment {
        return new MatchedSegment(
            wayId: $way, nodeARef: 10, nodeBRef: 11, lengthM: $lengthM,
            geometry: [[9.65, 47.12], [9.66, 47.13]], surface: 'gravel',
            avgSpeedKmh: 24.0, maxHaccM: $maxHaccM, hasMotion: true,
            riddenAt: new DateTimeImmutable($at, new DateTimeZone('UTC')),
            durationS: $durationS,
        );
    }

    /** @param list<MatchedSegment> $segments */
    private function ingest(array $segments, int $userId, ?ParsedRoute $route = null, ?DateTimeImmutable $now = null): array
    {
        $svc = new GameIngestionService(
            new FakeEdgeMatcher($segments),
            $this->repo,
            new EdgeRecalculator($this->repo, $this->config),
            $this->config,
            $this->pdo,
        );
        return $svc->ingest(1, $userId, $route ?? $this->parsedRoute(), true, $now ?? $this->now());
    }

    private function now(string $iso = '2026-06-20T08:00:00Z'): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    private function edgeId(): int
    {
        return (int)$this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 1)[0]['id'];
    }

    /** §9.1 */
    public function testDurationMathFromShapeDelta(): void
    {
        $r = EdgeRecordMath::fromDurationSeconds(200.0, 30.0);
        $this->assertNotNull($r);
        $this->assertSame(30000, $r['duration_ms']);
        $this->assertEqualsWithDelta(24.0, $r['avg_speed_kmh'], 0.1);
    }

    /** §9.2 */
    public function testDayCapKeepsFastestPass(): void
    {
        $u = $this->createUser('fast');
        $this->ingest([$this->segment(durationS: 40.0, at: '2026-06-20 08:00:00')], $u);
        $this->ingest([$this->segment(durationS: 30.0, at: '2026-06-20 10:00:00')], $u, null, $this->now('2026-06-20T11:00:00Z'));

        $pass = $this->repo->passRecordForUserEdgeDay($this->edgeId(), $u, '2026-06-20');
        $this->assertNotNull($pass);
        $this->assertSame(30000, $pass['duration_ms']);
        $this->assertEqualsWithDelta(24.0, $pass['avg_speed_kmh'], 0.1);
    }

    /** §9.3 */
    public function testAllTimeBestIsMinimumAcrossDays(): void
    {
        $u = $this->createUser('multi');
        $this->ingest([$this->segment(durationS: 35.0, at: '2026-06-18 08:00:00')], $u);
        $this->ingest([$this->segment(durationS: 30.0, at: '2026-06-19 08:00:00')], $u);
        $this->ingest([$this->segment(durationS: 33.0, at: '2026-06-20 08:00:00')], $u);

        $rows = $this->repo->bestRecordPassesForEdge($this->edgeId(), 'muscle', null);
        $this->assertCount(1, $rows);
        $this->assertSame(30000, $rows[0]['duration_ms']);
    }

    /** §9.4 */
    public function testBikeClassSeparation(): void
    {
        $u = $this->createUser('rider');
        $this->ingest([$this->segment(durationS: 16.0)], $u, $this->parsedRoute('ebike'));
        $this->ingest(
            [$this->segment(durationS: 30.0, at: '2026-06-21 08:00:00')],
            $u,
            $this->parsedRoute('muscle'),
            $this->now('2026-06-21T08:00:00Z'),
        );

        $muscle = $this->records->records($this->edgeId(), 'muscle', 'all', $u);
        $ebike = $this->records->records($this->edgeId(), 'ebike', 'all', $u);
        $this->assertNotNull($muscle);
        $this->assertCount(1, $muscle['records']);
        $this->assertEqualsWithDelta(24.0, $muscle['records'][0]['avg_speed_kmh'], 0.5);
        $this->assertCount(1, $ebike['records']);
        $this->assertGreaterThan(40.0, $ebike['records'][0]['avg_speed_kmh']);
    }

    /** §9.5 */
    public function testRecordAuthSkipsButPassRemains(): void
    {
        $u = $this->createUser('cheater');
        $res = $this->ingest([$this->segment(lengthM: 30.0, durationS: 5.0)], $u);
        $this->assertSame(1, $res['passes_new']);
        $this->assertSame(1, $res['skipped_record_short']);

        $pass = $this->repo->passRecordForUserEdgeDay($this->edgeId(), $u, '2026-06-20');
        $this->assertNotNull($pass);
        $this->assertNull($pass['duration_ms']);

        $edge = $this->pdo->query('SELECT owner_claimant_id FROM game_edge WHERE id = ' . $this->edgeId())->fetchColumn();
        $this->assertNotFalse($edge);
    }

    /** §9.6 */
    public function testRecordsOrthogonalToOwnership(): void
    {
        $u = $this->createUser('owner');
        $this->ingest([$this->segment(durationS: null)], $u, $this->parsedRoute(), $this->now());
        $before = $this->ownershipSnapshot();

        $this->pdo->prepare(
            'UPDATE game_edge_pass SET duration_ms = 30000, avg_speed_kmh = 24.0, bike_class = "muscle"
              WHERE user_id = ?'
        )->execute([$u]);
        $after = $this->ownershipSnapshot();

        $this->assertSame($before, $after);
    }

    /** §9.7 */
    public function testBikeClassFromGpxMetadata(): void
    {
        $u = $this->createUser('ebiker');
        $this->ingest([$this->segment()], $u, $this->parsedRoute('ebike'));
        $pass = $this->repo->passRecordForUserEdgeDay($this->edgeId(), $u, '2026-06-20');
        $this->assertSame('ebike', $pass['bike_class']);

        $u2 = $this->createUser('other');
        $this->ingest([$this->segment()], $u2, $this->parsedRoute(''));
        $row = $this->pdo->prepare(
            'SELECT bike_class FROM game_edge_pass WHERE user_id = ? AND invalidated_at IS NULL LIMIT 1'
        );
        $row->execute([$u2]);
        $bike = $row->fetchColumn();
        $this->assertSame('other', $bike);
    }

    /** §9.8 */
    public function testCrownsAndRecordsMetric(): void
    {
        $u1 = $this->createUser('crown1');
        $u2 = $this->createUser('crown2');
        foreach ([1001, 1002, 1003] as $way) {
            $this->ingest([$this->segment(durationS: 25.0, way: $way)], $u1);
        }
        $this->ingest([$this->segment(durationS: 20.0, way: 1001)], $u2);

        $this->assertSame(2, $this->records->recordsHeld($u1));
        $this->assertSame(1, $this->records->recordsHeld($u2));

        $me = $this->read->me($u1, $u1);
        $this->assertSame(2, $me['records_held']);

        $board = (new PlayerLeaderboardService($this->repo, $this->config))
            ->leaderboard('world', 'all', 'records', $u1, $this->now());
        $this->assertSame(2, $board['entries'][0]['value']);
        $this->assertTrue($board['entries'][0]['is_me']);
        $this->assertSame(1, $board['entries'][1]['value']);
    }

    /** §9.9 */
    public function testRecordsReadAnonymousAndAuthenticated(): void
    {
        $u = $this->createUser('leader');
        $this->ingest([$this->segment(durationS: 28.0)], $u);

        $anon = $this->records->records($this->edgeId(), 'muscle', 'all', null);
        $this->assertNotNull($anon);
        $this->assertArrayNotHasKey('me', $anon);
        $this->assertArrayNotHasKey('is_me', $anon['records'][0]);

        $auth = $this->records->records($this->edgeId(), 'muscle', 'all', $u);
        $this->assertArrayHasKey('me', $auth);
        $this->assertTrue($auth['records'][0]['is_me']);
    }

    /** §9.10 — zweiter Ingest-Lauf ändert Rekord-Daten nicht. */
    public function testReIngestIdempotent(): void
    {
        $u = $this->createUser('stable');
        $seg = [$this->segment(durationS: 30.0)];
        $this->ingest($seg, $u);
        $before = $this->repo->passRecordForUserEdgeDay($this->edgeId(), $u, '2026-06-20');
        $this->ingest($seg, $u, null, $this->now('2026-06-20T09:00:00Z'));
        $after = $this->repo->passRecordForUserEdgeDay($this->edgeId(), $u, '2026-06-20');
        $this->assertSame($before, $after);
    }

    /** @return array<int,array{owner:?int,value:string,fresh:string,n:int}> */
    private function ownershipSnapshot(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, owner_claimant_id, value_cached, freshness_cached, distinct_riders_total
               FROM game_edge ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = [
                'owner' => $r['owner_claimant_id'] !== null ? (int)$r['owner_claimant_id'] : null,
                'value' => (string)$r['value_cached'],
                'fresh' => (string)$r['freshness_cached'],
                'n'     => (int)$r['distinct_riders_total'],
            ];
        }
        return $out;
    }
}
