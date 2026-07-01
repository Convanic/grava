<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\GameConfig;
use App\Game\GameEdgesAtRiskService;
use App\Game\GameIngestionService;
use App\Game\GameReadService;
use App\Game\GameRepository;
use App\Game\GameRideSummaryService;
use App\Game\PlayerProgressionService;
use App\Game\Challenges\ChallengeService;
use App\Game\MatchUnavailableException;
use App\Game\RideSummaryNotIngestedException;
use App\Http\Request;
use App\Http\Response;
use App\Routes\GeometryParseException;
use App\Routes\GeometryParser;
use App\Routes\RadarTrafficData;
use App\Routes\RadarTrafficParser;
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
        private readonly GameRideSummaryService $rideSummary,
        private readonly GameEdgesAtRiskService $atRisk,
        private readonly ?ChallengeService $challenges = null,
    ) {}

    /**
     * GET /game/challenges (Bearer) — aktive Aufgaben mit Fortschritt,
     * Belohnung und Countdown (GAME_CHALLENGES_BACKEND.md). title/detail/badge
     * werden serverseitig in der Sprache des Nutzers (Accept-Language) geliefert.
     */
    public function challenges(Request $req): void
    {
        $uid = $this->userId($req);
        if ($this->challenges === null) {
            Response::json(['challenges' => [], 'points_total' => 0]);
        }
        Response::json($this->challenges->forUser($uid, $req->header('Accept-Language')));
    }

    public function edges(Request $req): void
    {
        $bbox = (string)($req->query['bbox'] ?? '');
        if ($bbox === '') {
            Response::error('bad_request', 'bbox erforderlich (minLon,minLat,maxLon,maxLat).', 400);
        }
        $viewer = $this->viewerClaimant($req);
        $onlyMine = (string)($req->query['mine'] ?? '') === '1';
        // Optionaler limit-Param (Spec §6): die App steuert die Kantenzahl
        // selbst. Ohne Angabe greift ein großzügiger Default; ein expliziter
        // Wert wird 1:1 übernommen (nur auf >= 1 normalisiert).
        $defaultLimit = 10000;
        $limit = isset($req->query['limit']) ? max(1, (int)$req->query['limit']) : $defaultLimit;
        // Persönliche Gefahr-Sicht (opt-in, nur mit Bearer): zusätzliche Felder
        // personal_vulnerability + challenger_scope pro Kante.
        $personal = (string)($req->query['personal'] ?? '') === '1';
        try {
            $edges = $this->read->edgesInBbox($bbox, $viewer, null, $limit, $this->optionalUserId($req), $personal);
        } catch (\InvalidArgumentException $e) {
            Response::error('bad_request', $e->getMessage(), 400);
        }
        if ($onlyMine && $viewer !== null) {
            $edges = array_values(array_filter(
                $edges,
                static fn($e) => $e['owner'] !== null && $e['owner']['claimant_id'] === $viewer,
            ));
        }
        // Optionale LOD-Stufe für weite Zooms: lange Kanten-Geometrien auf
        // max_points_per_edge Stützpunkte ausdünnen (Bucket-Mittelung, optisch
        // deckungsgleich). Ohne Parameter bleibt die volle Geometrie erhalten.
        $mpe = isset($req->query['max_points_per_edge'])
            ? max(2, (int)$req->query['max_points_per_edge'])
            : null;
        if ($mpe !== null) {
            foreach ($edges as &$edge) {
                $edge['geom'] = self::thinGeom($edge['geom'] ?? null, $mpe);
            }
            unset($edge);
        }
        Response::json(['edges' => $edges]);
    }

    /**
     * Dünnt eine GeoJSON-LineString-Geometrie auf höchstens `$cap` Stützpunkte
     * aus (Bucket-Mittelung via {@see \App\Support\MapLod}). Andere
     * Geometrietypen werden unverändert durchgereicht.
     *
     * @param mixed $geom
     * @return mixed
     */
    private static function thinGeom($geom, int $cap)
    {
        if (!is_array($geom) || ($geom['type'] ?? null) !== 'LineString'
            || !is_array($geom['coordinates'] ?? null)) {
            return $geom;
        }
        $points = [];
        foreach ($geom['coordinates'] as $c) {
            if (is_array($c) && count($c) >= 2) {
                $points[] = ['lon' => (float)$c[0], 'lat' => (float)$c[1], 'score' => null];
            }
        }
        $lod = \App\Support\MapLod::simplifyTrack($points, null, $cap);
        $coords = [];
        foreach ($lod['points'] as $p) {
            $coords[] = [$p['lon'], $p['lat']];
        }
        $geom['coordinates'] = $coords;
        return $geom;
    }

    /**
     * GET /game/ownership/map?bbox=&grid= — Besitz-Dichte je Gitterzelle für
     * weite Zooms (GameOwnershipOverview_Backend_Spec). OptionalBearer: mit
     * Bearer wird mine_length_m je Zelle für den Anfragenden gefüllt.
     */
    public function ownershipMap(Request $req): void
    {
        $bbox = (string)($req->query['bbox'] ?? '');
        $parsed = \App\Support\MapLod::parseBbox($bbox);
        if ($parsed === null) {
            Response::error('bad_request', 'bbox erforderlich (minLon,minLat,maxLon,maxLat).', 400);
        }
        [$minLon, $minLat, $maxLon, $maxLat] = $parsed;
        $grid = \App\Support\MapLod::gridFromQuery($req->query);
        Response::json($this->read->ownershipMap(
            $minLon, $minLat, $maxLon, $maxLat, $this->viewerClaimant($req), $grid,
        ));
    }

    public function edge(Request $req): void
    {
        $id = (int)($req->routeParams['id'] ?? 0);
        $detail = $this->read->edgeDetail($id, $this->viewerClaimant($req), null, $this->optionalUserId($req));
        if ($detail === null) {
            Response::error('not_found', 'Kante nicht gefunden.', 404);
        }
        Response::json($detail);
    }

    public function me(Request $req): void
    {
        $uid = $this->userId($req);
        // Effektiver Claimant (Crew, wenn Mitglied, sonst Rider) — MUSS mit der
        // Edge-Serialisierung (viewerClaimant) übereinstimmen. Sonst meldet ein
        // Crew-Mitglied held_edges=0, obwohl seine Kanten als owner_is_me=true
        // erscheinen (Besitz liegt nach Beitritt beim Group-Claimant).
        $claimant = $this->repo->effectiveClaimantId($uid);
        Response::json($this->read->me($claimant, $uid));
    }

    /** GET /game/me/at-risk — gefährdete eigene Kanten (Bearer). */
    public function atRisk(Request $req): void
    {
        $uid = $this->userId($req);
        Response::json($this->atRisk->atRisk($uid));
    }

    /**
     * GET /game/me/pioneered (Bearer) — zuletzt vom Fahrer erschlossene Kanten
     * (Erstbefahrer-Recht §7) für den Pionier-Showcase. `limit` (Default 10, max 50).
     */
    public function pioneered(Request $req): void
    {
        $uid = $this->userId($req);
        $claimant = $this->repo->effectiveClaimantId($uid);
        $limit = max(1, min(50, (int)($req->query['limit'] ?? 10)));
        Response::json($this->read->pioneeredShowcase($claimant, $limit));
    }

    public function config(Request $req): void
    {
        $this->userId($req); // Bearer erzwungen
        Response::json(['config' => $this->config->all()]);
    }

    /**
     * GET /game/progression (Bearer) — statischer Progressions-Katalog für die
     * Rang-Leiter + Abzeichen-Galerie (RankBadges_Concept.md): AP-Schwellen je
     * Rang, Gate-Regeln und Familien mit Stufenschwellen. Namen liefert die App
     * lokalisiert; Schwellen sind hier die Single Source of Truth.
     */
    public function progression(Request $req): void
    {
        $this->userId($req); // Bearer erzwungen
        Response::json((new PlayerProgressionService($this->repo, $this->config))->catalog());
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
        try {
            $parsed = $this->parser->parse($loaded['payload']);
        } catch (GeometryParseException $e) {
            // Route ohne verwertbare Geometrie → kein Server-, sondern ein
            // Eingabefehler.
            Response::error('unprocessable_route', 'Route enthält keine verwertbare Geometrie: ' . $e->getMessage(), 422);
        }

        // Strava-/GPX-Importe gelten vorerst als „echt": sie tragen keine
        // Motion-/Surface-/Radar-Daten, sollen aber dennoch Besitz beanspruchen
        // können. Für diese Quellen wird der Motion-Authentizitätsfilter
        // umgangen (Day-Cap, Privatzonen, start_buffer_m, Wertlogik bleiben).
        $trusted = GameIngestionService::isTrustedSource($route['source']);

        $radar = $parsed->sourceFormat === 'gpx'
            ? RadarTrafficParser::parse($loaded['payload'])
            : RadarTrafficData::empty();
        try {
            $summary = $this->ingest->ingest(
                (int)$route['route_id'],
                $uid,
                $parsed,
                $parsed->startedAt !== null,
                null,
                $radar,
                $trusted,
            );
        } catch (MatchUnavailableException $e) {
            // Routing-Engine (Valhalla) nicht erreichbar/kein Match → kein 500,
            // sondern ein ehrliches 503: der Client darf später erneut.
            Response::error('routing_unavailable', 'Map-Matching derzeit nicht möglich (Routing-Engine nicht erreichbar). Bitte später erneut versuchen.', 503);
        }
        Response::json($summary);
    }

    /** GET /game/rides/{routeId}/summary — Per-Ride Eroberungs-Zusammenfassung. */
    public function rideSummary(Request $req): void
    {
        $uid = $this->userId($req);
        $routeRef = trim((string)($req->routeParams['route_id'] ?? ''));
        try {
            $summary = $this->rideSummary->summary($uid, $routeRef);
        } catch (RideSummaryNotIngestedException) {
            Response::error('not_ingested', 'Route ist noch nicht ins Spiel aufgenommen.', 404);
        }
        if ($summary === null) {
            Response::error('not_found', 'Route nicht gefunden.', 404);
        }
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

    /** Optionaler Bearer: liefert die User-ID oder null (für in_reach-Heimatzone). */
    private function optionalUserId(Request $req): ?int
    {
        $u = $req->user;
        if ($u === null) {
            return null;
        }
        $uid = (int)($u->internal_id ?? 0);
        return $uid > 0 ? $uid : null;
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
