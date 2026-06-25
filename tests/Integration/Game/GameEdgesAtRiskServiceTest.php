<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameEdgesAtRiskService;
use App\Game\GameRepository;
use App\Privacy\PrivacyZoneRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/** Akzeptanzkriterien aus backend/GAME_EDGES_AT_RISK_BACKEND.md §5. */
final class GameEdgesAtRiskServiceTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private EdgeRecalculator $recalc;
    private GameEdgesAtRiskService $atRisk;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $config = new GameConfig($this->pdo);
        $this->repo = new GameRepository($this->pdo);
        $this->recalc = new EdgeRecalculator($this->repo, $config);
        $this->atRisk = new GameEdgesAtRiskService(
            $this->repo,
            $config,
            $this->recalc,
            new PrivacyZoneRepository($this->pdo),
        );
        $this->now = new DateTimeImmutable('2026-06-20T12:00:00Z', new DateTimeZone('UTC'));
    }

    /** AK1: keine Herausforderer → at_risk_count = 0, edges leer. */
    public function testNoChallengerReturnsZero(): void
    {
        $owner = $this->createUser('solo');
        $edge = $this->makeEdge(5001, [[9.65, 47.12], [9.66, 47.13]]);
        $this->seedOwnerDays($owner, $edge, 5);

        $res = $this->atRisk->atRisk($owner);
        $this->assertSame(0, $res['at_risk_count']);
        $this->assertSame([], $res['edges']);
    }

    /** AK2: P_chal ≈ 0.9 × P_owner (≥ risk_threshold) → challenged. */
    public function testChallengedWhenAboveRiskThreshold(): void
    {
        $owner = $this->createUser('defender');
        $challenger = $this->createUser('attacker');
        $edge = $this->makeEdge(5002, [[9.65, 47.12], [9.66, 47.13]]);
        $this->seedOwnerDays($owner, $edge, 10);
        $this->seedChallengerDays($challenger, $edge, 9);

        $res = $this->atRisk->atRisk($owner);
        $this->assertSame(1, $res['at_risk_count']);
        $this->assertSame('challenged', $res['edges'][0]['reason']);
        $this->assertSame($edge, $res['edges'][0]['edge_id']);
        $this->assertArrayHasKey('challenger_presence', $res['edges'][0]);
        $this->assertSame('attacker', $res['edges'][0]['challenger_handle'] ?? null);
    }

    /** AK3: P_chal ≈ 0.8 × P_owner (< 0.85) → nicht in Gefahr. */
    public function testNotChallengedWhenBelowRiskThreshold(): void
    {
        $owner = $this->createUser('safe');
        $challenger = $this->createUser('weak');
        $edge = $this->makeEdge(5003, [[9.65, 47.12], [9.66, 47.13]]);
        $this->seedOwnerDays($owner, $edge, 10);
        $this->seedChallengerDays($challenger, $edge, 8);

        $res = $this->atRisk->atRisk($owner);
        $this->assertSame(0, $res['at_risk_count']);
        $this->assertSame([], array_filter(
            $res['edges'],
            static fn(array $e): bool => $e['reason'] === 'challenged',
        ));
    }

    /** AK4: höheres P_chal/P_owner steht zuerst in der Liste. */
    public function testSortsByUrgencyDescending(): void
    {
        $owner = $this->createUser('holder');
        $ch1 = $this->createUser('hot');
        $ch2 = $this->createUser('warm');
        $edgeHot  = $this->makeEdge(5010, [[9.65, 47.12], [9.66, 47.13]]);
        $edgeWarm = $this->makeEdge(5011, [[9.70, 47.14], [9.71, 47.15]]);

        $this->seedOwnerDays($owner, $edgeHot, 5);
        $this->seedOwnerDays($owner, $edgeWarm, 10);
        $this->seedChallengerDays($ch1, $edgeHot, 5);
        $this->seedChallengerDays($ch2, $edgeWarm, 9);

        $res = $this->atRisk->atRisk($owner);
        $this->assertSame(2, $res['at_risk_count']);
        $this->assertSame($edgeHot, $res['edges'][0]['edge_id'], 'Höhere Dringlichkeit zuerst.');
    }

    /** AK5: Crew-Mitglied sieht gefährdete Crew-Kanten. */
    public function testCrewMemberSeesCrewEdges(): void
    {
        $cap = $this->createUser('captain');
        $mate = $this->createUser('member');
        $attacker = $this->createUser('rival');
        $edge = $this->makeEdge(5020, [[9.65, 47.12], [9.66, 47.13]]);

        $this->seedOwnerDays($cap, $edge, 10);
        $crewClaimant = $this->makeCrew([$cap, $mate]);
        $this->recalc->recalculate($edge, $this->now);
        $this->seedChallengerDays($attacker, $edge, 9);
        $this->recalc->recalculate($edge, $this->now);

        $this->assertSame($crewClaimant, (int)$this->repo->edgeById($edge)['owner_claimant_id']);

        $res = $this->atRisk->atRisk($mate);
        $this->assertSame(1, $res['at_risk_count']);
        $this->assertSame('challenged', $res['edges'][0]['reason']);
    }

    /** AK6: Liste gekürzt, at_risk_count vollständig. */
    public function testListLimitDoesNotCapCount(): void
    {
        $this->pdo->exec(
            "INSERT INTO game_config (config_key, config_value) VALUES ('at_risk_list_limit', '10')
             ON DUPLICATE KEY UPDATE config_value = '10'"
        );
        $atRisk = new GameEdgesAtRiskService(
            $this->repo,
            new GameConfig($this->pdo),
            $this->recalc,
            new PrivacyZoneRepository($this->pdo),
        );

        $owner = $this->createUser('many');
        $ch = $this->createUser('press');
        for ($i = 0; $i < 12; $i++) {
            $edge = $this->makeEdge(5100 + $i, [[9.65 + $i * 0.01, 47.12], [9.66 + $i * 0.01, 47.13]]);
            $this->seedOwnerDays($owner, $edge, 10);
            $this->seedChallengerDays($ch, $edge, 9);
        }

        $res = $atRisk->atRisk($owner);
        $this->assertSame(12, $res['at_risk_count']);
        $this->assertCount(10, $res['edges']);
    }

    /** AK7: Kanten in der Heimat-Privatzone tauchen nicht auf. */
    public function testPrivacyZoneEdgesExcluded(): void
    {
        $owner = $this->createUser('home');
        $attacker = $this->createUser('foe');
        $inZone = $this->makeEdge(5201, [[9.65, 47.12], [9.66, 47.13]]);
        $outside = $this->makeEdge(5202, [[9.80, 47.30], [9.81, 47.31]]);

        $this->seedOwnerDays($owner, $inZone, 10);
        $this->seedOwnerDays($owner, $outside, 10);
        $this->seedChallengerDays($attacker, $inZone, 9);
        $this->seedChallengerDays($attacker, $outside, 9);

        $this->pdo->prepare(
            'INSERT INTO user_privacy_zone (user_id, lat, lon, radius_m, enabled)
             VALUES (?, ?, ?, ?, 1)'
        )->execute([$owner, 47.125, 9.655, 500]);

        $res = $this->atRisk->atRisk($owner);
        $this->assertSame(1, $res['at_risk_count']);
        $this->assertSame($outside, $res['edges'][0]['edge_id']);
    }

    /** @param list<array{0:float,1:float}> $coords */
    private function makeEdge(int $wayId, array $coords): int
    {
        $a = $this->repo->upsertNode($wayId * 10, $coords[0][1], $coords[0][0]);
        $b = $this->repo->upsertNode($wayId * 10 + 1, $coords[1][1], $coords[1][0]);
        $lons = array_column($coords, 0);
        $lats = array_column($coords, 1);
        $json = json_encode(['type' => 'LineString', 'coordinates' => $coords]);
        return $this->repo->upsertEdge(
            $wayId, $a, $b, 120.0, $json, null,
            min($lats), min($lons), max($lats), max($lons),
        );
    }

    private function seedOwnerDays(int $userId, int $edgeId, int $days): void
    {
        $claimant = $this->repo->riderClaimantId($userId);
        for ($d = 0; $d < $days; $d++) {
            $day = $this->now->modify("-{$d} days");
            $on = $day->format('Y-m-d');
            $at = $on . ' 08:00:00.000';
            $this->repo->insertPassIfAbsent($edgeId, $claimant, $userId, 100 + $edgeId + $d, $on, $at);
        }
        $this->repo->refreshEdgeDiscovery($edgeId);
        $this->recalc->recalculate($edgeId, $this->now);
    }

    private function seedChallengerDays(int $userId, int $edgeId, int $days): void
    {
        $claimant = $this->repo->riderClaimantId($userId);
        for ($d = 0; $d < $days; $d++) {
            $day = $this->now->modify("-{$d} days");
            $on = $day->format('Y-m-d');
            $at = $on . ' 09:00:00.000';
            $this->repo->insertPassIfAbsent($edgeId, $claimant, $userId, 200 + $edgeId + $d, $on, $at);
        }
        $this->recalc->recalculate($edgeId, $this->now);
    }

    /** @param list<int> $userIds */
    private function makeCrew(array $userIds): int
    {
        $this->pdo->prepare('INSERT INTO game_claimant (type, user_id) VALUES ("group", NULL)')->execute();
        $claimantId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO game_crew (claimant_id, name, slug, owner_user_id, join_code)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $claimantId,
            'Crew',
            'crew-' . $claimantId,
            $userIds[0],
            substr('CD' . $claimantId . 'XXXXXX', 0, 8),
        ]);
        $crewId = (int)$this->pdo->lastInsertId();
        foreach ($userIds as $i => $uid) {
            $this->pdo->prepare('INSERT INTO game_crew_member (user_id, crew_id, role) VALUES (?, ?, ?)')
                ->execute([$uid, $crewId, $i === 0 ? 'captain' : 'member']);
        }
        return $claimantId;
    }
}
