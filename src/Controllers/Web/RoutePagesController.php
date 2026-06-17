<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Config\Config;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Routes\GeometryParseException;
use App\Routes\RouteNotFoundException;
use App\Routes\RouteService;
use App\Routes\ShareTokenService;
use App\Support\Validator;
use Throwable;

/**
 * Web-UI für Routen — Listing, Upload, Detail, Edit, Soft-Delete,
 * Share-Verwaltung. Spiegelt das Owner-API aus
 * {@see App\Controllers\Api\RouteController}, aber für Browser-Forms
 * statt JSON-Calls.
 *
 * Auth-Modell:
 *  - Primär-Auth ist die {@see WebSession}. Pro Request wird
 *    {@see resolveOrRefresh()} aufgerufen — wenn die Session abgelaufen
 *    ist, leiten wir auf `/auth/web-refresh?next=…` um, sodass das
 *    path-scoped `ge_refresh` einmalig konsumiert wird und der User
 *    danach auf der ursprünglichen Page landet.
 *  - Alle POSTs sind via {@see Csrf}-Middleware geschützt.
 *
 * M5-Verify-Pflicht:
 *  - GET /routes/new zeigt für unverified User ein Banner mit Resend-
 *    Hinweis, statt der Upload-Form. POST /routes blockt unverified
 *    serverseitig identisch zum API.
 */
final class RoutePagesController
{
    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly RouteService $routes,
        private readonly ShareTokenService $shares,
        private readonly Config $config,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    // ---------------------------------------------------------------------
    // GET /routes — Listing
    // ---------------------------------------------------------------------
    public function index(Request $req): void
    {
        [$user] = $this->resolveOrRefresh($req, '/routes');
        $items  = $this->routes->listForUser((int)$user['internal_id'], 100, 0);
        $this->render('routes/index', $user, [
            '_title'  => 'Meine Routen · GravelExplorer',
            'routes'  => $items,
            'verified' => (bool)$user['email_verified'],
            'flash'   => $this->popFlash(),
        ]);
    }

    // ---------------------------------------------------------------------
    // GET /routes/new — Upload-Form (oder Verify-Banner)
    // ---------------------------------------------------------------------
    public function showUpload(Request $req): void
    {
        [$user] = $this->resolveOrRefresh($req, '/routes/new');
        $this->render('routes/new', $user, [
            '_title'   => 'Route hochladen · GravelExplorer',
            'verified' => (bool)$user['email_verified'],
            'errors'   => [],
            'values'   => ['title' => '', 'description' => '', 'visibility' => 'private', 'tags' => ''],
            'flash'    => $this->popFlash(),
        ]);
    }

