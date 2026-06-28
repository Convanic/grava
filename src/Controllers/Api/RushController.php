<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\Rush\RushException;
use App\Game\Rush\RushService;
use App\Http\Request;
use App\Http\Response;

/**
 * HTTP-Adapter für /game/crews/me/rush + /game/rush/* (GAME_RUSH_BACKEND.md §5).
 * Logik liegt in {@see RushService}; hier nur Parsing, Bearer-Pflicht und
 * JSON/Fehler-Mapping.
 */
final class RushController
{
    public function __construct(private readonly RushService $rush) {}

    /** POST /game/crews/me/rush */
    public function create(Request $req): void
    {
        $uid = $this->userId($req);
        $startAt = (string)$req->input('start_at', '');
        $windowHours = $req->input('window_hours');
        $note = $req->input('note');
        $this->run(fn () => Response::json($this->rush->create(
            $uid,
            $startAt,
            $windowHours !== null ? (int)$windowHours : null,
            $this->floatOrNull($req->input('meetup_lat')),
            $this->floatOrNull($req->input('meetup_lon')),
            null,
            $note !== null ? (string)$note : null,
        ), 201));
    }

    /** GET /game/crews/me/rush */
    public function myRush(Request $req): void
    {
        $uid = $this->userId($req);
        $detail = $this->rush->myRush($uid);
        if ($detail === null) {
            Response::noContent();
        }
        Response::json($detail);
    }

    /** POST /game/rush/{id}/rsvp */
    public function rsvp(Request $req): void
    {
        $uid = $this->userId($req);
        $id    = (int)($req->routeParams['id'] ?? 0);
        $state = (string)$req->input('state', '');
        $this->run(fn () => Response::json($this->rush->rsvp($uid, $id, $state)));
    }

    /** DELETE /game/rush/{id} */
    public function cancel(Request $req): void
    {
        $uid = $this->userId($req);
        $id  = (int)($req->routeParams['id'] ?? 0);
        $this->run(function () use ($uid, $id) {
            $this->rush->cancel($uid, $id);
            Response::noContent();
        });
    }

    private function run(callable $fn): void
    {
        try {
            $fn();
        } catch (RushException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->status);
        }
    }

    private function floatOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (float)$v;
    }

    private function userId(Request $req): int
    {
        $u = $req->user;
        $uid = $u !== null ? (int)($u->internal_id ?? 0) : 0;
        if ($uid <= 0) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }
        return $uid;
    }
}
