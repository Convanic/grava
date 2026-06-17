<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Engagement\NotificationService;
use App\Http\Request;
use App\Http\Response;

/**
 * M4c: Notifications-Inbox (alle auth-required).
 *
 *   GET  /api/v1/notifications              paginiert
 *   GET  /api/v1/notifications/unread-count {count}
 *   POST /api/v1/notifications/read         markiert alle (oder {ids:[]})
 *   POST /api/v1/notifications/{nid}/read   markiert eine
 */
final class NotificationController
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function list(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        Response::json($this->notifications->list(
            $userId,
            (int)($req->query['limit']  ?? 20),
            (int)($req->query['offset'] ?? 0),
        ));
    }

    public function unreadCount(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        Response::json(['count' => $this->notifications->unreadCount($userId)]);
    }

    public function markAll(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);

        // Optional gezielte IDs: {"ids":[1,2,3]}. Ohne ids → alle.
        $ids = $req->json['ids'] ?? null;
        if (is_array($ids) && $ids !== []) {
            $marked = 0;
            foreach ($ids as $id) {
                if ($this->notifications->markRead($userId, (int)$id)) {
                    $marked++;
                }
            }
            Response::json(['marked' => $marked]);
        }

        Response::json(['marked' => $this->notifications->markAllRead($userId)]);
    }

    public function markOne(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        $nid    = (int)($req->routeParams['nid'] ?? 0);
        $this->notifications->markRead($userId, $nid);
        Response::noContent();
    }
}
