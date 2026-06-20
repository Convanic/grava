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
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Tests\IntegrationTestCase;

final class GameIngestBanLogTest extends IntegrationTestCase
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

    public function testBannedUserCreatesNoPasses(): void
    {
        $u1 = $this->createUser('cheater');
        $this->pdo->prepare(
            'INSERT INTO game_user_flag (user_id, banned, reason) VALUES (?, 1, ?)'
        )->execute([$u1, 'cheat']);

        $segs = [$this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]], '2026-06-20 08:00:00')];
        $res = $this->service($segs)->ingest(1, $u1, $this->parsedRoute(), true, $this->now());

        $this->assertSame(0, $res['passes_new']);
        $this->assertTrue($res['banned']);

        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM game_ingest_log')->fetchColumn();
        $this->assertSame(1, $count, 'Gesperrter User schreibt genau eine Log-Zeile.');
    }

    public function testNormalIngestWritesOkLog(): void
    {
        $u1 = $this->createUser('armin');
        $segs = [$this->segment(1001, 10, 11, [[9.65, 47.12], [9.66, 47.13]], '2026-06-20 08:00:00')];
        $res = $this->service($segs)->ingest(1, $u1, $this->parsedRoute(), true, $this->now());

        $this->assertSame(1, $res['passes_new']);
        $this->assertFalse($res['banned']);

        $row = $this->pdo->query(
            'SELECT status, matched_edges, new_passes FROM game_ingest_log ORDER BY id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'Erfolgreiche Ingestion schreibt eine Log-Zeile.');
        $this->assertSame('ok', $row['status']);
        $this->assertSame(1, (int) $row['new_passes']);
        $this->assertSame(1, (int) $row['matched_edges']);
    }
}
