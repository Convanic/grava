<?php
declare(strict_types=1);

namespace Tests\Integration\Game\Admin;

use App\Game\Admin\GameAuditService;
use App\Game\Admin\GamePassAdminService;
use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class GamePassAdminServiceTest extends IntegrationTestCase
{
    public function testInvalidateAndReactivateRecomputeEdgeAndAudit(): void
    {
        $now = new DateTimeImmutable('2026-06-20T10:00:00Z', new DateTimeZone('UTC'));

        $repo = new GameRepository($this->pdo);
        $recalc = new EdgeRecalculator($repo, new GameConfig($this->pdo));
        $audit = new GameAuditService($this->pdo);
        $svc = new GamePassAdminService($this->pdo, $repo, $recalc, $audit);

        $user1 = $this->createUser('rider1');
        $user2 = $this->createUser('rider2');
        $c1 = $repo->riderClaimantId($user1);
        $c2 = $repo->riderClaimantId($user2);

        $a = $repo->upsertNode(10, 47.12, 9.65);
        $b = $repo->upsertNode(11, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $edgeId = $repo->upsertEdge(1001, $a, $b, 120.0, $geom, 'gravel', 47.12, 9.65, 47.13, 9.66);

        $this->assertTrue($repo->insertPassIfAbsent($edgeId, $c1, $user1, 1, '2026-06-19', '2026-06-19 08:00:00.000'));
        $this->assertTrue($repo->insertPassIfAbsent($edgeId, $c2, $user2, 1, '2026-06-20', '2026-06-20 08:00:00.000'));

        $repo->refreshEdgeDiscovery($edgeId);
        $recalc->recalculate($edgeId, $now);

        $this->assertSame(2, (int)$repo->edgeById($edgeId)['distinct_riders_total']);

        $passId = (int)$this->passId($edgeId, $user2);

        // Invalidate user2's pass.
        $this->assertTrue($svc->invalidate(7, $passId, 'cheat', $now));
        $this->assertSame(1, $repo->distinctRidersTotal($edgeId));
        $this->assertSame(1, (int)$repo->edgeById($edgeId)['distinct_riders_total']);
        $this->assertSame(1, $this->auditCount('pass_invalidate'));

        // Reactivate.
        $this->assertTrue($svc->reactivate(7, $passId, $now));
        $this->assertSame(2, $repo->distinctRidersTotal($edgeId));
        $this->assertSame(1, $this->auditCount('pass_reactivate'));

        // Nonexistent pass.
        $this->assertFalse($svc->invalidate(7, 999999, 'x'));
    }

    private function passId(int $edgeId, int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM game_edge_pass WHERE edge_id = ? AND user_id = ?');
        $stmt->execute([$edgeId, $userId]);
        return (int)$stmt->fetchColumn();
    }

    private function auditCount(string $action): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM game_audit WHERE action = ?');
        $stmt->execute([$action]);
        return (int)$stmt->fetchColumn();
    }
}
