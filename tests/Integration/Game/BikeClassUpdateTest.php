<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\BikeClass;
use App\Game\EdgeRecordService;
use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameReadService;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Game\PlayerLeaderboardService;
use App\Routes\ParsedRoute;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/**
 * Delta-Akzeptanz aus backend/GAME_BIKE_CLASS_UPDATE.md (2026-06-25).
 */
final class BikeClassUpdateTest extends IntegrationTestCase
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

    private function parsedRoute(string $bikeClass = 'gravel', bool $recordingMarkers = true): ParsedRoute
    {
        return new ParsedRoute(
            points: [[9.65, 47.12, null, null, null, null, null, '2026-06-20T08:00:00Z']],
            sourceFormat: 'geojson',
            bikeClass: $bikeClass,
            hasRecordingMarkers: $recordingMarkers,
        );
    }

    private function segment(float $durationS, int $way = 1001, string $at = '2026-06-20 08:00:00'): MatchedSegment
    {
        return new MatchedSegment(
            wayId: $way, nodeARef: 10, nodeBRef: 11, lengthM: 200.0,
            geometry: [[9.65, 47.12], [9.66, 47.13]], surface: 'gravel',
            avgSpeedKmh: 24.0, maxHaccM: 8.0, hasMotion: true,
            riddenAt: new DateTimeImmutable($at, new DateTimeZone('UTC')),
            durationS: $durationS,
        );
    }

    /** @param list<MatchedSegment> $segments */
    private function ingest(array $segments, int $userId, ParsedRoute $route): void
    {
        (new GameIngestionService(
            new FakeEdgeMatcher($segments),
            $this->repo,
            new EdgeRecalculator($this->repo, $this->config),
            $this->config,
            $this->pdo,
        ))->ingest(1, $userId, $route, true);
    }

    private function edgeId(): int
    {
        return (int)$this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 1)[0]['id'];
    }

    /** 1. Granular gespeichert */
    public function testGravelStoredGranularly(): void
    {
        $u = $this->createUser('graveler');
        $this->ingest([$this->segment(30.0)], $u, $this->parsedRoute('gravel'));
        $pass = $this->repo->passRecordForUserEdgeDay($this->edgeId(), $u, '2026-06-20');
        $this->assertSame('gravel', $pass['bike_class']);
    }

    /** 2. Muskel-Gruppe: gravel + road gemeinsam gerankt */
    public function testMuscleGroupRanksGravelAndRoadTogether(): void
    {
        $gravel = $this->createUser('graveler');
        $road = $this->createUser('roadie');
        $this->ingest([$this->segment(30.0)], $gravel, $this->parsedRoute('gravel'));
        $this->ingest([$this->segment(25.0)], $road, $this->parsedRoute('road'));

        $muscle = $this->records->records($this->edgeId(), 'muscle', 'all', null);
        $this->assertCount(2, $muscle['records']);
        $this->assertSame(1, $muscle['records'][0]['rank']);
        $this->assertGreaterThan($muscle['records'][1]['avg_speed_kmh'], $muscle['records'][0]['avg_speed_kmh']);
    }

    /** 3. E-Bike getrennt von Muskel */
    public function testEbikeExcludedFromMuscleList(): void
    {
        $u = $this->createUser('ebiker');
        $this->ingest([$this->segment(16.0)], $u, $this->parsedRoute('ebike'));

        $this->assertCount(0, $this->records->records($this->edgeId(), 'muscle', 'all', null)['records']);
        $this->assertCount(1, $this->records->records($this->edgeId(), 'ebike', 'all', null)['records']);
        $this->assertCount(1, $this->records->records($this->edgeId(), 'all', 'all', null)['records']);
    }

    /** 4. Legacy muscle/other erscheinen unter bike=muscle */
    public function testLegacyMuscleAndOtherInMuscleGroup(): void
    {
        $u = $this->createUser('legacy');
        $this->ingest([$this->segment(30.0)], $u, $this->parsedRoute('gravel'));
        $edgeId = $this->edgeId();
        $this->pdo->prepare('UPDATE game_edge_pass SET bike_class = ? WHERE user_id = ?')
            ->execute([BikeClass::LEGACY_MUSCLE, $u]);
        $this->assertCount(1, $this->repo->bestRecordPassesForEdge($edgeId, BikeClass::MUSCLE, null));

        $this->pdo->prepare('UPDATE game_edge_pass SET bike_class = ? WHERE user_id = ?')
            ->execute([BikeClass::OTHER, $u]);
        $this->assertCount(1, $this->repo->bestRecordPassesForEdge($edgeId, BikeClass::MUSCLE, null));
    }

    /** 5. Crowns je Motor-Gruppe (Muskel-Typen gebündelt) */
    public function testRecordsHeldUsesMotorGroups(): void
    {
        $muscle = $this->createUser('m1');
        $ebike = $this->createUser('e1');
        $this->ingest([$this->segment(25.0, way: 1001)], $muscle, $this->parsedRoute('gravel'));
        $this->ingest([$this->segment(25.0, way: 1002)], $muscle, $this->parsedRoute('road'));
        $this->ingest([$this->segment(20.0, way: 1001)], $ebike, $this->parsedRoute('ebike'));

        // Eine Muskel-Krone (1002), eine E-Bike-Krone (1001) — je Motor-Gruppe ein Leader pro Kante.
        $this->assertSame(2, $this->records->recordsHeld($muscle));
        $this->assertSame(1, $this->records->recordsHeld($ebike));
        $this->assertSame(2, $this->read->me($muscle, $muscle)['records_held']);

        $board = (new PlayerLeaderboardService($this->repo, $this->config))
            ->leaderboard('world', 'all', 'records', $muscle);
        $this->assertSame(2, $board['entries'][0]['value']);
    }
}
