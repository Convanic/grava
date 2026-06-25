<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use App\Presence\PresenceService;

/**
 * Live-Aktiv-Zähler (PRESENCE_BACKEND.md):
 *   POST /presence/heartbeat  OptionalBearer
 *   POST /presence/stop       OptionalBearer
 *   GET  /presence/active     öffentlich, cachebar
 */
final class PresenceController
{
    public function __construct(private readonly PresenceService $presence) {}

    public function heartbeat(Request $req): void
    {
        Response::json($this->presence->heartbeat($this->userId($req), $this->sessionId($req)));
    }

    public function stop(Request $req): void
    {
        Response::json($this->presence->stop($this->userId($req), $this->sessionId($req)));
    }

    public function active(Request $req): void
    {
        Response::json(
            $this->presence->active(),
            200,
            ['Cache-Control' => 'public, max-age=15'],
        );
    }

    private function userId(Request $req): ?int
    {
        if ($req->user === null) {
            return null;
        }
        $uid = (int)($req->user->internal_id ?? 0);
        return $uid > 0 ? $uid : null;
    }

    private function sessionId(Request $req): ?string
    {
        if (!isset($req->json['session_id'])) {
            return null;
        }
        $sid = trim((string)$req->json['session_id']);
        return $sid === '' ? null : $sid;
    }
}
