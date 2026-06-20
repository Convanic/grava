<?php
declare(strict_types=1);
namespace App\Game\Admin;

use App\Game\EdgeRecalculator;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Invalidiert/reaktiviert einzelne Pässe und rechnet die betroffene Kante neu (Dashboard §5.5). */
final class GamePassAdminService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly GameRepository $repo,
        private readonly EdgeRecalculator $recalc,
        private readonly GameAuditService $audit,
    ) {}

    public function invalidate(int $adminUserId, int $passId, string $reason, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $edgeId = $this->edgeIdForPass($passId);
        if ($edgeId === null) {
            return false;
        }
        $this->pdo->prepare(
            'UPDATE game_edge_pass
                SET invalidated_at = ?, invalidated_by = ?, invalid_reason = ?
              WHERE id = ? AND invalidated_at IS NULL'
        )->execute([$now->format('Y-m-d H:i:s.v'), $adminUserId, $reason, $passId]);
        $this->repo->refreshEdgeDiscovery($edgeId);
        $this->recalc->recalculate($edgeId, $now);
        $this->audit->record($adminUserId, 'pass_invalidate', 'pass:' . $passId, ['edge_id' => $edgeId, 'reason' => $reason]);
        return true;
    }

    public function reactivate(int $adminUserId, int $passId, ?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $edgeId = $this->edgeIdForPass($passId);
        if ($edgeId === null) {
            return false;
        }
        $this->pdo->prepare(
            'UPDATE game_edge_pass
                SET invalidated_at = NULL, invalidated_by = NULL, invalid_reason = NULL
              WHERE id = ?'
        )->execute([$passId]);
        $this->repo->refreshEdgeDiscovery($edgeId);
        $this->recalc->recalculate($edgeId, $now);
        $this->audit->record($adminUserId, 'pass_reactivate', 'pass:' . $passId, ['edge_id' => $edgeId]);
        return true;
    }

    private function edgeIdForPass(int $passId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT edge_id FROM game_edge_pass WHERE id = ?');
        $stmt->execute([$passId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int)$v;
    }
}
