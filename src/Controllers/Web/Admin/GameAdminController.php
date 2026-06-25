<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminGuard;
use App\Game\Admin\GameAdminService;
use App\Game\Admin\GameAuditService;
use App\Game\Admin\GameConfigAdminService;
use App\Game\Admin\GameModerationService;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameMath;
use App\Game\GameRecomputeService;
use App\Game\GameRepository;
use App\Heatmap\ValhallaClient;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Routes\GeometryParser;
use App\Routes\RouteService;
use Throwable;

/** Server-gerendertes Game-Admin-Dashboard (A,B,C,E,F). Schutz: WebSession + ADMIN_EMAILS. */
final class GameAdminController
{
    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminGuard $guard,
        private readonly GameAdminService $admin,
        private readonly GameConfigAdminService $configAdmin,
        private readonly GameConfig $config,
        private readonly GameModerationService $moderation,
        private readonly GameRecomputeService $recompute,
        private readonly GameAuditService $audit,
        private readonly GameRepository $repo,
        private readonly GameIngestionService $ingest,
        private readonly RouteService $routes,
        private readonly GeometryParser $parser,
        private readonly ValhallaClient $valhalla,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function health(Request $req): void
    {
        [$user] = $this->requireAdmin();
        $this->view->render('admin/game/health', [
            '_title' => 'Game · Health', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'metrics' => $this->admin->healthMetrics(),
            'ingestHealth' => $this->admin->ingestHealth(),
            // Map-Matching-Abhängigkeit: ohne erreichbaren Valhalla schlägt die
            // Game-Ingestion fehl (routing_unavailable). Ping mit kurzem Timeout.
            'valhalla' => $this->valhalla->status(),
            'audits' => $this->audit->recent(15),
        ]);
    }

    public function config(Request $req): void
    {
        [$user] = $this->requireAdmin();
        $this->view->render('admin/game/config', [
            '_title' => 'Game · Config', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'config' => $this->config->all(),
            'errors' => $this->takeErrors(),
            'pioneerPreview' => $this->pioneerPreview(),
        ]);
    }

    public function saveConfig(Request $req): void
    {
        [, $adminId] = $this->requireAdmin();
        $values = [];
        foreach (array_keys($this->config->all()) as $k) {
            $v = $req->input($k, null);
            if ($v !== null) {
                $values[$k] = (string)$v;
            }
        }
        $errors = $this->configAdmin->update($adminId, $values);
        if ($errors !== []) {
            $_SESSION['game_config_errors'] = $errors;
            $this->flash('Validierung fehlgeschlagen — bitte Eingaben prüfen.');
        } else {
            $this->flash('Konfiguration gespeichert.');
        }
        Response::redirect('/admin/game/config');
    }

    public function recompute(Request $req): void
    {
        [, $adminId] = $this->requireAdmin();
        $bbox = trim((string)$req->input('bbox', ''));
        if ($bbox !== '') {
            $parts = array_map('trim', explode(',', $bbox));
            if (count($parts) === 4 && array_filter($parts, static fn($p) => !is_numeric($p)) === []) {
                [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $parts);
                $n = $this->recompute->recomputeBbox($minLon, $minLat, $maxLon, $maxLat);
                $this->audit->record($adminId, 'recompute', 'bbox:' . $bbox, ['edges' => $n]);
                $this->flash("Region neu berechnet: {$n} Kanten.");
            } else {
                $this->flash('Ungültige BBox (erwartet minLon,minLat,maxLon,maxLat).');
            }
        } else {
            $n = $this->recompute->recomputeAll();
            $this->audit->record($adminId, 'recompute', 'full', ['edges' => $n]);
            $this->flash("Voll-Recompute: {$n} Kanten.");
        }
        Response::redirect('/admin/game/config');
    }

    public function ingest(Request $req): void
    {
        [$user] = $this->requireAdmin();
        $status = (string)($req->query['status'] ?? '');
        $status = in_array($status, ['ok', 'pending', 'failed'], true) ? $status : null;
        $this->view->render('admin/game/ingest', [
            '_title' => 'Game · Ingest', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'ingestHealth' => $this->admin->ingestHealth(),
            'rows' => $this->admin->recentIngests($status, 100),
            'status' => $status,
        ]);
    }

    public function reingest(Request $req): void
    {
        [, $adminId] = $this->requireAdmin();
        $routeId = (int)($req->routeParams['route_id'] ?? 0);
        $route = $this->repo->routeForIngest($routeId);
        if ($route === null) {
            $this->flash('Route nicht gefunden.');
            Response::redirect('/admin/game/ingest');
        }
        $this->ingestRoute($adminId, $routeId, (int)$route['user_id'], (string)$route['public_id'], (string)$route['source']);
        Response::redirect('/admin/game/ingest');
    }

