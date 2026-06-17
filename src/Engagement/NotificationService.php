<?php
declare(strict_types=1);

namespace App\Engagement;

use App\Database\Db;
use PDO;

/**
 * M4c: Notifications.
 *
 * Pull-Modell. `notify()` wird synchron aus FollowService /
 * LikeService / CommentService aufgerufen und legt einen Eintrag
 * für den Empfänger an — es sei denn:
 *  - Empfänger == Auslöser (keine Self-Notification), oder
 *  - zwischen beiden besteht ein Block (egal welche Richtung).
 *
 * notify() ist absichtlich „best effort": ein Fehler beim
 * Notification-Insert darf die auslösende Aktion (Follow/Like/
 * Comment) nicht scheitern lassen. Deshalb fängt der Aufrufer den
 * Notification-Call in einem try/catch — und auch hier schlucken
 * wir fehlende Tabelle (1146) defensiv.
 */
final class NotificationService
{
    public function notify(
        int $recipientId,
        int $actorId,
        string $type,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): void {
        if ($recipientId === $actorId) {
            return; // keine Self-Notification
        }
        if (RouteVisibility::isBlockedEither($recipientId, $actorId)) {
            return; // geblockt → keine Notification
        }
        try {
            Db::pdo()->prepare(
                'INSERT INTO notifications (user_id, actor_id, type, subject_type, subject_id)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$recipientId, $actorId, $type, $subjectType, $subjectId]);
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), '1146')) {
                throw $e;
            }
        }
    }

    /**
     * @return array{
     *   notifications: list<array<string,mixed>>,
     *   pagination: array{limit:int, offset:int, total:int, has_more:bool}
     * }
     */
    public function list(int $userId, int $limit = 20, int $offset = 0): array
    {
        $limit  = max(1, min(50, $limit));
        $offset = max(0, $offset);
        $pdo = Db::pdo();

        $total = (int)$pdo->query(
            "SELECT COUNT(*) FROM notifications WHERE user_id = {$userId}"
        )->fetchColumn();

        // Actor-Daten + (bei route-Subject) Routen-Titel/public_id
        // per LEFT JOIN. Verwaiste/gelöschte Routen liefern NULL und
        // werden in der Shape als route=null abgebildet.
        $stmt = $pdo->prepare(
            "SELECT n.id, n.type, n.subject_type, n.subject_id,
                    n.created_at, n.read_at,
                    a.public_handle AS actor_handle, a.display_name AS actor_name,
                    r.public_id AS route_public_id, r.title AS route_title,
                    r.deleted_at AS route_deleted_at
               FROM notifications n
               JOIN users a ON a.id = n.actor_id
               LEFT JOIN routes r
                      ON n.subject_type = 'route' AND r.id = n.subject_id
              WHERE n.user_id = ?
              ORDER BY n.created_at DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $route = null;
            if ($row['route_public_id'] !== null && $row['route_deleted_at'] === null) {
                $route = [
                    'id'    => (string)$row['route_public_id'],
                    'title' => (string)$row['route_title'],
                ];
            }
            $items[] = [
                'id'         => (int)$row['id'],
                'type'       => (string)$row['type'],
                'created_at' => str_replace(' ', 'T', (string)$row['created_at']) . 'Z',
                'read'       => $row['read_at'] !== null,
                'actor'      => [
                    'handle'       => $row['actor_handle'] === null ? null : (string)$row['actor_handle'],
                    'display_name' => $row['actor_name'] === null ? null : (string)$row['actor_name'],
                ],
                'route'      => $route,
            ];
        }

        return [
            'notifications' => $items,
            'pagination'    => [
                'limit'    => $limit,
                'offset'   => $offset,
                'total'    => $total,
                'has_more' => ($offset + $limit) < $total,
            ],
        ];
    }

    public function unreadCount(int $userId): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Markiert alle ungelesenen Notifications des Users als gelesen.
     * Liefert die Anzahl der betroffenen Zeilen.
     */
    public function markAllRead(int $userId): int
    {
        $stmt = Db::pdo()->prepare(
            'UPDATE notifications SET read_at = CURRENT_TIMESTAMP(3)
              WHERE user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    /**
     * Markiert eine einzelne Notification als gelesen (nur eigene).
     * Liefert true, wenn eine ungelesene Zeile aktualisiert wurde.
     */
    public function markRead(int $userId, int $notificationId): bool
    {
        $stmt = Db::pdo()->prepare(
            'UPDATE notifications SET read_at = CURRENT_TIMESTAMP(3)
              WHERE id = ? AND user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$notificationId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Cleanup für den Cron: entfernt gelesene Notifications, die älter
     * als $days Tage sind. Liefert die Anzahl gelöschter Zeilen.
     */
    public function purgeOldRead(int $days): int
    {
        $days = max(0, $days);
        $stmt = Db::pdo()->prepare(
            'DELETE FROM notifications
              WHERE read_at IS NOT NULL
                AND read_at <= (UTC_TIMESTAMP() - INTERVAL ? DAY)'
        );
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }
}
