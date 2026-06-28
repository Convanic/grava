<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\Faction\FactionException;
use App\Game\Faction\FactionService;
use App\Http\Request;
use App\Http\Response;

/**
 * HTTP-Adapter für Fraktionen (Stufe 3, GAME_STAGE3_BACKEND.md):
 *  - POST/DELETE /game/crews/{slug}/faction (Captain, Bearer)
 *  - GET /game/factions/map?bbox= (Meta-Karte)
 *  - GET /game/factions (Standings, öffentlich)
 *
 * Logik liegt in FactionService; hier nur Parsing + JSON/Fehler-Mapping.
 */
final class FactionController
{
    public function __construct(private readonly FactionService $factions) {}

    public function set(Request $req): void
    {
        $uid  = $this->userId($req);
        $slug = trim((string)($req->routeParams['slug'] ?? ''));
        $key  = (string)$req->input('faction_key', '');
        $this->run(fn () => Response::json($this->factions->setFaction($uid, $slug, $key)));
    }

    public function clear(Request $req): void
    {
        $uid  = $this->userId($req);
        $slug = trim((string)($req->routeParams['slug'] ?? ''));
        $this->run(fn () => Response::json($this->factions->clearFaction($uid, $slug)));
    }

    public function map(Request $req): void
    {
        $bbox = (string)($req->query['bbox'] ?? '');
        $parts = array_map('trim', explode(',', $bbox));
        if (count($parts) !== 4 || !self::allNumeric($parts)) {
            Response::error('bad_request', 'bbox erforderlich (minLon,minLat,maxLon,maxLat).', 400);
        }
        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $parts);
        // Optionale gröbere Gitterweite (Zoom-abhängig) — sonst Config-Default.
        $grid = \App\Support\MapLod::gridFromQuery($req->query);
        Response::json($this->factions->map($minLon, $minLat, $maxLon, $maxLat, $grid));
    }

    public function standings(Request $req): void
    {
        Response::json($this->factions->standings());
    }

    /** @param list<string> $parts */
    private static function allNumeric(array $parts): bool
    {
        foreach ($parts as $p) {
            if (!is_numeric($p)) {
                return false;
            }
        }
        return true;
    }

    private function run(callable $fn): void
    {
        try {
            $fn();
        } catch (FactionException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->status, $e->fields);
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
