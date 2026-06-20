<?php
declare(strict_types=1);

namespace Tests\Integration\Game\Admin;

use App\Game\Admin\GameAuditService;
use App\Game\Admin\GameUserFlagService;
use App\Game\GameRepository;
use Tests\IntegrationTestCase;

final class GameUserFlagServiceTest extends IntegrationTestCase
{
    public function testBanAndUnbanToggleFlagWithAudit(): void
    {
        $userId = $this->createUser('cheater');
        $svc = new GameUserFlagService($this->pdo, new GameAuditService($this->pdo));
        $repo = new GameRepository($this->pdo);

        $svc->ban(7, $userId, 'cheating');
        $this->assertTrue($repo->isUserBanned($userId));
        $this->assertSame(1, $this->auditCount('user_game_ban'));

        $svc->unban(7, $userId);
        $this->assertFalse($repo->isUserBanned($userId));
        $this->assertSame(1, $this->auditCount('user_game_unban'));
    }

    private function auditCount(string $action): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM game_audit WHERE action = ?');
        $stmt->execute([$action]);
        return (int)$stmt->fetchColumn();
    }
}
