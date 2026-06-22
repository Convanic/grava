<?php
declare(strict_types=1);

namespace Tests\Integration\Game\Crew;

use App\Game\Admin\GameAuditService;
use App\Game\Crew\CrewException;
use App\Game\Crew\CrewRepository;
use App\Game\Crew\CrewService;
use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class CrewLeaderboardTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private CrewRepository $crews;
    private CrewService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $cfg = new GameConfig($this->pdo);
        $this->repo = new GameRepository($this->pdo);
        $this->crews = new CrewRepository($this->pdo);
        $recalc = new EdgeRecalculator($this->repo, $cfg);
        $this->svc = new CrewService(
            $this->pdo, $this->crews, $this->repo, $recalc, $cfg, new GameAuditService($this->pdo),
        );
    }

    private function now(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    private function edge(int $wayId, int $nodeBase, float $lengthM): int
    {
        $a = $this->repo->upsertNode($nodeBase, 47.12, 9.65);
        $b = $this->repo->upsertNode($nodeBase + 1, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        return $this->repo->upsertEdge($wayId, $a, $b, $lengthM, $geom, null, 47.12, 9.65, 47.13, 9.66);
    }

    private function makeCrew(array $captainFirstUserIds): array
    {
        $this->pdo->prepare('INSERT INTO game_claimant (type, user_id) VALUES ("group", NULL)')->execute();
        $claimantId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO game_crew (claimant_id, name, slug, owner_user_id, join_code) VALUES (?, ?, ?, ?, ?)'
        )->execute([$claimantId, 'Crew', 'rangcrew', $captainFirstUserIds[0], 'LBCODE12']);
        $crewId = (int)$this->pdo->lastInsertId();
        foreach ($captainFirstUserIds as $i => $uid) {
            $this->pdo->prepare('INSERT INTO game_crew_member (user_id, crew_id, role) VALUES (?, ?, ?)')
                ->execute([$uid, $crewId, $i === 0 ? 'captain' : 'member']);
        }
        return ['claimant_id' => $claimantId, 'crew_id' => $crewId, 'slug' => 'rangcrew'];
    }

    private function ownEdge(int $edgeId, int $claimantId): void
    {
        $this->pdo->prepare('UPDATE game_edge SET owner_claimant_id = ? WHERE id = ?')
            ->execute([$claimantId, $edgeId]);
    }

    private function pass(int $edgeId, int $claimant, int $user, int $route, string $riddenAt): void
    {
        $this->repo->insertPassIfAbsent($edgeId, $claimant, $user, $route, substr($riddenAt, 0, 10), $riddenAt);
    }

    public function testNonMemberForbidden(): void
    {
        $u1 = $this->createUser('lbcap');
        $this->repo->riderClaimantId($u1);
        $outsider = $this->createUser('outsider');
        $crew = $this->makeCrew([$u1]);

        try {
            $this->svc->leaderboard($crew['slug'], $outsider);
            $this->fail('Nicht-Mitglied muss 403 erhalten.');
        } catch (CrewException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    public function testEmptyCrewReturnsZeros(): void
    {
        $u1 = $this->createUser('solocap');
        $this->repo->riderClaimantId($u1);
        $crew = $this->makeCrew([$u1]);

        $res = $this->svc->leaderboard($crew['slug'], $u1);
        $this->assertCount(1, $res['members']);
        $m = $res['members'][0];
        $this->assertSame('captain', $m['role']);
        $this->assertSame(0.0, $m['presence_contribution']);
        $this->assertSame(0, $m['held_edges']);
        $this->assertSame(0.0, $m['held_length_m']);
        $this->assertSame(0, $m['activity_rides']);
    }

    public function testMetricsAndTieBreak(): void
    {
        $u1 = $this->createUser('cap'); // niedrigere id -> Tie-Break-Gewinner
        $u2 = $this->createUser('mate');
        $r1 = $this->repo->riderClaimantId($u1);
        $r2 = $this->repo->riderClaimantId($u2);
        $crew = $this->makeCrew([$u1, $u2]);

        $e1 = $this->edge(4101, 40, 100.0);
        $e2 = $this->edge(4102, 42, 200.0);
        $this->ownEdge($e1, $crew['claimant_id']);
        $this->ownEdge($e2, $crew['claimant_id']);

        // E1: u1 & u2 je 1 Tag -> Gleichstand -> Tie-Break u1 (kleinere id).
        $this->pass($e1, $r1, $u1, 100, '2026-06-20 08:00:00.000');
        $this->pass($e1, $r2, $u2, 200, '2026-06-20 08:00:00.000');
        // E2: u2 an 2 Tagen, u1 an 1 Tag -> u2 Top-Beitragender.
        $this->pass($e2, $r1, $u1, 101, '2026-06-20 08:00:00.000');
        $this->pass($e2, $r2, $u2, 201, '2026-06-20 08:00:00.000');
        $this->pass($e2, $r2, $u2, 202, '2026-06-19 08:00:00.000');

        $now = $this->now('2026-06-20T12:00:00Z');
        $res = $this->svc->leaderboard($crew['slug'], $u1, $now);

        $by = [];
        foreach ($res['members'] as $m) {
            $by[$m['handle']] = $m;
        }
        $this->assertArrayHasKey('cap', $by);
        $this->assertArrayHasKey('mate', $by);

        // held: u1 trägt E1 (Tie-Break), u2 trägt E2.
        $this->assertSame(1, $by['cap']['held_edges']);
        $this->assertSame(100.0, $by['cap']['held_length_m']);
        $this->assertSame(1, $by['mate']['held_edges']);
        $this->assertSame(200.0, $by['mate']['held_length_m']);

        // presence_contribution: u1 ~2*0.998, u2 ~ (0.998 + 0.998 + 0.987).
        $this->assertEqualsWithDelta(1.996, $by['cap']['presence_contribution'], 0.01);
        $this->assertEqualsWithDelta(2.983, $by['mate']['presence_contribution'], 0.01);

        // activity (90-Tage, besitzunabhängig): u1 2 Fahrten / 300 m, u2 3 Fahrten / 500 m.
        $this->assertSame(2, $by['cap']['activity_rides']);
        $this->assertSame(300.0, $by['cap']['activity_distance_m']);
        $this->assertSame(3, $by['mate']['activity_rides']);
        $this->assertSame(500.0, $by['mate']['activity_distance_m']);
    }

    public function testInvalidatedPassesExcluded(): void
    {
        $u1 = $this->createUser('invcap');
        $r1 = $this->repo->riderClaimantId($u1);
        $crew = $this->makeCrew([$u1]);
        $e1 = $this->edge(4201, 50, 100.0);
        $this->ownEdge($e1, $crew['claimant_id']);

        $this->pass($e1, $r1, $u1, 300, '2026-06-20 08:00:00.000');
        $this->pass($e1, $r1, $u1, 301, '2026-06-19 08:00:00.000');
        // Den zweiten Pass soft-invalidieren.
        $this->pdo->prepare(
            "UPDATE game_edge_pass SET invalidated_at = '2026-06-20 09:00:00.000', invalid_reason = 'test'
              WHERE edge_id = ? AND user_id = ? AND ridden_on = '2026-06-19'"
        )->execute([$e1, $u1]);

        $now = $this->now('2026-06-20T12:00:00Z');
        $res = $this->svc->leaderboard($crew['slug'], $u1, $now);
        $m = $res['members'][0];

        // Nur 1 gültiger Pass zählt -> presence ~0.998, activity 1 Fahrt / 100 m.
        $this->assertEqualsWithDelta(0.998, $m['presence_contribution'], 0.01);
        $this->assertSame(1, $m['activity_rides']);
        $this->assertSame(100.0, $m['activity_distance_m']);
    }
}
