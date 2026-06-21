<?php
declare(strict_types=1);

namespace Tests\Integration\Game\Admin;

use App\Game\Admin\GameAdminService;
use App\Game\GameConfig;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class GameAdminServiceTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameConfig $config;
    private GameAdminService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->config = new GameConfig($this->pdo);
        $this->service = new GameAdminService($this->pdo, $this->repo, $this->config);
    }

    private function now(string $iso = '2026-06-20T10:00:00Z'): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    private function makeEdge(int $wayId, int $nodeAOsm, int $nodeBOsm): int
    {
        $a = $this->repo->upsertNode($nodeAOsm, 47.12, 9.65);
        $b = $this->repo->upsertNode($nodeBOsm, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        return $this->repo->upsertEdge($wayId, $a, $b, 120.0, $geom, null, 47.12, 9.65, 47.13, 9.66);
    }

    public function testInspectorPioneerGoldenNumber(): void
    {
        $now = $this->now('2026-06-20T10:00:00Z');
        $edgeId = $this->makeEdge(2001, 20, 21);

        for ($i = 0; $i < 12; $i++) {
            $uid = $this->createUser("u{$i}");
            $cid = $this->repo->riderClaimantId($uid);
            $day = $now->modify('-' . $i . ' days')->format('Y-m-d');
            $this->repo->insertPassIfAbsent($edgeId, $cid, $uid, 1, $day, $day . ' 08:00:00.000');
        }

        $inspector = $this->service->edgeInspector($edgeId, $now);

        $this->assertNotNull($inspector);
        $this->assertSame(12, $inspector['n']);
        $this->assertEqualsWithDelta(50.0, $inspector['value']['pioneer'], 0.0001);
    }

    public function testLeaderboardRanksByHeldEdges(): void
    {
        $u1 = $this->createUser('rider1');
        $u2 = $this->createUser('rider2');
        $c1 = $this->repo->riderClaimantId($u1);
        $c2 = $this->repo->riderClaimantId($u2);

        $e1 = $this->makeEdge(3001, 30, 31);
        $e2 = $this->makeEdge(3002, 32, 33);
        $e3 = $this->makeEdge(3003, 34, 35);

        $upd = $this->pdo->prepare('UPDATE game_edge SET owner_claimant_id = ? WHERE id = ?');
        $upd->execute([$c1, $e1]);
        $upd->execute([$c1, $e2]);
        $upd->execute([$c2, $e3]);

        $this->pdo->prepare('UPDATE game_edge SET discoverer_claimant_id = ? WHERE id = ?')
            ->execute([$c1, $e1]);

        $board = $this->service->leaderboard(10);

        $this->assertCount(2, $board);
        $this->assertSame($c1, $board[0]['claimant_id']);
        $this->assertSame(2, $board[0]['held_edges']);
        $this->assertSame($c2, $board[1]['claimant_id']);
        $this->assertSame(1, $board[1]['held_edges']);
    }

    private function makeCrew(string $name, string $slug, string $joinCode, int $captainUserId): array
    {
        $this->pdo->prepare('INSERT INTO game_claimant (type, user_id) VALUES ("group", NULL)')->execute();
        $groupClaimant = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO game_crew (claimant_id, name, slug, owner_user_id, join_code) VALUES (?, ?, ?, ?, ?)'
        )->execute([$groupClaimant, $name, $slug, $captainUserId, $joinCode]);
        $crewId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO game_crew_member (user_id, crew_id, role) VALUES (?, ?, "captain")')
            ->execute([$captainUserId, $crewId]);
        return [$crewId, $groupClaimant];
    }

    public function testCrewLeaderboardRanksByHeldEdgesAndExposesCaptain(): void
    {
        $cap1 = $this->createUser('captain1');
        $cap2 = $this->createUser('captain2');
        [, $gc1] = $this->makeCrew('Gravel Goats', 'gravel-goats', 'JOINAAA1', $cap1);
        [, $gc2] = $this->makeCrew('Mud Movers', 'mud-movers', 'JOINBBB1', $cap2);

        // weiteres Mitglied in Crew 1
        $m = $this->createUser('member1');
        $crew1Id = (int)$this->pdo->query("SELECT id FROM game_crew WHERE slug = 'gravel-goats'")->fetchColumn();
        $this->pdo->prepare('INSERT INTO game_crew_member (user_id, crew_id, role) VALUES (?, ?, "member")')
            ->execute([$m, $crew1Id]);

        $e1 = $this->makeEdge(5001, 50, 51);
        $e2 = $this->makeEdge(5002, 52, 53);
        $e3 = $this->makeEdge(5003, 54, 55);

        $upd = $this->pdo->prepare('UPDATE game_edge SET owner_claimant_id = ? WHERE id = ?');
        $upd->execute([$gc1, $e1]);
        $upd->execute([$gc1, $e2]);
        $upd->execute([$gc2, $e3]);
        $this->pdo->prepare('UPDATE game_edge SET discoverer_claimant_id = ? WHERE id = ?')->execute([$gc1, $e1]);

        $board = $this->service->crewLeaderboard(10);

        $this->assertCount(2, $board);
        $this->assertSame('gravel-goats', $board[0]['slug']);
        $this->assertSame(2, $board[0]['held_edges']);
        $this->assertSame(2, $board[0]['members']);
        $this->assertSame(1, $board[0]['pioneered']);
        $this->assertSame('captain1', $board[0]['captain_handle']);
        $this->assertSame('mud-movers', $board[1]['slug']);
        $this->assertSame(1, $board[1]['held_edges']);
    }

    public function testCrewLeaderboardIncludesCrewsWithoutTerritory(): void
    {
        $cap = $this->createUser('lonelycap');
        $this->makeCrew('Empty Crew', 'empty-crew', 'JOINCCC1', $cap);

        $board = $this->service->crewLeaderboard(10);

        $this->assertCount(1, $board);
        $this->assertSame('empty-crew', $board[0]['slug']);
        $this->assertSame(0, $board[0]['held_edges']);
    }

    public function testHealthMetricsCounts(): void
    {
        $now = $this->now('2026-06-20T10:00:00Z');
        $u1 = $this->createUser('rider1');
        $c1 = $this->repo->riderClaimantId($u1);

        $e1 = $this->makeEdge(4001, 40, 41);
        $e2 = $this->makeEdge(4002, 42, 43);

        $u2 = $this->createUser('rider2');
        $c2 = $this->repo->riderClaimantId($u2);

        $this->repo->insertPassIfAbsent($e1, $c1, $u1, 1, '2026-06-20', '2026-06-20 08:00:00.000');
        $this->repo->insertPassIfAbsent($e1, $c2, $u2, 1, '2026-06-19', '2026-06-19 08:00:00.000');
        $this->repo->insertPassIfAbsent($e2, $c1, $u1, 1, '2026-06-18', '2026-06-18 08:00:00.000');

        $this->pdo->prepare(
            'UPDATE game_edge_pass SET invalidated_at = ?, invalid_reason = ? WHERE edge_id = ? AND user_id = ?'
        )->execute(['2026-06-20 09:00:00.000', 'test', $e2, $u1]);

        $metrics = $this->service->healthMetrics($now);

        $this->assertSame(2, $metrics['edges']);
        $this->assertSame(4, $metrics['nodes']);
        $this->assertSame(2, $metrics['passes_total']);
    }

    public function testIngestHealthAggregates(): void
    {
        $u1 = $this->createUser('rider1');
        $this->repo->insertIngestLog(1, $u1, 'ok', 3, 2, null, null, 100);
        $this->repo->insertIngestLog(2, $u1, 'ok', 1, 1, null, null, 80);
        $this->repo->insertIngestLog(3, $u1, 'failed', 0, 0, null, 'boom', 50);

        $health = $this->service->ingestHealth();

        $this->assertSame(2, $health['ok']);
        $this->assertSame(0, $health['pending']);
        $this->assertSame(1, $health['failed']);
        $this->assertEqualsWithDelta(0.6667, $health['match_rate'], 0.0001);
    }
}
