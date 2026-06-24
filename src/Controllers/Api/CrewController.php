<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\Crew\CrewException;
use App\Game\Crew\CrewService;
use App\Http\Request;
use App\Http\Response;

/**
 * HTTP-Adapter für /game/crews (Spec §5, Stufe 2). Logik liegt in
 * CrewService; hier nur Parsing, Bearer-Pflicht und JSON/Fehler-Mapping.
 */
final class CrewController
{
    public function __construct(private readonly CrewService $crews) {}

    public function create(Request $req): void
    {
        $uid  = $this->userId($req);
        $name = (string)$req->input('name', '');
        $this->run(fn () => Response::json($this->crews->create($uid, $name), 201));
    }

    public function show(Request $req): void
    {
        $this->userId($req);
        $slug = trim((string)($req->routeParams['slug'] ?? ''));
        $profile = $this->crews->profile($slug);
        if ($profile === null) {
            Response::error('not_found', 'Crew nicht gefunden.', 404);
        }
        Response::json($profile);
    }

    public function join(Request $req): void
    {
        $uid  = $this->userId($req);
        $code = (string)$req->input('join_code', '');
        $this->run(fn () => Response::json($this->crews->join($uid, $code)));
    }

    public function leave(Request $req): void
    {
        $uid = $this->userId($req);
        $this->run(fn () => Response::json($this->crews->leave($uid)));
    }

    public function transfer(Request $req): void
    {
        $uid    = $this->userId($req);
        $target = (int)$req->input('user_id', 0);
        if ($target <= 0) {
            Response::error('validation_error', 'user_id erforderlich.', 422);
        }
        $this->run(fn () => Response::json($this->crews->transfer($uid, $target)));
    }

    public function me(Request $req): void
    {
        $uid = $this->userId($req);
        Response::json(['crew' => $this->crews->me($uid)]);
    }

    /** POST /game/crews/{slug}/captain — Notbesetzung (§12.3). */
    public function claimCaptain(Request $req): void
    {
        $uid    = $this->userId($req);
        $slug   = trim((string)($req->routeParams['slug'] ?? ''));
        $handle = (string)$req->input('user_handle', '');
        $this->run(fn () => Response::json($this->crews->claimCaptain($uid, $slug, $handle)));
    }

    public function leaderboard(Request $req): void
    {
        $uid  = $this->userId($req);
        $slug = trim((string)($req->routeParams['slug'] ?? ''));
        $this->run(fn () => Response::json($this->crews->leaderboard($slug, $uid)));
    }

    /**
     * Führt eine schreibende Service-Aktion aus und mappt CrewException auf
     * die einheitliche Fehlerantwort. Response::json/-error beenden den
     * Request (never).
     */
    private function run(callable $fn): void
    {
        try {
            $fn();
        } catch (CrewException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->status);
        }
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