    // ---------------------------------------------------------------------
    // POST /routes — Upload-Submit
    // ---------------------------------------------------------------------
    public function doUpload(Request $req): void
    {
        [$user] = $this->resolveOrRefresh($req, '/routes/new');

        // Doppelter Schutz parallel zum RequireVerified-Middleware-Pfad
        // im API: das Web-Routing hängt RequireVerified nicht vor (weil
        // CSRF + WebSession bereits gesetzt sind), also prüfen wir hier
        // explizit.
        if (!$user['email_verified']) {
            $this->setFlash('Bitte bestätige zuerst deine E-Mail-Adresse, bevor du Routen hochlädst.');
            Response::redirect('/routes/new');
        }

        $upload = $req->file('payload');
        $title  = (string)($req->post['title']       ?? '');
        $desc   = (string)($req->post['description'] ?? '');
        $vis    = (string)($req->post['visibility']  ?? 'private');
        $tags   = (string)($req->post['tags']        ?? '');

        $errors = [];
        if ($upload === null) {
            $errors['payload'] = ['Bitte wähle eine GPX- oder GeoJSON-Datei.'];
        } else {
            $maxBytes = $this->config->int('REQUEST_MAX_UPLOAD_BYTES', 26_214_400);
            if ($upload['size'] > $maxBytes) {
                $errors['payload'] = ['Datei ist zu groß (max. ' . self::humanBytes($maxBytes) . ').'];
            }
        }

        $v = new Validator();
        $cleanTitle = $v->routeTitle('title', $title);
        $cleanDesc  = $desc === '' ? null : $v->optionalString('description', $desc);
        $cleanVis   = $v->visibility('visibility', $vis);
        $tagList    = $tags === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $tags)), static fn($t) => $t !== ''));
        $cleanTags  = $v->tags('tags', $tagList);
        if ($v->fails()) {
            $errors = array_merge($errors, $v->errors());
        }

        if ($errors !== [] || $upload === null) {
            $this->render('routes/new', $user, [
                '_title'   => 'Route hochladen · GravelExplorer',
                'verified' => true,
                'errors'   => $errors,
                'values'   => ['title' => $title, 'description' => $desc, 'visibility' => $vis, 'tags' => $tags],
                'flash'    => null,
            ], 422);
        }

        $payload = @file_get_contents($upload['tmp_name']);
        if ($payload === false) {
            $this->render('routes/new', $user, [
                '_title'   => 'Route hochladen · GravelExplorer',
                'verified' => true,
                'errors'   => ['payload' => ['Datei konnte nicht gelesen werden.']],
                'values'   => ['title' => $title, 'description' => $desc, 'visibility' => $vis, 'tags' => $tags],
                'flash'    => null,
            ], 500);
        }

        try {
            $result = $this->routes->createOrAddVersion(
                userId: (int)$user['internal_id'],
                title: $cleanTitle,
                description: $cleanDesc,
                visibility: $cleanVis,
                // Web-UI = File-Import (semantisch wie iOS-Strava-Import,
                // im Gegensatz zu 'app' = direkt aufgezeichnet).
                source: 'import',
                clientRouteUuid: null,
                payload: $payload,
                tags: $cleanTags ?? [],
            );
        } catch (GeometryParseException $e) {
            $this->render('routes/new', $user, [
                '_title'   => 'Route hochladen · GravelExplorer',
                'verified' => true,
                'errors'   => ['payload' => [$e->getMessage()]],
                'values'   => ['title' => $title, 'description' => $desc, 'visibility' => $vis, 'tags' => $tags],
                'flash'    => null,
            ], 422);
        } catch (Throwable $e) {
            $this->render('routes/new', $user, [
                '_title'   => 'Route hochladen · GravelExplorer',
                'verified' => true,
                'errors'   => ['payload' => [$e->getMessage()]],
                'values'   => ['title' => $title, 'description' => $desc, 'visibility' => $vis, 'tags' => $tags],
                'flash'    => null,
            ], 422);
        }

        $verb = $result['action'] === 'created' ? 'angelegt' : ('aktualisiert (Version ' . $result['version'] . ')');
        $this->setFlash('Route „' . $cleanTitle . '" ' . $verb . '.');
        Response::redirect('/routes/' . $result['route']['id']);
    }

    // ---------------------------------------------------------------------
    // GET /routes/{id} — Detail + Share-Liste
    // ---------------------------------------------------------------------
    public function show(Request $req): void
    {
        $publicId = (string)($req->routeParams['id'] ?? '');
        [$user]   = $this->resolveOrRefresh($req, '/routes/' . $publicId);

        $route = $this->routes->get((int)$user['internal_id'], $publicId);
        if ($route === null) {
            $this->render404($user);
        }
        $sharesList = $this->shares->listForRoute((int)$user['internal_id'], $publicId);

        // Ein gerade frisch erzeugter Share-Token kommt als Flash —
        // siehe doCreateShare(). Wir holen ihn raus und reichen ihn an
        // die View, damit der User einmalig den Klartext-Token sieht.
        $newShareToken = $_SESSION['flash_share_token'] ?? null;
        unset($_SESSION['flash_share_token']);

        $this->render('routes/show', $user, [
            '_title'        => $route['title'] . ' · GravelExplorer',
            'route'         => $route,
            'shares'        => $sharesList,
            'newShareToken' => $newShareToken,
            'shareBaseUrl'  => $this->shareBaseUrl(),
            'flash'         => $this->popFlash(),
        ]);
    }

    // ---------------------------------------------------------------------
    // GET /routes/{id}/edit — Edit-Form
    // ---------------------------------------------------------------------
    public function showEdit(Request $req): void
    {
        $publicId = (string)($req->routeParams['id'] ?? '');
        [$user]   = $this->resolveOrRefresh($req, '/routes/' . $publicId . '/edit');

        $route = $this->routes->get((int)$user['internal_id'], $publicId);
        if ($route === null) {
            $this->render404($user);
        }

        $this->render('routes/edit', $user, [
            '_title' => 'Route bearbeiten · GravelExplorer',
            'route'  => $route,
            'errors' => [],
            'values' => [
                'title'       => $route['title'],
                'description' => (string)($route['description'] ?? ''),
                'visibility'  => $route['visibility'],
                'tags'        => implode(', ', $route['tags'] ?? []),
            ],
            'flash'  => $this->popFlash(),
        ]);
    }

    // ---------------------------------------------------------------------
    // POST /routes/{id}/update — Edit-Submit
    // ---------------------------------------------------------------------
    public function doUpdate(Request $req): void
    {
        $publicId = (string)($req->routeParams['id'] ?? '');
        [$user]   = $this->resolveOrRefresh($req, '/routes/' . $publicId . '/edit');

        $title = (string)($req->post['title']       ?? '');
        $desc  = (string)($req->post['description'] ?? '');
        $vis   = (string)($req->post['visibility']  ?? 'private');
        $tags  = (string)($req->post['tags']        ?? '');

        $v = new Validator();
        $cleanTitle = $v->routeTitle('title', $title);
        $cleanDesc  = $desc === '' ? null : $v->optionalString('description', $desc);
        $cleanVis   = $v->visibility('visibility', $vis);
        $tagList    = $tags === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $tags)), static fn($t) => $t !== ''));
        $cleanTags  = $v->tags('tags', $tagList);

        if ($v->fails()) {
            $route = $this->routes->get((int)$user['internal_id'], $publicId);
            if ($route === null) { $this->render404($user); }
            $this->render('routes/edit', $user, [
                '_title' => 'Route bearbeiten · GravelExplorer',
                'route'  => $route,
                'errors' => $v->errors(),
                'values' => ['title' => $title, 'description' => $desc, 'visibility' => $vis, 'tags' => $tags],
                'flash'  => null,
            ], 422);
        }

        try {
            $this->routes->updateMeta((int)$user['internal_id'], $publicId, [
                'title'       => $cleanTitle,
                'description' => $cleanDesc,
                'visibility'  => $cleanVis,
                'tags'        => $cleanTags ?? [],
            ]);
        } catch (RouteNotFoundException) {
            $this->render404($user);
        } catch (Throwable $e) {
            $route = $this->routes->get((int)$user['internal_id'], $publicId);
            if ($route === null) { $this->render404($user); }
            $this->render('routes/edit', $user, [
                '_title' => 'Route bearbeiten · GravelExplorer',
                'route'  => $route,
                'errors' => ['title' => [$e->getMessage()]],
                'values' => ['title' => $title, 'description' => $desc, 'visibility' => $vis, 'tags' => $tags],
                'flash'  => null,
            ], 422);
        }

        $this->setFlash('Route aktualisiert.');
        Response::redirect('/routes/' . $publicId);
    }

    // ---------------------------------------------------------------------
    // POST /routes/{id}/delete — Soft-Delete
    // ---------------------------------------------------------------------
    public function doDelete(Request $req): void
    {
        $publicId = (string)($req->routeParams['id'] ?? '');
        [$user]   = $this->resolveOrRefresh($req, '/routes/' . $publicId);

        try {
            $this->routes->softDelete((int)$user['internal_id'], $publicId);
        } catch (RouteNotFoundException) {
            $this->render404($user);
        }
        $this->setFlash('Route gelöscht.');
        Response::redirect('/routes');
    }

    // ---------------------------------------------------------------------
    // GET /routes/{id}/download — Download head-version payload
    // ---------------------------------------------------------------------
    public function download(Request $req): void
    {
        $publicId = (string)($req->routeParams['id'] ?? '');
        [$user]   = $this->resolveOrRefresh($req, '/routes/' . $publicId);

        try {
            $loaded = $this->routes->loadPayload((int)$user['internal_id'], $publicId, null);
        } catch (RouteNotFoundException) {
            $this->render404($user);
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

    // ---------------------------------------------------------------------
    // POST /routes/{id}/shares — Share-Link erzeugen
    // ---------------------------------------------------------------------
    public function doCreateShare(Request $req): void
    {
        $publicId = (string)($req->routeParams['id'] ?? '');
        [$user]   = $this->resolveOrRefresh($req, '/routes/' . $publicId);

        try {
            $share = $this->shares->create((int)$user['internal_id'], $publicId, null);
        } catch (RouteNotFoundException) {
            $this->render404($user);
        }
        // Token-Klartext nur einmal anzeigen — nach Redirect lesen wir ihn
        // aus der Flash-Variable und die View zeigt ihn als Copy-to-Hint.
        Csrf::ensureStarted();
        $_SESSION['flash_share_token'] = $share['token'];
        $this->setFlash('Neuer Share-Link erstellt. Kopiere ihn jetzt — er wird nur einmal angezeigt.');
        Response::redirect('/routes/' . $publicId);
    }

    // ---------------------------------------------------------------------
    // POST /routes/{id}/shares/{shareId}/revoke — Share zurückziehen
    // ---------------------------------------------------------------------
    public function doRevokeShare(Request $req): void
    {
        $publicId = (string)($req->routeParams['id']      ?? '');
        $shareId  = (int)   ($req->routeParams['shareId'] ?? 0);
        [$user]   = $this->resolveOrRefresh($req, '/routes/' . $publicId);
        if ($shareId > 0) {
            $this->shares->revoke((int)$user['internal_id'], $shareId);
            $this->setFlash('Share-Link zurückgezogen.');
        }
        Response::redirect('/routes/' . $publicId);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return array{0: array<string,mixed>, 1: int}  [user, sessionId]
     */
    private function resolveOrRefresh(Request $req, string $next): array
    {
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/auth/web-refresh?next=' . rawurlencode($next));
        }
        $user = $this->auth->loadUserPublic($ctx['user_id']);
        // loadUserPublic enthält keine internal_id — wir brauchen sie für
        // alle Service-Calls. Aus dem WebSession-Context ziehen.
        $user['internal_id'] = $ctx['user_id'];
        Csrf::ensureStarted();
        return [$user, $ctx['session_id']];
    }

    /** @param array<string,mixed> $vars */
    private function render(string $view, array $user, array $vars, int $status = 200): never
    {
        $vars['_authedUser'] = $user;
        $vars['_layoutWide'] = true;
        $this->view->render($view, $vars, $status);
    }

    private function render404(array $user): never
    {
        $this->render('routes/not_found', $user, [
            '_title' => 'Route nicht gefunden · GravelExplorer',
            'flash'  => null,
        ], 404);
    }

    private function shareBaseUrl(): string
    {
        $appUrl = rtrim((string)$this->config->get('APP_URL', ''), '/');
        return $appUrl . '/share/';
    }

    private function setFlash(string $msg): void
    {
        Csrf::ensureStarted();
        $_SESSION['flash'] = $msg;
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

    private static function humanBytes(int $bytes): string
    {
        $mb = $bytes / 1_048_576;
        return number_format($mb, 1, ',', '.') . ' MB';
    }
}
