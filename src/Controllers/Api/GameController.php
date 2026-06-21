<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameReadService;
use App\Game\GameRepository;
use App\Http\Request;
use App\Http\Response;
use App\Routes\GeometryParser;
use App\Routes\RouteService;

/**
 * HTTP-Adapter für die /game-Endpunkte (Spec §6). Logik liegt in
 * GameReadService / GameIngestionService; hier nur Parsing + JSON.
 */
final class GameController
{
    public function __construct(
        private readonly GameReadService $read,
        private readonly GameRepository $repo,
        private readonly GameIngestionService $ingest,
        private readonly GameConfig $config,
        private readonly RouteService $routes,
        private readonly GeometryParser $parser,
    ) {}

    public function edges(Request $req): void
    {
        $bbox = (string)($req->query['bbox'] ?? '');
        if ($bbox === '') {
            Response::error('bad_request', 'bbox erforderlich (minLon,minLat,maxLon,maxLat).', 400);
        }
        $viewer = $this->viewerClaimant($req);
        $onlyMine = (string)($req->query['mine'] ?? '') === '1';
        try {
            $edges = $this->read->edgesInBbox($bbox, $viewer, null, 1000);
        } catch (\InvalidArgumentException $e) {
            Response::error('bad_request', $e->getMessage(), 400);
        }
        if ($onlyMine && $viewer !== null) {
            $edges = array_values(array_filter(
                $edges,
                static fn($e) => $e['owner'] !== null && $e['owner']['claimant_id'] === $viewer,
            ));
        }
        Response::json(['edges' => $edges]);
    }

    public function edge(Request $req): void
    {
        $id = (int)($req->routeParams['id'] ?? 0);
        $detail = $this->read->edgeDetail($id, $this->viewerClaimant($req), null);
        if ($detail === null) {
            Response::error('not_found', 'Kante nicht gefunden.', 404);
        }
        Response::json($detail);
    }

    public function me(Request $req): void
    {
        $uid = $this->userId($req);
        $claimant = $this->repo->riderClaimantId($uid);
        Response::json($this->read->me($claimant));
    }

    public function config(Request $req): void
    {
        $this->userId($req); // Bearer erzwungen
        Response::json(['config' => $this->config->all()]);
    }

    public function reingest(Request $req): void
    {
        $uid = $this->userId($req);
        // iOS kennt nur die öffentliche Route-ID (UUID) — konsistent mit
        // GET /routes/{id} & Co. resolveRouteForIngest() akzeptiert UUID
        // ODER (rückwärtskompatibel) die interne numerische ID.
        $routeRef = trim((string)($req->routeParams['route_id'] ?? ''));
        $route = $this->repo->resolveRouteForIngest($routeRef);
        if ($route === null) {
            Response::error('not_found', 'Route nicht gefunden.', 404);
        }
        if ($route['user_id'] !== $uid) {
            Response::error('forbidden', 'Nur der Eigentümer darf re-ingestieren.', 403);
        }
        $loaded = $this->routes->loadPayloadByPublicId($route['public_id']);
        $parsed = $this->parser->parse($loaded['payload']);
        $summary = $this->ingest->ingest((int)$route['route_id'], $uid, $parsed, $parsed->startedAt !== null, null);
        Response::json($summary);
    }

    private function viewerClaimant(Request $req): ?int
    {
        $u = $req->user;
        if ($u === null) {
            return null;
        }
        $uid = (int)($u->internal_id ?? 0);
        // Stufe 2: effektiver Claimant (Crew, wenn Mitglied, sonst Rider) —
        // konsistent mit dem Besitz, den der EdgeRecalculator über den
        // effektiven Claimant berechnet. Sonst gälten nach Crew-Beitritt die
        // eigenen (jetzt crew-eigenen) Kanten als fremd: owner_is_me=false und
        // der mine=1-Filter würde sie ausblenden.
        return $uid > 0 ? $this->repo->effectiveClaimantId($uid) : null;
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
