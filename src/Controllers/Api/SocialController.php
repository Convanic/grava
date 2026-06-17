<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Database\Db;
use App\Discovery\BlockService;
use App\Discovery\FollowService;
use App\Discovery\SocialException;
use App\Http\Request;
use App\Http\Response;

/**
 * M3 Phase 4: Follow-/Block-Endpoints + me-Listings.
 *
 * Alle Endpoints sind auth-required. Target-User wird per Handle
 * resolved (`/users/by-handle/{handle}/follow`); existiert kein User
 * mit dem Handle, antwortet jeder Endpoint mit 404 — wie bei
 * /users/by-handle/{handle}.
 *
 * Wichtig: Wer einen User blockiert hat oder von ihm blockiert ist,
 * sieht ihn als „nicht existent" — ein POST /follow zu einem
 * blockierten Target liefert auch 404, kein 403, damit Block-
 * Beziehungen nicht aus dem Status-Code ablesbar sind.
 */
final class SocialController
{
    public function __construct(
        private readonly FollowService $follow,
        private readonly BlockService $block,
    ) {}

    public function follow(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        $target = $this->resolveTarget((string)($req->routeParams['handle'] ?? ''));
        try {
            $isNew = $this->follow->follow($viewer, $target);
        } catch (SocialException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::json(['ok' => true], $isNew ? 201 : 200);
    }

    public function unfollow(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        $target = $this->resolveTarget((string)($req->routeParams['handle'] ?? ''));
        $this->follow->unfollow($viewer, $target);
        Response::noContent();
    }

    public function block(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        // Block muss auch funktionieren, wenn der Target schon den
        // Viewer blockt — sonst „lockt" der schnellere Block den
        // anderen aus. Daher resolveTarget OHNE Block-Check hier.
        $target = $this->resolveTargetRaw((string)($req->routeParams['handle'] ?? ''));
        try {
            $isNew = $this->block->block($viewer, $target);
        } catch (SocialException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::json(['ok' => true], $isNew ? 201 : 200);
    }

    public function unblock(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        $target = $this->resolveTargetRaw((string)($req->routeParams['handle'] ?? ''));
        $this->block->unblock($viewer, $target);
        Response::noContent();
    }

    public function meFollows(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        Response::json([
            'users' => $this->follow->listFollowees(
                $viewer,
                (int)($req->query['limit']  ?? 50),
                (int)($req->query['offset'] ?? 0),
            ),
        ]);
    }

    public function meFollowers(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        Response::json([
            'users' => $this->follow->listFollowers(
                $viewer,
                (int)($req->query['limit']  ?? 50),
                (int)($req->query['offset'] ?? 0),
            ),
        ]);
    }

    public function meBlocks(Request $req): void
    {
        $viewer = (int)($req->user->internal_id ?? 0);
        Response::json([
            'users' => $this->block->listBlocks(
                $viewer,
                (int)($req->query['limit']  ?? 50),
                (int)($req->query['offset'] ?? 0),
            ),
        ]);
    }

    /**
     * Looks up the user by handle. 404 wenn unbekannt oder kein
     * aktiver User. Diese Variante ist für Follow-Operationen
     * (wo wir Block-Verhältnisse durch FollowService prüfen lassen).
     */
    private function resolveTarget(string $handle): int
    {
        return $this->resolveTargetRaw($handle);
    }

    private function resolveTargetRaw(string $handle): int
    {
        if ($handle === '' || preg_match('/^[a-z0-9_]{3,30}$/', $handle) !== 1) {
            Response::error('not_found', 'Profil existiert nicht.', 404);
        }
        $stmt = Db::pdo()->prepare(
            "SELECT id FROM users
              WHERE public_handle = ? AND status = 'active'
              LIMIT 1"
        );
        $stmt->execute([$handle]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            Response::error('not_found', 'Profil existiert nicht.', 404);
        }
        return (int)$id;
    }
}
