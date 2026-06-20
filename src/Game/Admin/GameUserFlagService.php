<?php
declare(strict_types=1);
namespace App\Game\Admin;

use PDO;

/** Spiel-Sperre (Ban/Unban) eines Users + Audit (Dashboard §5.6). */
final class GameUserFlagService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly GameAuditService $audit,
    ) {}

    public function ban(int $adminUserId, int $userId, string $reason): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_user_flag (user_id, banned, reason, updated_at)
             VALUES (?, 1, ?, NOW(3))
             ON DUPLICATE KEY UPDATE banned = 1, reason = VALUES(reason), updated_at = NOW(3)'
        )->execute([$userId, $reason]);
        $this->audit->record($adminUserId, 'user_game_ban', 'user:' . $userId, ['reason' => $reason]);
    }

    public function unban(int $adminUserId, int $userId): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_user_flag (user_id, banned, reason, updated_at)
             VALUES (?, 0, NULL, NOW(3))
             ON DUPLICATE KEY UPDATE banned = 0, updated_at = NOW(3)'
        )->execute([$userId]);
        $this->audit->record($adminUserId, 'user_game_unban', 'user:' . $userId, null);
    }
}
