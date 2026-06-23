<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Controllers\Web\WebView;
use App\Game\Admin\AdminGuard;
use App\Http\Request;
use App\Http\Response;
use App\Routes\RouteAdminService;
use App\Routes\RouteNotFoundException;
use App\Routes\RouteService;

/**
 * Admin-Übersicht aller Routen-Uploads (alle User) inkl. Owner, Quelle,
 * Sichtbarkeit, der lokal gespeicherten Datei (Head-Version) und Spiel-Status.
 * Schutz: WebSession + ADMIN_EMAILS (wie GameAdminController).
 */
final class AdminUploadsController
{
    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly AdminGuard $guard,
        private readonly RouteAdminService $uploads,
        private readonly RouteService $routes,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function index(Request $req): void
    {
        [$user] = $this->requireAdmin();

        $source = (string)($req->query['source'] ?? '');
        $source = in_array($source, RouteAdminService::SOURCES, true) ? $source : null;
        $q = trim((string)($req->query['q'] ?? ''));
        $deleted = (string)($req->query['deleted'] ?? '') === '1';
        $limit = 50;
        $page = max(1, (int)($req->query['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $result = $this->uploads->listUploads([
            'source'          => $source,
            'q'               => $q,
            'include_deleted' => $deleted,
            'limit'           => $limit,
            'offset'          => $offset,
        ]);

        $this->view->render('admin/uploads', [
            '_title' => 'Admin · Uploads', '_authedUser' => $user, '_layoutWide' => true,
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'limit'   => $result['limit'],
            'offset'  => $result['offset'],
            'page'    => $page,
            'summary' => $this->uploads->summary(),
            'filters' => ['source' => $source ?? '', 'q' => $q, 'deleted' => $deleted],
        ]);
    }

    /** Streamt die gespeicherte Head-Version-Datei (Admin, nicht owner-skopiert). */
    public function download(Request $req): void
    {
        $this->requireAdmin();
        $publicId = (string)($req->routeParams['id'] ?? '');
        try {
            $loaded = $this->routes->loadPayloadByPublicId($publicId);
        } catch (RouteNotFoundException) {
            Response::error('not_found', 'Route oder Datei nicht gefunden.', 404);
        }
        $contentType = $loaded['format'] === 'gpx'
            ? 'application/gpx+xml; charset=utf-8'
            : 'application/geo+json; charset=utf-8';
        $filename = sprintf('route-%s-v%d.%s', $publicId, $loaded['version'], $loaded['format']);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $loaded['payload'];
        exit;
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
}
