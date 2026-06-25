<?php
declare(strict_types=1);

namespace App\Presence;

use PDO;

final class PresenceRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function upsert(string $identity, string $lastSeen): void
    {
        $this->pdo->prepare(
            'INSERT INTO presence_active (identity, last_seen)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)'
        )->execute([$identity, $lastSeen]);
    }

    public function delete(string $identity): void
    {
        $this->pdo->prepare('DELETE FROM presence_active WHERE identity = ?')
            ->execute([$identity]);
    }

    public function findLastSeen(string $identity): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT last_seen FROM presence_active WHERE identity = ? LIMIT 1'
        );
        $stmt->execute([$identity]);
        $row = $stmt->fetchColumn();
        return is_string($row) ? $row : null;
    }

    public function countActive(int $ttlSeconds, bool $countAnonymous): int
    {
        if ($countAnonymous) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM presence_active
                  WHERE last_seen > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ? SECOND)'
            );
            $stmt->execute([$ttlSeconds]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM presence_active
                  WHERE last_seen > DATE_SUB(UTC_TIMESTAMP(3), INTERVAL ? SECOND)
                    AND identity LIKE ?'
            );
            $stmt->execute([$ttlSeconds, 'u:%']);
        }
        return (int)$stmt->fetchColumn();
    }
}