    /** Manuelle Ingestion per interner Route-ID ODER Public-ID (Formular). */
    public function ingestByRoute(Request $req): void
    {
        [, $adminId] = $this->requireAdmin();
        $input = trim((string)$req->input('route', ''));
        if ($input === '') {
            $this->flash('Bitte eine Route-ID oder Public-ID angeben.');
            Response::redirect('/admin/game/ingest');
        }
        $route = $this->repo->resolveRouteForIngest($input);
        if ($route === null) {
            $this->flash("Keine Route gefunden für: {$input}");
            Response::redirect('/admin/game/ingest');
        }
        $this->ingestRoute($adminId, (int)$route['route_id'], (int)$route['user_id'], (string)$route['public_id'], (string)$route['source']);
        Response::redirect('/admin/game/ingest');
    }

    /** Gemeinsame Ingest-Ausführung mit Audit + Flash (reingest + manuell). */
    private function ingestRoute(int $adminId, int $routeId, int $userId, string $publicId, string $source = 'app'): void
    {
        try {
            $loaded = $this->routes->loadPayloadByPublicId($publicId);
            $parsed = $this->parser->parse($loaded['payload']);
            $summary = $this->ingest->ingest(
                $routeId,
                $userId,
                $parsed,
                $parsed->startedAt !== null,
                null,
                null,
                \App\Game\GameIngestionService::isTrustedSource($source),
            );
            $this->audit->record($adminId, 'ingest_rerun', 'route:' . $routeId, $summary);
            $this->flash("Route {$routeId} ingestiert: {$summary['passes_new']} neue Pässe (gematcht: {$summary['matched']}).");
        } catch (Throwable $e) {
            $this->repo->insertIngestLog($routeId, $userId, 'failed', 0, 0, null, substr($e->getMessage(), 0, 255), null);
            $this->audit->record($adminId, 'ingest_rerun_failed', 'route:' . $routeId, ['error' => $e->getMessage()]);
            $this->flash('Ingestion fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function moderation(Request $req): void
    {
        [$user] = $this->requireAdmin();
        $this->view->render('admin/game/moderation', [
            '_title' => 'Game · Moderation', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'highVolume' => $this->moderation->highVolumeRiders(50),
            'suspiciousSpeed' => $this->moderation->suspiciousSpeed(50),
        ]);
    }

    public function players(Request $req): void
    {
        [$user] = $this->requireAdmin();
        $this->view->render('admin/game/players', [
            '_title' => 'Game · Spieler', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'rows' => $this->admin->leaderboard(50),
        ]);
    }

    public function crews(Request $req): void
    {
        [$user] = $this->requireAdmin();
        $this->view->render('admin/game/crews', [
            '_title' => 'Game · Crews', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'rows' => $this->admin->crewLeaderboard(100),
        ]);
    }

    public function player(Request $req): void
    {
        [$user] = $this->requireAdmin();
        $q = trim((string)($req->query['q'] ?? ''));
        $this->view->render('admin/game/player', [
            '_title' => 'Game · Spieler-Detail', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'q' => $q,
            'detail' => $q !== '' ? $this->admin->playerDetail($q) : null,
        ]);
    }

    /** Regions-Übersichtskarte (Leaflet). Daten kommen per GeoJSON-Endpunkt. */
    public function map(Request $req): void
    {
        [$user] = $this->requireAdmin();
        $this->view->render('admin/game/map', [
            '_title' => 'Game · Karte', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
        ]);
    }

    /**
     * GeoJSON-FeatureCollection der Kanten (optional BBox-gefiltert) für die
     * Übersichtskarte. Props: owner/value/freshness zum Einfärben am Client.
     */
    public function edgesGeoJson(Request $req): void
    {
        $this->requireAdmin();
        [$minLon, $minLat, $maxLon, $maxLat] = $this->parseBbox((string)($req->query['bbox'] ?? ''));
        $limit = (int)($req->query['limit'] ?? 50000);
        $limit = max(1, min(50000, $limit));

        $rows = $this->repo->edgesGeoForMap($minLon, $minLat, $maxLon, $maxLat, $limit);

        $features = [];
        $maxValue = 0.0;
        $bMinLon = $bMinLat = INF;
        $bMaxLon = $bMaxLat = -INF;
        foreach ($rows as $r) {
            $geom = json_decode((string)$r['geom_geojson'], true);
            if (!is_array($geom) || !isset($geom['coordinates'])) {
                continue;
            }
            $value = (float)$r['value_cached'];
            $maxValue = max($maxValue, $value);
            $bMinLon = min($bMinLon, (float)$r['min_lon']);
            $bMinLat = min($bMinLat, (float)$r['min_lat']);
            $bMaxLon = max($bMaxLon, (float)$r['max_lon']);
            $bMaxLat = max($bMaxLat, (float)$r['max_lat']);
            $features[] = [
                'type' => 'Feature',
                'geometry' => $geom,
                'properties' => [
                    'id'            => (int)$r['id'],
                    'owner_id'      => $r['owner_claimant_id'] !== null ? (int)$r['owner_claimant_id'] : null,
                    'owner_handle'  => $r['owner_handle'] !== null ? (string)$r['owner_handle'] : null,
                    // Owner-Modus: der Mensch, der die Kante zuerst erradelt hat.
                    'rider_id'      => $r['rider_user_id'] !== null ? (int)$r['rider_user_id'] : null,
                    'rider_handle'  => $r['rider_handle'] !== null ? (string)$r['rider_handle'] : null,
                    // Crew-Modus: besitzende Crew (nur wenn Owner eine Gruppe ist).
                    'crew_id'       => $r['crew_id'] !== null ? (int)$r['crew_id'] : null,
                    'crew_name'     => $r['crew_name'] !== null ? (string)$r['crew_name'] : null,
                    // Fraktions-Modus: Fraktion der besitzenden Crew.
                    'faction_key'   => $r['faction_key'] !== null ? (string)$r['faction_key'] : null,
                    'faction_color' => $r['faction_color'] !== null ? (string)$r['faction_color'] : null,
                    'value'         => round($value, 2),
                    'freshness'     => round((float)$r['freshness_cached'], 3),
                    'riders'        => (int)$r['distinct_riders_total'],
                    'length_m'      => round((float)$r['length_m'], 1),
                    'surface'       => $r['surface_character'] !== null ? (string)$r['surface_character'] : null,
                ],
            ];
        }

        $meta = ['count' => count($features), 'max_value' => round($maxValue, 2)];
        if ($features !== []) {
            $meta['bbox'] = [$bMinLon, $bMinLat, $bMaxLon, $bMaxLat];
        }

        Response::json(['type' => 'FeatureCollection', 'features' => $features, 'meta' => $meta]);
    }

    /**
     * @return array{0:?float,1:?float,2:?float,3:?float} [minLon,minLat,maxLon,maxLat] oder vier null.
     */
    private function parseBbox(string $bbox): array
    {
        if ($bbox === '') {
            return [null, null, null, null];
        }
        $parts = array_map('trim', explode(',', $bbox));
        if (count($parts) !== 4 || array_filter($parts, static fn($p) => !is_numeric($p)) !== []) {
            return [null, null, null, null];
        }
        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $parts);
        return [$minLon, $minLat, $maxLon, $maxLat];
    }

    /** @return array{0:array<string,mixed>,1:int} [user, adminId] */
    private function requireAdmin(): array
    {
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/login');
        }
        $user = $this->auth->loadUserPublic($ctx['user_id']);
        if (!$this->guard->isAdminEmail((string)($user['email'] ?? ''))) {
            Response::error('not_found', 'Nicht gefunden.', 404);
        }
        return [$user, (int)$ctx['user_id']];
    }

    /** @return list<array{n:int,pioneer:float}> */
    private function pioneerPreview(): array
    {
        $p0 = $this->config->float('pioneer_p0');
        $k = $this->config->float('pioneer_k');
        $s = $this->config->float('pioneer_s');
        $out = [];
        foreach ([1, 5, 10, 12, 20, 30] as $n) {
            $out[] = ['n' => $n, 'pioneer' => GameMath::pioneer($n, $p0, $k, $s)];
        }
        return $out;
    }

    private function flash(string $msg): void
    {
        Csrf::ensureStarted();
        $_SESSION['flash'] = $msg;
    }

    private function takeFlash(): ?string
    {
        Csrf::ensureStarted();
        $f = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $f !== null ? (string)$f : null;
    }

    /** @return array<string,string> */
    private function takeErrors(): array
    {
        Csrf::ensureStarted();
        $e = $_SESSION['game_config_errors'] ?? [];
        unset($_SESSION['game_config_errors']);
        return is_array($e) ? $e : [];
    }
}
