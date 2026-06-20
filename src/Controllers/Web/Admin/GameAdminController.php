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
        try {
            $loaded = $this->routes->loadPayloadByPublicId($route['public_id']);
            $parsed = $this->parser->parse($loaded['payload']);
            $summary = $this->ingest->ingest($routeId, $route['user_id'], $parsed, $parsed->startedAt !== null, null);
            $this->audit->record($adminId, 'ingest_rerun', 'route:' . $routeId, $summary);
            $this->flash("Route {$routeId} neu ingestiert: {$summary['passes_new']} neue Pässe.");
        } catch (Throwable $e) {
            $this->repo->insertIngestLog($routeId, (int)$route['user_id'], 'failed', 0, 0, null, substr($e->getMessage(), 0, 255), null);
            $this->audit->record($adminId, 'ingest_rerun_failed', 'route:' . $routeId, ['error' => $e->getMessage()]);
            $this->flash('Re-Ingestion fehlgeschlagen: ' . $e->getMessage());
        }
        Response::redirect('/admin/game/ingest');
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
