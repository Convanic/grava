<?php
declare(strict_types=1);

namespace App\Discovery;

use App\Database\Db;
use App\Engagement\NotificationService;
use PDO;

/**
 * M3 Phase 4: Follow-Beziehungen verwalten.
 *
 * Asymmetrisch (kein Approval-Workflow). Idempotent (Doppel-POST
 * ist OK). Self-Follow ist verboten (422).
 *
 * Block-Wechselwirkung: wer einen User folgen möchte, der ihn
 * blockiert hat, bekommt 404 — der User „existiert" für ihn nicht.
 * Wer selbst einen User blockiert hat, bekommt ebenfalls 404
 * (kein „blockiert + folgst trotzdem"-Edge-Case).
 */
final class FollowService
{
    public function __construct(
        private readonly ?NotificationService $notifications = null,
    ) {}

    public function follow(int $viewerUserId, int $targetUserId): bool
    {
        if ($viewerUserId === $targetUserId) {
            throw new SocialException('cannot_follow_self',
                'Du kannst dir nicht selbst folgen.', 422);
        }
        if ($this->isBlockedEither($viewerUserId, $targetUserId)) {
            throw new SocialException('not_found',
                'Profil existiert nicht.', 404);
        }

        $pdo = Db::pdo();
        try {
            $pdo->prepare(
                'INSERT INTO follows (follower_id, followee_id) VALUES (?, ?)'
            )->execute([$viewerUserId, $targetUserId]);
            // M4c: Notification an den Gefolgten (best effort).
            $this->notifications?->notify($targetUserId, $viewerUserId, 'follow', 'user', $viewerUserId);
            return true; // neu
        } catch (\PDOException $e) {
            // 1062 = Duplicate (PK-Verstoß) → bereits gefolgt
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }
    }

    public function unfollow(int $viewerUserId, int $targetUserId): void
    {
        // Kein Block-Check: wer geblockt wurde, soll trotzdem
        // saubere DB-Beziehungen aufräumen können.
        Db::pdo()->prepare(
            'DELETE FROM follows WHERE follower_id = ? AND followee_id = ?'
        )->execute([$viewerUserId, $targetUserId]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listFollowees(int $userId, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $stmt = Db::pdo()->prepare(
            "SELECT u.public_handle, u.display_name, f.created_at AS followed_at
               FROM follows f
               JOIN users u ON u.id = f.followee_id
              WHERE f.follower_id = ?
                AND u.public_handle IS NOT NULL
                AND u.status = 'active'
              ORDER BY f.created_at DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return self::shape($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listFollowers(int $userId, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $stmt = Db::pdo()->prepare(
            "SELECT u.public_handle, u.display_name, f.created_at AS followed_at
               FROM follows f
               JOIN users u ON u.id = f.follower_id
              WHERE f.followee_id = ?
                AND u.public_handle IS NOT NULL
                AND u.status = 'active'
              ORDER BY f.created_at DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return self::shape($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function isBlockedEither(int $a, int $b): bool
    {
        $stmt = Db::pdo()->prepare(
            'SELECT 1 FROM user_blocks
              WHERE (blocker_id = ? AND blocked_id = ?)
                 OR (blocker_id = ? AND blocked_id = ?)
              LIMIT 1'
        );
        $stmt->execute([$a, $b, $b, $a]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function shape(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'handle'       => (string)$r['public_handle'],
                'display_name' => $r['display_name'] === null ? null : (string)$r['display_name'],
                'followed_at'  => str_replace(' ', 'T', (string)$r['followed_at']) . 'Z',
            ];
        }
        return $out;
    }
}
