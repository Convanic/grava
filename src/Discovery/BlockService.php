<?php
declare(strict_types=1);

namespace App\Discovery;

use App\Database\Db;
use PDO;

/**
 * M3 Phase 4: Block-Beziehungen verwalten.
 *
 * Asymmetrisches DB-Modell, **bidirektionales Verhalten**: ein
 * einziger user_blocks-Eintrag (A blockt B) blendet beide User
 * gegenseitig aus Discovery, Profile und Feed (siehe
 * DiscoveryService::blockedUserIds, ProfileService::isBlocked).
 *
 * Beim Anlegen eines Blocks werden bestehende Follow-Beziehungen
 * in beide Richtungen hart entfernt — sonst hätten wir den
 * absurden Zustand „X folgt Y, kann Y aber nicht sehen". Das
 * passiert in einer Transaktion zusammen mit dem INSERT.
 */
final class BlockService
{
    /** @return bool true = neuer Block, false = bereits geblockt */
    public function block(int $viewerUserId, int $targetUserId): bool
    {
        if ($viewerUserId === $targetUserId) {
            throw new SocialException('cannot_block_self',
                'Du kannst dich nicht selbst blockieren.', 422);
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $isNew = true;
            try {
                $pdo->prepare(
                    'INSERT INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?)'
                )->execute([$viewerUserId, $targetUserId]);
            } catch (\PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $isNew = false;
                } else {
                    throw $e;
                }
            }

            // Cascade-Cleanup: Follow-Beziehungen in beide Richtungen
            // entfernen. Idempotent — wenn keine Zeile existiert,
            // passiert hier nichts.
            $del = $pdo->prepare(
                'DELETE FROM follows
                  WHERE (follower_id = ? AND followee_id = ?)
                     OR (follower_id = ? AND followee_id = ?)'
            );
            $del->execute([$viewerUserId, $targetUserId, $targetUserId, $viewerUserId]);

            $pdo->commit();
            return $isNew;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function unblock(int $viewerUserId, int $targetUserId): void
    {
        Db::pdo()->prepare(
            'DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?'
        )->execute([$viewerUserId, $targetUserId]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listBlocks(int $userId, int $limit, int $offset): array
    {
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        // Wir listen NUR die Richtung „Du hast diese User blockiert".
        // Die andere Richtung („dich haben diese User blockiert") ist
        // bewusst nicht abrufbar — sonst könnte ein User Block-Listen
        // als Stalking-Tool nutzen. Discovery filtert diese aber
        // implizit, indem sie unsichtbar werden.
        $stmt = Db::pdo()->prepare(
            "SELECT u.public_handle, u.display_name, ub.created_at AS blocked_at
               FROM user_blocks ub
               JOIN users u ON u.id = ub.blocked_id
              WHERE ub.blocker_id = ?
              ORDER BY ub.created_at DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                // Wenn der geblockte User später seinen Handle entfernt
                // hätte (technisch via Support-Mail), könnte dieses Feld
                // null sein. Wir behalten den User-Datensatz trotzdem
                // sichtbar in der Block-Liste — sonst wüsste der
                // Blocker nicht mehr, wen er da blockiert hat.
                'handle'       => $r['public_handle'] === null ? null : (string)$r['public_handle'],
                'display_name' => $r['display_name'] === null ? null : (string)$r['display_name'],
                'blocked_at'   => str_replace(' ', 'T', (string)$r['blocked_at']) . 'Z',
            ];
        }
        return $out;
    }
}
