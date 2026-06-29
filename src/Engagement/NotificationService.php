<?php
declare(strict_types=1);

namespace App\Engagement;

use App\Database\Db;
use App\Push\PushService;
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
    public function __construct(
        private readonly ?PushService $push = null,
        private readonly ?NotificationPreferenceRepository $prefs = null,
    ) {}

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
        $notificationId = 0;
        try {
            $pdo = Db::pdo();
            $pdo->prepare(
                'INSERT INTO notifications (user_id, actor_id, type, subject_type, subject_id)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$recipientId, $actorId, $type, $subjectType, $subjectId]);
            $notificationId = (int)$pdo->lastInsertId();
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), '1146')) {
                throw $e;
            }
            return; // Tabelle fehlt → kein Push
        }

        // Push (best effort) — Fehler dürfen die Aktion nie scheitern lassen.
        // Per-Typ-Schalter (S9): ist der Typ beim Empfänger ausgeschaltet, wird
        // KEINE Push versendet. Der In-App-Eintrag oben bleibt davon unberührt.
        if ($this->push !== null && $notificationId > 0
            && ($this->prefs === null || $this->prefs->isPushEnabled($recipientId, $type))) {
            $this->push->dispatch($notificationId, $recipientId, $actorId, $type, $subjectType, $subjectId);
        }
    }

    /**
     * Spiel-Mitteilung (GAME_PUSH_BACKEND.md) mit Kanten-Deep-Link (`edgeId`)
     * und optionaler Bündelung (`count` > 1 ⇒ Digest). Der Auslöser ist
     * optional: bei Digest ist `actorId = null` (kein einzelner Auslöser) —
     * dann entfallen Self-/Block-Filter. Die Inbox-Zeile entsteht immer; der
     * Push hängt am per-Typ-Schalter (Pref aus ⇒ Inbox ja, Push nein).
     */
    public function notifyGame(
        int $recipientId,
        ?int $actorId,
        string $type,
        ?int $edgeId = null,
        ?int $count = null,
    ): void {
        if ($actorId !== null) {
            if ($recipientId === $actorId) {
                return; // keine Self-Notification
            }
            if (RouteVisibility::isBlockedEither($recipientId, $actorId)) {
                return;
            }
        }

        $notificationId = 0;
        try {
            $pdo = Db::pdo();
            $pdo->prepare(
                'INSERT INTO notifications (user_id, actor_id, type, edge_id, `count`)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$recipientId, $actorId, $type, $edgeId, $count]);
            $notificationId = (int)$pdo->lastInsertId();
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), '1146')) {
                throw $e;
            }
            return;
        }

        if ($this->push !== null && $notificationId > 0
            && ($this->prefs === null || $this->prefs->isPushEnabled($recipientId, $type))) {
            $this->push->dispatch($notificationId, $recipientId, $actorId, $type, null, null, $edgeId, $count);
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
        // LEFT JOIN auf den Actor: Digest-Spiel-Mitteilungen haben keinen
        // Auslöser (actor_id = NULL) — ein INNER JOIN würde sie ausblenden.
        $stmt = $pdo->prepare(
            "SELECT n.id, n.type, n.subject_type, n.subject_id, n.actor_id,
                    n.edge_id, n.`count`,
                    n.created_at, n.read_at,
                    a.public_handle AS actor_handle, a.display_name AS actor_name,
                    r.public_id AS route_public_id, r.title AS route_title,
                    r.deleted_at AS route_deleted_at
               FROM notifications n
               LEFT JOIN users a ON a.id = n.actor_id
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
            // Digest (actor_id NULL) ⇒ actor=null (Spec); sonst Actor-Objekt.
            $actor = $row['actor_id'] === null ? null : [
                'handle'       => $row['actor_handle'] === null ? null : (string)$row['actor_handle'],
                'display_name' => $row['actor_name'] === null ? null : (string)$row['actor_name'],
            ];
            $item = [
                'id'         => (int)$row['id'],
                'type'       => (string)$row['type'],
                'created_at' => str_replace(' ', 'T', (string)$row['created_at']) . 'Z',
                'read'       => $row['read_at'] !== null,
                'actor'      => $actor,
                'route'      => $route,
            ];
            // edge_id/count additiv — nur wenn gesetzt (Deep-Link/Digest).
            if ($row['edge_id'] !== null) {
                $item['edge_id'] = (int)$row['edge_id'];
            }
            if ($row['count'] !== null) {
                $item['count'] = (int)$row['count'];
            }
            $items[] = $item;
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
