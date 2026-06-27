<?php
declare(strict_types=1);

namespace App\Engagement;

use App\Database\Db;
use PDO;

/**
 * M4b: Kommentare auf Routen.
 *
 * Flach (kein Threading). Privacy-/Block-aware über
 * {@see RouteVisibility}: kommentieren darf nur, wer die Route
 * sehen darf. Soft-Delete; löschen darf Autor ODER Routen-Owner.
 *
 * Body 1..2000 Zeichen Plaintext; Validierung hier, Escaping beim
 * Rendern in der View / JSON-Encoding.
 */
final class CommentService
{
    public const MAX_LEN = 2000;

    public function __construct(
        private readonly ?NotificationService $notifications = null,
    ) {}

    /**
     * @return array<string,mixed> die angelegte Kommentar-Form (inkl. id)
     */
    public function create(string $routePublicId, int $viewerUserId, string $body): array
    {
        $body = trim($body);
        $len = mb_strlen($body);
        if ($len < 1) {
            throw new EngagementException('comment_empty', 'Kommentar darf nicht leer sein.', 422);
        }
        if ($len > self::MAX_LEN) {
            throw new EngagementException('comment_too_long',
                'Kommentar darf höchstens ' . self::MAX_LEN . ' Zeichen haben.', 422);
        }

        $route = RouteVisibility::resolveVisibleOrThrow($routePublicId, $viewerUserId);

        $pdo = Db::pdo();
        $pdo->prepare(
            'INSERT INTO route_comments (route_id, user_id, body) VALUES (?, ?, ?)'
        )->execute([$route['route_id'], $viewerUserId, $body]);
        $id = (int)$pdo->lastInsertId();

        // M4c: Notification an den Routen-Owner — strikt best effort.
        // Ein Fehler im Notification-/Push-Pfad darf das Kommentieren
        // nicht scheitern lassen (der Kommentar steht bereits).
        try {
            $this->notifications?->notify($route['owner_id'], $viewerUserId, 'comment', 'route', $route['route_id']);
        } catch (\Throwable $e) {
            error_log('CommentService::create notify failed: ' . $e->getMessage());
        }

        return $this->loadOne($id);
    }

    /**
     * Listet nicht-gelöschte Kommentare einer (sichtbaren) Route,
     * neueste zuerst, paginiert.
     *
     * @return array{
     *   comments: list<array<string,mixed>>,
     *   pagination: array{limit:int, offset:int, total:int, has_more:bool}
     * }
     */
    public function list(string $routePublicId, ?int $viewerUserId, int $limit = 20, int $offset = 0): array
    {
        $route = RouteVisibility::resolveVisibleOrThrow($routePublicId, $viewerUserId);
        $routeId = $route['route_id'];
        $limit  = max(1, min(50, $limit));
        $offset = max(0, $offset);

        $pdo = Db::pdo();
        $total = (int)$pdo->query(
            "SELECT COUNT(*) FROM route_comments
              WHERE route_id = {$routeId} AND deleted_at IS NULL"
        )->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT c.id, c.body, c.created_at, c.user_id,
                    u.public_handle, u.display_name
               FROM route_comments c
               JOIN users u ON u.id = c.user_id
              WHERE c.route_id = ? AND c.deleted_at IS NULL
              ORDER BY c.created_at DESC
              LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $routeId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit,  PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $comments = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $comments[] = $this->shape($row, $viewerUserId, $route['owner_id']);
        }

        return [
            'comments'   => $comments,
            'pagination' => [
                'limit'    => $limit,
                'offset'   => $offset,
                'total'    => $total,
                'has_more' => ($offset + $limit) < $total,
            ],
        ];
    }

    /**
     * Soft-Delete. Erlaubt für Autor ODER Routen-Owner. Andernfalls
     * 403; unbekannter/bereits gelöschter Kommentar → 404.
     */
    public function delete(string $routePublicId, int $commentId, int $viewerUserId): void
    {
        $route = RouteVisibility::resolveVisibleOrThrow($routePublicId, $viewerUserId);
        $pdo = Db::pdo();

        $stmt = $pdo->prepare(
            'SELECT user_id FROM route_comments
              WHERE id = ? AND route_id = ? AND deleted_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$commentId, $route['route_id']]);
        $authorId = $stmt->fetchColumn();
        if ($authorId === false) {
            throw new EngagementException('not_found', 'Kommentar existiert nicht.', 404);
        }

        $isAuthor = (int)$authorId === $viewerUserId;
        $isOwner  = $route['owner_id'] === $viewerUserId;
        if (!$isAuthor && !$isOwner) {
            throw new EngagementException('forbidden',
                'Du darfst diesen Kommentar nicht löschen.', 403);
        }

        $pdo->prepare(
            'UPDATE route_comments SET deleted_at = CURRENT_TIMESTAMP(3) WHERE id = ?'
        )->execute([$commentId]);
    }

    private function loadOne(int $id): array
    {
        $stmt = Db::pdo()->prepare(
            "SELECT c.id, c.body, c.created_at, c.user_id,
                    u.public_handle, u.display_name
               FROM route_comments c
               JOIN users u ON u.id = c.user_id
              WHERE c.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $this->shape($row, (int)$row['user_id'], (int)$row['user_id']);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shape(array $row, ?int $viewerUserId, int $ownerId): array
    {
        $authorId = (int)$row['user_id'];
        return [
            'id'           => (int)$row['id'],
            'body'         => (string)$row['body'],
            'created_at'   => str_replace(' ', 'T', (string)$row['created_at']) . 'Z',
            'author'       => [
                'handle'       => $row['public_handle'] === null ? null : (string)$row['public_handle'],
                'display_name' => $row['display_name'] === null ? null : (string)$row['display_name'],
            ],
            // Darf der aktuelle Viewer diesen Kommentar löschen?
            'can_delete'   => $viewerUserId !== null
                && ($viewerUserId === $authorId || $viewerUserId === $ownerId),
        ];
    }
}
