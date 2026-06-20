<?php
declare(strict_types=1);
namespace App\Game\Admin;

use PDO;

/** Audit-Log für alle schreibenden Admin-Aktionen. */
final class GameAuditService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @param array<string,mixed>|null $detail */
    public function record(int $adminUserId, string $action, ?string $target = null, ?array $detail = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_audit (admin_user_id, action, target, detail_json) VALUES (?, ?, ?, ?)'
        )->execute([
            $adminUserId,
            $action,
            $target,
            $detail !== null ? json_encode($detail, JSON_THROW_ON_ERROR) : null,
        ]);
    }

    /** @return list<array<string,mixed>> letzte N Audit-Zeilen (neueste zuerst), detail_json dekodiert. */
    public function recent(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, admin_user_id, action, target, detail_json, created_at
               FROM game_audit ORDER BY id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['detail'] = $r['detail_json'] !== null ? json_decode((string)$r['detail_json'], true) : null;
            $out[] = $r;
        }
        return $out;
    }
}
