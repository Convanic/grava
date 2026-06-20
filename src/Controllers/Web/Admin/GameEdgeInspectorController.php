<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminGuard;
use App\Game\Admin\GameAdminService;
use App\Game\Admin\GameAuditService;
use App\Game\Admin\GamePassAdminService;
use App\Game\Admin\GameUserFlagService;
use App\Game\EdgeRecalculator;
use App\Game\GameRepository;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;

/** Server-gerenderter Kanten-Inspector (D): Pass-Invalidierung, Recalc, User-Ban. */
final class GameEdgeInspectorController
{
    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminGuard $guard,
        private readonly GameAdminService $admin,
        private readonly GamePassAdminService $passAdmin,
        private readonly GameUserFlagService $userFlag,
        private readonly EdgeRecalculator $recalc,
        private readonly GameRepository $repo,
        private readonly GameAuditService $audit,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function show(Request $req): void
    {
        [$user] = $this->requireAdmin();
        $edgeId = (int)($req->routeParams['id'] ?? $req->query['id'] ?? 0);
        $inspector = $edgeId > 0 ? $this->admin->edgeInspector($edgeId) : null;
        $this->view->render('admin/game/edge', [
            '_title' => 'Game · Kanten-Inspector', '_authedUser' => $user, '_layoutWide' => true,
            'flash' => $this->takeFlash(),
            'inspector' => $inspector,
            'edgeId' => $edgeId,
        ]);
    }

    public function invalidatePass(Request $req): void
    {
        [, $adminId] = $this->requireAdmin();
        $passId = (int)($req->routeParams['pass_id'] ?? 0);
        $reason = trim((string)$req->input('reason', 'admin'));
        $edgeId = (int)$req->input('edge_id', 0);
        $ok = $this->passAdmin->invalidate($adminId, $passId, $reason !== '' ? $reason : 'admin');
        $this->flash($ok ? "Pass {$passId} invalidiert." : "Pass {$passId} nicht gefunden.");
        Response::redirect('/admin/game/edge/' . $edgeId);
    }

    public function reactivatePass(Request $req): void
    {
        [, $adminId] = $this->requireAdmin();
        $passId = (int)($req->routeParams['pass_id'] ?? 0);
        $edgeId = (int)$req->input('edge_id', 0);
        $ok = $this->passAdmin->reactivate($adminId, $passId);
        $this->flash($ok ? "Pass {$passId} reaktiviert." : "Pass {$passId} nicht gefunden.");
        Response::redirect('/admin/game/edge/' . $edgeId);
    }

    public function recalcEdge(Request $req): void
    {
        [, $adminId] = $this->requireAdmin();
        $edgeId = (int)($req->routeParams['id'] ?? 0);
        $this->repo->refreshEdgeDiscovery($edgeId);
        $this->recalc->recalculate($edgeId);
        $this->audit->record($adminId, 'edge_recalc', 'edge:' . $edgeId, null);
        $this->flash("Kante {$edgeId} neu berechnet.");
        Response::redirect('/admin/game/edge/' . $edgeId);
    }

    public function banUser(Request $req): void
    {
        [, $adminId] = $this->requireAdmin();
        $userId = (int)($req->routeParams['user_id'] ?? 0);
        $reason = trim((string)$req->input('reason', 'admin'));
        $edgeId = (int)$req->input('edge_id', 0);
        $this->userFlag->ban($adminId, $userId, $reason !== '' ? $reason : 'admin');
        $this->flash("User {$userId} für das Spiel gesperrt.");
        Response::redirect('/admin/game/edge/' . $edgeId);
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
}
