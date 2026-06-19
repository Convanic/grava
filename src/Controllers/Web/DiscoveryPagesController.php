<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Discovery\DiscoveryService;
use App\Discovery\FeedService;
use App\Discovery\ProfileService;
use App\Http\GeoJsonResponse;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Routes\RouteGeoJson;
use App\Routes\RouteInsights;
use App\Routes\RouteService;

/**
 * M3 Phase 6: Read-only Web-Pages für Discovery + Profile + Feed.
 *
 * Anonym OK an /discover/*, /u/{handle}, /u/{handle}/r/{id}.
 * `/feed` ist Auth-pflichtig (Login-Redirect via WebSession).
 *
 * Wir reichen einen optionalen viewer in die Services, damit
 * Block-Filter und is_followed_by_viewer-Flags greifen — auch in
 * der reinen Web-Sicht.
 */
final class DiscoveryPagesController
{
    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly DiscoveryService $discovery,
        private readonly ProfileService $profile,
        private readonly FeedService $feed,
        string $viewsPath,
        private readonly ?\App\Engagement\LikeService $likes = null,
        private readonly ?\App\Engagement\CommentService $comments = null,
        private readonly ?\App\Engagement\NotificationService $notifications = null,
        private readonly ?\App\Heatmap\HeatmapService $heatmap = null,
        private readonly ?RouteService $routesService = null,
        private readonly ?RouteGeoJson $geo = null,
        private readonly ?RouteInsights $insights = null,
    ) {
        $this->view = new WebView($viewsPath);
    }

    // -----------------------------------------------------------------
    // GET /discover (Routen)
    // -----------------------------------------------------------------
    public function discoverRoutes(Request $req): void
    {
        [$viewerId, $authedUser] = $this->maybeAuthed();

        $filters = [
            'limit'  => $this->intParam($req, 'limit', 20, 1, 50),
            'offset' => max(0, $this->intParam($req, 'offset', 0)),
        ];
        $sort = (string)($req->query['sort'] ?? 'newest');
        if (!in_array($sort, ['newest', 'oldest', 'distance_asc', 'distance_desc'], true)) {
            $sort = 'newest';
        }
        $filters['sort'] = $sort;

        $q = $req->query['q'] ?? null;
        $qStr = '';
        if (is_string($q) && trim($q) !== '') {
            $qStr = substr(trim($q), 0, 100);
            $filters['q'] = $qStr;
        }

        $tags = [];
        if (!empty($req->query['tag'])) {
            $raw = is_array($req->query['tag']) ? $req->query['tag'] : [$req->query['tag']];
            foreach ($raw as $t) {
                if (!is_string($t)) continue;
                $t = trim(strtolower($t));
                if ($t !== '' && preg_match('/^[a-z0-9-]{1,32}$/', $t) === 1) {
                    $tags[] = $t;
                }
            }
            $tags = array_values(array_unique($tags));
            if ($tags !== []) {
                $filters['tags'] = $tags;
            }
        }

        $bbox = $this->parseBbox((string)($req->query['bbox'] ?? ''));
        if ($bbox !== null) {
            $filters['bbox'] = $bbox;
        }

        $res = $this->discovery->searchRoutes($filters, $viewerId);

        $this->renderPage('discover/routes', $authedUser, [
            '_title'     => 'Routen entdecken · GravelExplorer',
            'routes'     => $res['routes'],
            'pagination' => $res['pagination'],
            'filters'    => [
                'q'    => $qStr,
                'sort' => $sort,
                'tags' => $tags,
                'bbox' => $bbox === null ? '' : (string)($req->query['bbox'] ?? ''),
            ],
            '_layoutWide' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // GET /discover/users
    // -----------------------------------------------------------------
    public function discoverUsers(Request $req): void
    {
        [$viewerId, $authedUser] = $this->maybeAuthed();

        $filters = [
            'limit'  => $this->intParam($req, 'limit', 20, 1, 50),
            'offset' => max(0, $this->intParam($req, 'offset', 0)),
        ];
        $q = $req->query['q'] ?? null;
        $qStr = '';
        if (is_string($q) && trim($q) !== '') {
            $qStr = substr(trim($q), 0, 100);
            $filters['q'] = $qStr;
        }

        $res = $this->discovery->searchUsers($filters, $viewerId);

        $this->renderPage('discover/users', $authedUser, [
            '_title'     => 'User entdecken · GravelExplorer',
            'users'      => $res['users'],
            'pagination' => $res['pagination'],
            'filters'    => ['q' => $qStr],
        ]);
    }

    // -----------------------------------------------------------------
    // GET /u/{handle}
    // -----------------------------------------------------------------
    public function profile(Request $req): void
    {
        $handle = (string)($req->routeParams['handle'] ?? '');
        [$viewerId, $authedUser] = $this->maybeAuthed();

        $profile = $this->profile->getProfile($handle, $viewerId);
        if ($profile === null) {
            $this->renderPage('profile/not_found', $authedUser, [
                '_title' => 'Profil nicht gefunden · GravelExplorer',
            ], 404);
        }

        $routes = $this->profile->getProfileRoutes($handle, $viewerId, [
            'limit'  => 20,
            'offset' => 0,
            'sort'   => 'newest',
        ]);

        // Block-Status für aktuellen Viewer (für Block-Button-Logik):
        // Wenn der Viewer den Owner blockt, gäbe es schon 404 — aber
        // wenn er ihn UNblock'en will, kommt er hierher. Lassen wir
        // das simpel und zeigen den Block-Button immer, solange
        // viewer != owner.
        $isSelf = $profile['is_self'] ?? false;

        $this->renderPage('profile/show', $authedUser, [
            '_title'     => '@' . $profile['handle'] . ' · GravelExplorer',
            'profile'    => $profile,
            'routes'     => $routes['routes'] ?? [],
            'pagination' => $routes['pagination'] ?? ['total' => 0, 'has_more' => false, 'limit' => 20, 'offset' => 0],
            'isSelf'     => $isSelf,
            '_layoutWide' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // GET /u/{handle}/r/{id}
    // -----------------------------------------------------------------
    public function profileRoute(Request $req): void
    {
        $handle   = (string)($req->routeParams['handle'] ?? '');
        $routePid = (string)($req->routeParams['id'] ?? '');
        [$viewerId, $authedUser] = $this->maybeAuthed();

        $profile = $this->profile->getProfile($handle, $viewerId);
        if ($profile === null) {
            $this->renderPage('profile/not_found', $authedUser, [
                '_title' => 'Profil nicht gefunden · GravelExplorer',
            ], 404);
        }

        // Wir reusen searchPublic per Profile-Routes, finden den
        // einen Treffer per public_id-Check. Das ist nicht super
        // effizient (ein Vollscan über ein Limit-50-Window), aber
        // für die View völlig OK.
        $listing = $this->profile->getProfileRoutes($handle, $viewerId, [
            'limit'  => 50,
            'offset' => 0,
        ]);
        $route = null;
        foreach (($listing['routes'] ?? []) as $r) {
            if ((string)$r['id'] === $routePid) {
                $route = $r;
                break;
            }
        }
        if ($route === null) {
            $this->renderPage('profile/not_found', $authedUser, [
                '_title' => 'Route nicht gefunden · GravelExplorer',
            ], 404);
        }

        // M4a: Like-Summary für die Detail-Seite (Count + Viewer-Flag).
        $likes = ['count' => 0, 'liked_by_viewer' => false, 'recent' => []];
        if ($this->likes !== null) {
            try {
                $likes = $this->likes->summary((string)$route['id'], $viewerId);
            } catch (\Throwable) {
                // Sichtbarkeit wurde oben bereits via getProfileRoutes
                // sichergestellt; ein Fehler hier ist nicht fatal für die View.
            }
        }

        // M4b: Kommentar-Liste (erste Seite) für die Detail-Seite.
        $comments = ['comments' => [], 'pagination' => ['total' => 0, 'has_more' => false, 'limit' => 20, 'offset' => 0]];
        if ($this->comments !== null) {
            try {
                $comments = $this->comments->list((string)$route['id'], $viewerId, 20, 0);
            } catch (\Throwable) {
                // Sichtbarkeit oben bereits sichergestellt.
            }
        }

        $insights = null;
        if ($this->routesService !== null && $this->insights !== null) {
            try {
                $loaded   = $this->routesService->loadPayloadByPublicId((string)$route['id']);
                $insights = $this->insights->compute($loaded['payload']);
            } catch (\Throwable) {
                $insights = null;
            }
        }

        $this->renderPage('profile/route', $authedUser, [
            '_title'   => $route['title'] . ' · @' . $profile['handle'],
            'route'    => $route,
            'profile'  => $profile,
            'likes'    => $likes,
            'comments' => $comments['comments'],
            'commentsPagination' => $comments['pagination'],
            'insights' => $insights,
        ]);
    }

    // -----------------------------------------------------------------
    // GET /u/{handle}/r/{id}/geojson — Geometrie der öffentlichen Route
    // -----------------------------------------------------------------
    public function profileRouteGeojson(Request $req): void
    {
        $handle   = (string)($req->routeParams['handle'] ?? '');
        $routePid = (string)($req->routeParams['id'] ?? '');
        [$viewerId] = $this->maybeAuthed();

        // Sichtbarkeit exakt wie profileRoute(): Profil muss sichtbar
        // sein und die Route im sichtbaren Listing des Viewers liegen.
        $profile = $this->profile->getProfile($handle, $viewerId);
        if ($profile === null || $this->routesService === null || $this->geo === null) {
            GeoJsonResponse::error(404);
        }
        $listing = $this->profile->getProfileRoutes($handle, $viewerId, ['limit' => 50, 'offset' => 0]);
        $visible = false;
        foreach (($listing['routes'] ?? []) as $r) {
            if ((string)$r['id'] === $routePid) {
                $visible = true;
                break;
            }
        }
        if (!$visible) {
            GeoJsonResponse::error(404);
        }
        try {
            $loaded = $this->routesService->loadPayloadByPublicId($routePid);
            $fc = $this->geo->toFeatureCollection(
                $loaded['payload'],
                [],
                $this->routesService->hintsForPublicId($routePid),
            );
        } catch (\Throwable) {
            GeoJsonResponse::error(404);
        }
        GeoJsonResponse::emit($fc);
    }

    // -----------------------------------------------------------------
    // GET /feed (auth)
    // -----------------------------------------------------------------
    public function feed(Request $req): void
    {
        [$user] = $this->resolveOrRefresh('/feed');
        $viewerId = (int)$user['internal_id'];

        $limit  = $this->intParam($req, 'limit',  20, 1, 50);
        $offset = max(0, $this->intParam($req, 'offset', 0));
        $res = $this->feed->getFeed($viewerId, $limit, $offset);

        $this->renderPage('feed', $user, [
            '_title'     => 'Feed · GravelExplorer',
            'routes'     => $res['routes'],
            'pagination' => $res['pagination'],
            '_layoutWide' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // GET /notifications (auth)
    // -----------------------------------------------------------------
    public function notifications(Request $req): void
    {
        [$user] = $this->resolveOrRefresh('/notifications');
        $viewerId = (int)$user['internal_id'];

        $limit  = $this->intParam($req, 'limit',  30, 1, 50);
        $offset = max(0, $this->intParam($req, 'offset', 0));

        $res = $this->notifications !== null
            ? $this->notifications->list($viewerId, $limit, $offset)
            : ['notifications' => [], 'pagination' => ['total' => 0, 'has_more' => false, 'limit' => $limit, 'offset' => $offset]];

        // Inbox-Besuch markiert alles als gelesen (read-Flags der View
        // stammen noch aus dem Zustand VOR dem Markieren).
        $this->notifications?->markAllRead($viewerId);

        $this->renderPage('notifications', $user, [
            '_title'     => 'Mitteilungen · GravelExplorer',
            'items'      => $res['notifications'],
            'pagination' => $res['pagination'],
        ]);
    }

    // -----------------------------------------------------------------
    // GET /heatmap (anonym OK)
    // -----------------------------------------------------------------
    public function heatmap(Request $req): void
    {
        [, $authedUser] = $this->maybeAuthed();

        $fc = $this->heatmap !== null
            ? $this->heatmap->query(null, 500)
            : ['features' => [], 'meta' => ['grid' => \App\Heatmap\HeatmapService::GRID, 'cell_count' => 0, 'max_weight' => 0]];

        // Top-Zellen für die tabellarische Sicht (kein JS-Map im MVP).
        $cells = [];
        foreach ($fc['features'] as $f) {
            $cells[] = [
                'lat'    => $f['geometry']['coordinates'][1],
                'lon'    => $f['geometry']['coordinates'][0],
                'weight' => $f['properties']['weight'],
            ];
        }

        // M6: Der "Strecken"-Layer (Valhalla-gematchte heatmap_edges) wird erst
        // sichtbar, wenn die Daten in Prod befüllt sind. Flag-gesteuert, damit
        // der Web-Code vor dem Cutover (Modell A) deploybar bleibt.
        $linesEnabled = \App\Config\Config::instance()->bool('HEATMAP_LINES_ENABLED', false);

        $this->renderPage('heatmap', $authedUser, [
            '_title'       => 'Heatmap · GravelExplorer',
            'cells'        => $cells,
            'meta'         => $fc['meta'],
            'linesEnabled' => $linesEnabled,
            '_layoutWide'  => true,
        ]);
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    /**
     * Liefert [viewerId|null, authedUser-array|null]. Anonymous OK.
     */
    private function maybeAuthed(): array
    {
        Csrf::ensureStarted();
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            return [null, null];
        }
        $user = $this->auth->loadUserPublic($ctx['user_id']);
        $user['internal_id'] = $ctx['user_id'];
        return [$ctx['user_id'], $user];
    }

    /**
     * Auth-required mit Refresh-Hop bei abgelaufener Session.
     *
     * @return array{0: array<string,mixed>, 1: int}
     */
    private function resolveOrRefresh(string $next): array
    {
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/auth/web-refresh?next=' . rawurlencode($next));
        }
        $user = $this->auth->loadUserPublic($ctx['user_id']);
        $user['internal_id'] = $ctx['user_id'];
        Csrf::ensureStarted();
        return [$user, $ctx['session_id']];
    }

    /**
     * @param array<string,mixed>|null $authedUser
     * @param array<string,mixed> $vars
     */
    private function renderPage(string $view, ?array $authedUser, array $vars, int $status = 200): never
    {
        $vars['_authedUser'] = $authedUser;
        if (!array_key_exists('flash', $vars)) {
            $vars['flash'] = $this->popFlash();
        }
        // M4c: Unread-Badge in der Navigation für eingeloggte User.
        if (!array_key_exists('_notifUnread', $vars)) {
            $vars['_notifUnread'] = 0;
            if ($authedUser !== null && $this->notifications !== null && isset($authedUser['internal_id'])) {
                try {
                    $vars['_notifUnread'] = $this->notifications->unreadCount((int)$authedUser['internal_id']);
                } catch (\Throwable) {
                    // Badge ist nice-to-have; Fehler dürfen Seite nicht brechen.
                }
            }
        }
        $this->view->render($view, $vars, $status);
    }

    private function popFlash(): ?string
    {
        Csrf::ensureStarted();
        if (isset($_SESSION['flash'])) {
            $msg = (string)$_SESSION['flash'];
            unset($_SESSION['flash']);
            return $msg;
        }
        return null;
    }

    private function intParam(Request $req, string $key, int $default, int $min = 0, int $max = PHP_INT_MAX): int
    {
        $v = $req->query[$key] ?? null;
        if ($v === null || $v === '' || !is_numeric($v)) {
            return $default;
        }
        $n = (int)$v;
        return max($min, min($max, $n));
    }

    /**
     * @return array{min_lat:float,min_lon:float,max_lat:float,max_lon:float}|null
     */
    private function parseBbox(string $raw): ?array
    {
        if ($raw === '') return null;
        $parts = explode(',', $raw);
        if (count($parts) !== 4) return null;
        foreach ($parts as $p) {
            if (!is_numeric(trim($p))) return null;
        }
        $minLat = (float)$parts[0];
        $minLon = (float)$parts[1];
        $maxLat = (float)$parts[2];
        $maxLon = (float)$parts[3];
        if ($minLat > $maxLat || $minLon > $maxLon) return null;
        return ['min_lat'=>$minLat,'min_lon'=>$minLon,'max_lat'=>$maxLat,'max_lon'=>$maxLon];
    }
}
