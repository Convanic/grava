<?php
declare(strict_types=1);

/*
 * GravelExplorer — front controller for API, web and CLI.
 *
 * Routes:
 *   /api/v1/auth/*            JSON API (Bearer auth)
 *   /api/v1/users/*           JSON API (Bearer auth)
 *   /, /login, /register, ... server-rendered web pages (cookie + CSRF)
 *
 * Also doubles as a CLI entry point:
 *   php public/index.php cli:migrate
 *   php public/index.php cron:cleanup
 */

use App\Auth\AuthService;
use App\Auth\CookieAuth;
use App\Auth\PasswordService;
use App\Auth\RateLimiter;
use App\Auth\TokenService;
use App\Auth\WebSession;
use App\Cli\Commands;
use App\Config\Config;
use App\Controllers\Api\AuthController;
use App\Controllers\Api\RouteController;
use App\Controllers\Api\SharedRouteController;
use App\Controllers\Api\UserController;
use App\Controllers\Api\AvatarController;
use App\Controllers\Api\CommentController;
use App\Controllers\Api\DiscoverController;
use App\Controllers\Api\FeedController;
use App\Controllers\Api\HeatmapController;
use App\Controllers\Api\IntegrationsController;
use App\Controllers\Api\LikeController;
use App\Controllers\Api\NotificationController;
use App\Controllers\Api\ProfileController;
use App\Controllers\Api\SocialController;
use App\Controllers\Web\AuthPagesController;
use App\Controllers\Web\DashboardController;
use App\Controllers\Web\DiscoveryPagesController;
use App\Controllers\Web\EngagementPagesController;
use App\Controllers\Web\StravaPagesController;
use App\Controllers\Web\PublicSharePageController;
use App\Controllers\Web\RoutePagesController;
use App\Controllers\Web\SettingsPagesController;
use App\Controllers\Web\SocialPagesController;
use App\Controllers\Web\WebRefreshController;
use App\Http\Middleware\Csrf;
use App\Discovery\BlockService;
use App\Discovery\DiscoveryService;
use App\Discovery\FeedService;
use App\Discovery\FollowService;
use App\Discovery\ProfileService;
use App\Engagement\CommentService;
use App\Engagement\LikeService;
use App\Engagement\NotificationService;
use App\Heatmap\HeatmapService;
use App\Integrations\Strava\FakeStravaClient;
use App\Integrations\Strava\RealStravaClient;
use App\Integrations\Strava\StravaService;
use App\Media\AvatarService;
use App\Support\Crypto;
use App\Http\Middleware\OptionalBearer;
use App\Http\Middleware\RequireBearer;
use App\Http\Middleware\RequireVerified;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Mail\MailService;
use App\Routes\GeometryParser;
use App\Routes\GeometryStats;
use App\Routes\RouteGeoJson;
use App\Routes\RouteRepository;
use App\Routes\RouteService;
use App\Routes\RouteStorage;
use App\Routes\ShareTokenService;
use App\Routes\SurfaceTrack;

$basePath = dirname(__DIR__);

// Beim PHP built-in server (`php -S ... public/index.php`) übernimmt dieses
// Script auch das Routing. Statische Dateien unter public/ wollen wir aber
// direkt vom Server ausliefern lassen (Apache/.htaccess macht das produktiv).
if (PHP_SAPI === 'cli-server') {
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $uri;
    if ($uri !== '/' && is_file($file)) {
        return false;
    }
}

require_once $basePath . '/vendor/autoload.php';

Config::boot($basePath);
$config = Config::instance();

date_default_timezone_set('UTC');

// In production we never leak details. In dev we surface them to speed up
// iteration. Logs always go to storage/logs/php.log via custom handler.
$isProd = $config->isProduction();
ini_set('display_errors', $isProd ? '0' : '1');
ini_set('log_errors', '1');
ini_set('error_log', $basePath . '/storage/logs/php.log');
error_reporting($isProd ? (E_ALL & ~E_DEPRECATED & ~E_NOTICE) : E_ALL);

set_exception_handler(function (\Throwable $e) use ($isProd, $basePath): void {
    // H7: erst über PHP-Standard-Logger (folgt der oben gesetzten
    // error_log-Konfiguration und greift auch wenn die Datei nicht
    // schreibbar ist — landet dann zumindest in stderr/syslog).
    error_log(sprintf("Uncaught: %s in %s:%d\n%s",
        $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
    ));
    // Notnagel: ein letzter direkter Schreibversuch ins File. Hier darf
    // das @ bleiben, weil wir bereits in einem Exception-Handler stehen
    // und einen weiteren Crash hier nicht mehr aufkommen wollen.
    @file_put_contents(
        $basePath . '/storage/logs/php.log',
        sprintf("[%s] %s in %s:%d\n%s\n", gmdate('Y-m-d H:i:s'), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()),
        FILE_APPEND
    );
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'error' => [
            'code'    => 'server_error',
            'message' => $isProd ? 'Interner Serverfehler.' : $e->getMessage(),
        ],
    ]);
    exit;
});

// Wire up services (poor-man's DI).
$passwords  = new PasswordService();
$tokens     = new TokenService($config);
$rate       = new RateLimiter($config);
$mailer     = new MailService($config, $basePath, $basePath . '/views/email');
$auth       = new AuthService($config, $passwords, $tokens, $mailer);
$cookieAuth = new CookieAuth($config, $tokens);
$webSession = new WebSession($config);

// M2: Routes-Stack
$routeStorage = new RouteStorage($config);
$routeRepo    = new RouteRepository();
$routeService = new RouteService($routeRepo, $routeStorage, new GeometryParser(), new GeometryStats());
$shareTokens  = new ShareTokenService($routeRepo);
// GeoJSON-Konverter für die Web-Karten (eigener Parser, zustandslos).
// SurfaceTrack färbt GPX-Tracks mit <ge:surfaceScore> ein.
$routeGeoJson = new RouteGeoJson(new GeometryParser(), new SurfaceTrack());

// ---------------------------------------------------------------------------
// CLI dispatch
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli') {
    $cli = new Commands($basePath, $tokens, $routeService, $config, new NotificationService(), new HeatmapService());
    exit($cli->run($_SERVER['argv'] ?? []));
}

// ---------------------------------------------------------------------------
// HTTP dispatch
// ---------------------------------------------------------------------------

// H3: Sicherheitsheader hosting-portabel im PHP-Layer setzen, nicht nur via
// .htaccess — manche Reverse-Proxies / Shared-Hostings filtern oder
// überschreiben Apache-Header. So sind sie immer aktiv.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
$requestIsHttps = (($_SERVER['HTTPS'] ?? '') === 'on')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
if ($requestIsHttps) {
    // HSTS nur über TLS senden — RFC 6797 verbietet das Setzen über plain HTTP.
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self'; "
  . "style-src 'self' 'unsafe-inline'; img-src 'self' data: https://*.tile.openstreetmap.org; connect-src 'self'; "
  . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'"
);

$request = Request::fromGlobals();
$router  = new Router();

$apiBase = rtrim((string)$config->get('API_BASE_PATH', '/api/v1'), '/');
$requireBearer   = new RequireBearer($tokens);
$optionalBearer  = new OptionalBearer($tokens);
$requireVerified = new RequireVerified();
$csrf            = new Csrf();

$discovery     = new DiscoveryService($routeRepo);
$profileServ   = new ProfileService($discovery, $routeRepo);
$notifServ     = new NotificationService();
$followServ    = new FollowService($notifServ);
$blockServ     = new BlockService();
$feedServ      = new FeedService($routeRepo, $discovery);
$likeServ      = new LikeService($notifServ);
$commentServ   = new CommentService($notifServ);
$avatarServ    = new AvatarService($config);
$heatmapServ   = new HeatmapService();

// M4e: Strava-Integration. Dev-Seam — Fake-Client, wenn STRAVA_FAKE=1
// oder keine STRAVA_CLIENT_ID gesetzt ist; sonst echter HTTP-Client.
$stravaClientId     = (string)($config->get('STRAVA_CLIENT_ID', '') ?? '');
$stravaClientSecret = (string)($config->get('STRAVA_CLIENT_SECRET', '') ?? '');
$stravaRedirectUri  = (string)($config->get('STRAVA_REDIRECT_URI', '') ?? '');
$stravaFake         = (string)($config->get('STRAVA_FAKE', '') ?? '') === '1' || $stravaClientId === '';
$stravaClient       = $stravaFake
    ? new FakeStravaClient()
    : new RealStravaClient($stravaClientId, $stravaClientSecret);
$cryptoServ    = new Crypto((string)$config->get('APP_KEY', ''));
$stravaServ    = new StravaService(
    $stravaClient,
    $cryptoServ,
    $routeService,
    $stravaClientId,
    $stravaRedirectUri,
    $stravaFake,
    (string)$config->get('APP_URL', ''),
);

$apiAuth    = new AuthController($auth, $rate);
$apiUsers   = new UserController($auth);
$apiRoutes  = new RouteController($routeService, $shareTokens, $config);
$apiShared  = new SharedRouteController($shareTokens);
$apiDiscover = new DiscoverController($discovery);
$apiProfile  = new ProfileController($profileServ);
$apiSocial   = new SocialController($followServ, $blockServ);
$apiFeed     = new FeedController($feedServ);
$apiLike     = new LikeController($likeServ);
$apiComment  = new CommentController($commentServ, $rate);
$apiNotif    = new NotificationController($notifServ);
$apiAvatar   = new AvatarController($avatarServ);
$apiIntegr   = new IntegrationsController($stravaServ);
$apiHeatmap  = new HeatmapController($heatmapServ);
$webAuth    = new AuthPagesController($auth, $cookieAuth, $webSession, $rate, $basePath . '/views');
$webHome    = new DashboardController($webSession, $auth, $basePath . '/views');
$webRefresh = new WebRefreshController($cookieAuth, $webSession);
$webRoutes  = new RoutePagesController($webSession, $auth, $routeService, $shareTokens, $config, $routeGeoJson, $basePath . '/views');
$webShare   = new PublicSharePageController($shareTokens, $basePath . '/views', $routeService, $routeGeoJson);
$webSetting = new SettingsPagesController($webSession, $auth, $basePath . '/views', $avatarServ);
$webDiscover = new DiscoveryPagesController($webSession, $auth, $discovery, $profileServ, $feedServ, $basePath . '/views', $likeServ, $commentServ, $notifServ, $heatmapServ, $routeService, $routeGeoJson);
$webSocial   = new SocialPagesController($webSession, $auth, $followServ, $blockServ);
$webEngage   = new EngagementPagesController($webSession, $likeServ, $commentServ, $auth, $rate);
$webStrava   = new StravaPagesController($webSession, $auth, $stravaServ, $basePath . '/views');

// ---- JSON API ----
$router->post("{$apiBase}/auth/register",                fn($r) => $apiAuth->register($r));
$router->post("{$apiBase}/auth/login",                   fn($r) => $apiAuth->login($r));
$router->post("{$apiBase}/auth/refresh",                 fn($r) => $apiAuth->refresh($r));
$router->post("{$apiBase}/auth/logout",                  fn($r) => $apiAuth->logout($r),           [$requireBearer]);
$router->post("{$apiBase}/auth/logout-all",              fn($r) => $apiAuth->logoutAll($r),        [$requireBearer]);
$router->post("{$apiBase}/auth/password/change",         fn($r) => $apiAuth->changePassword($r),   [$requireBearer]);
$router->post("{$apiBase}/auth/password/forgot",         fn($r) => $apiAuth->forgotPassword($r));
$router->post("{$apiBase}/auth/password/reset",          fn($r) => $apiAuth->resetPassword($r));
$router->post("{$apiBase}/auth/email/verify",            fn($r) => $apiAuth->verifyEmail($r));
$router->post("{$apiBase}/auth/email/verify/resend",     fn($r) => $apiAuth->resendVerification($r));

$router->get("{$apiBase}/users/me",                       fn($r) => $apiUsers->me($r),       [$requireBearer]);
$router->patch("{$apiBase}/users/me",                     fn($r) => $apiUsers->updateMe($r), [$requireBearer]);
$router->delete("{$apiBase}/users/me",                    fn($r) => $apiUsers->deleteMe($r), [$requireBearer]);
// M3 Phase 0: One-time-Setting des public_handle. Eigener Endpoint
// statt PATCH /users/me, weil die Operation nicht idempotent ist
// (One-Time-Lock) und einen klaren Konflikt-Code (409) braucht.
$router->patch("{$apiBase}/users/me/handle",              fn($r) => $apiUsers->setHandle($r), [$requireBearer, $requireVerified]);

// ---- Routes API (M2 Phase 4) ----
// POST /routes ist sowohl Create als auch "Add Version" — Idempotenz
// über client_route_uuid (siehe RouteService).
// M5: Upload erfordert verifizierte E-Mail. Andere Operationen
// (Listing, Patch, Delete, Sharing) bleiben offen — wer schon Routen
// hat, kann die weiter verwalten.
$router->post("{$apiBase}/routes",                                fn($r) => $apiRoutes->upload($r),         [$requireBearer, $requireVerified]);
$router->get("{$apiBase}/routes",                                 fn($r) => $apiRoutes->listForUser($r),    [$requireBearer]);
$router->get("{$apiBase}/routes/{id}",                            fn($r) => $apiRoutes->show($r),           [$requireBearer]);
$router->patch("{$apiBase}/routes/{id}",                          fn($r) => $apiRoutes->patch($r),          [$requireBearer]);
$router->delete("{$apiBase}/routes/{id}",                         fn($r) => $apiRoutes->softDelete($r),     [$requireBearer]);
$router->get("{$apiBase}/routes/{id}/payload",                    fn($r) => $apiRoutes->downloadPayload($r), [$requireBearer]);
$router->post("{$apiBase}/routes/{id}/shares",                    fn($r) => $apiRoutes->createShare($r),    [$requireBearer]);
$router->get("{$apiBase}/routes/{id}/shares",                     fn($r) => $apiRoutes->listShares($r),     [$requireBearer]);
$router->delete("{$apiBase}/routes/{id}/shares/{shareId}",        fn($r) => $apiRoutes->revokeShare($r),    [$requireBearer]);

// Public Share-Endpoint — kein Bearer, kein CSRF (read-only GET).
$router->get("{$apiBase}/share/{token}",                          fn($r) => $apiShared->show($r));

// ---- Discovery (M3 Phase 2) ----
// Anonym OK, OptionalBearer setzt request->user nur bei gültigem
// Token. Eingeloggte Viewer bekommen ihre Block-Beziehungen aus
// dem Result-Set gefiltert.
$router->get("{$apiBase}/discover/routes",                        fn($r) => $apiDiscover->routes($r), [$optionalBearer]);
$router->get("{$apiBase}/discover/users",                         fn($r) => $apiDiscover->users($r),  [$optionalBearer]);

// ---- Profile (M3 Phase 3) ----
// Anonym OK. 404 bei nicht existentem oder gegenseitig blockierten
// User. Routes-Endpoint erbt die Discovery-Filter (limit/offset/sort/q).
$router->get("{$apiBase}/users/by-handle/{handle}",               fn($r) => $apiProfile->show($r),    [$optionalBearer]);
$router->get("{$apiBase}/users/by-handle/{handle}/routes",        fn($r) => $apiProfile->routes($r),  [$optionalBearer]);

// ---- Follow + Block (M3 Phase 4) ----
// Auth-required. POST /follow ist 201 (neu) bzw. 200 (idempotent).
// Block hat den Cascade-Cleanup: existierende Follows in beide
// Richtungen werden in derselben Transaktion entfernt.
$router->post("{$apiBase}/users/by-handle/{handle}/follow",       fn($r) => $apiSocial->follow($r),   [$requireBearer]);
$router->delete("{$apiBase}/users/by-handle/{handle}/follow",     fn($r) => $apiSocial->unfollow($r), [$requireBearer]);
$router->post("{$apiBase}/users/by-handle/{handle}/block",        fn($r) => $apiSocial->block($r),    [$requireBearer]);
$router->delete("{$apiBase}/users/by-handle/{handle}/block",      fn($r) => $apiSocial->unblock($r),  [$requireBearer]);
$router->get("{$apiBase}/users/me/follows",                       fn($r) => $apiSocial->meFollows($r),   [$requireBearer]);
$router->get("{$apiBase}/users/me/followers",                     fn($r) => $apiSocial->meFollowers($r), [$requireBearer]);
$router->get("{$apiBase}/users/me/blocks",                        fn($r) => $apiSocial->meBlocks($r),    [$requireBearer]);

// ---- Feed (M3 Phase 5) ----
// Auth-required. Public Routen aller gefolgten User, neueste zuerst.
$router->get("{$apiBase}/feed",                                   fn($r) => $apiFeed->show($r),       [$requireBearer]);

// ---- Likes (M4a) ----
// POST/DELETE auth-required, GET /likes anonym OK (OptionalBearer für
// das liked_by_viewer-Flag). Nicht-sichtbare Routen → 404.
$router->post("{$apiBase}/routes/{id}/like",                      fn($r) => $apiLike->like($r),    [$requireBearer]);
$router->delete("{$apiBase}/routes/{id}/like",                    fn($r) => $apiLike->unlike($r),  [$requireBearer]);
$router->get("{$apiBase}/routes/{id}/likes",                      fn($r) => $apiLike->summary($r), [$optionalBearer]);

// ---- Comments (M4b) ----
// GET anonym OK; POST erfordert verifizierte E-Mail (Spam-Schutz);
// DELETE durch Autor oder Routen-Owner. Nicht-sichtbar → 404.
$router->get("{$apiBase}/routes/{id}/comments",                   fn($r) => $apiComment->list($r),   [$optionalBearer]);
$router->post("{$apiBase}/routes/{id}/comments",                  fn($r) => $apiComment->create($r), [$requireBearer, $requireVerified]);
$router->delete("{$apiBase}/routes/{id}/comments/{cid}",          fn($r) => $apiComment->delete($r), [$requireBearer]);

// ---- Notifications (M4c) ----
// Alle auth-required. Pull-Modell: Client pollt Liste + unread-count.
$router->get("{$apiBase}/notifications",                          fn($r) => $apiNotif->list($r),        [$requireBearer]);
$router->get("{$apiBase}/notifications/unread-count",             fn($r) => $apiNotif->unreadCount($r), [$requireBearer]);
$router->post("{$apiBase}/notifications/read",                    fn($r) => $apiNotif->markAll($r),     [$requireBearer]);
$router->post("{$apiBase}/notifications/{nid}/read",              fn($r) => $apiNotif->markOne($r),     [$requireBearer]);

// ---- Avatare (M4d) ----
// Upload/Delete auth-required (+verifiziert für Upload). Serving ist
// public (eigene URL /u/{handle}/avatar weiter unten bei den Web-Routen).
// POST statt PUT: PHP parst multipart-Bodies nur bei POST in $_FILES.
$router->post("{$apiBase}/users/me/avatar",                       fn($r) => $apiAvatar->upload($r), [$requireBearer, $requireVerified]);
$router->delete("{$apiBase}/users/me/avatar",                     fn($r) => $apiAvatar->delete($r), [$requireBearer]);

// ---- Integrationen / Strava (M4e) ----
$router->get("{$apiBase}/integrations/strava",                    fn($r) => $apiIntegr->stravaStatus($r),     [$requireBearer]);
$router->post("{$apiBase}/integrations/strava/import",            fn($r) => $apiIntegr->stravaImport($r),     [$requireBearer, $requireVerified]);
$router->delete("{$apiBase}/integrations/strava",                 fn($r) => $apiIntegr->stravaDisconnect($r), [$requireBearer]);

$router->get("{$apiBase}/heatmap",                                fn($r) => $apiHeatmap->index($r));

// ---- Web pages ----
$router->get('/',                  fn($r) => Response::redirect('/dashboard'));
$router->get('/login',             fn($r) => $webAuth->showLogin($r));
$router->post('/login',            fn($r) => $webAuth->doLogin($r),           [$csrf]);
$router->get('/register',          fn($r) => $webAuth->showRegister($r));
$router->post('/register',         fn($r) => $webAuth->doRegister($r),        [$csrf]);
$router->get('/forgot-password',   fn($r) => $webAuth->showForgot($r));
$router->post('/forgot-password',  fn($r) => $webAuth->doForgot($r),          [$csrf]);
$router->get('/reset-password',    fn($r) => $webAuth->showReset($r));
$router->post('/reset-password',   fn($r) => $webAuth->doReset($r),           [$csrf]);
$router->get('/verify-email',      fn($r) => $webAuth->showVerify($r));
$router->get('/dashboard',         fn($r) => $webHome->show($r));
$router->post('/logout',           fn($r) => $webAuth->doLogout($r),          [$csrf]);
// H5: Einziger Punkt, an dem ein Refresh-Token-Cookie konsumiert wird.
// Pfad-Scoped Cookie sorgt dafür, dass es nur hier eintrifft.
$router->get('/auth/web-refresh',  fn($r) => $webRefresh->handle($r));

// ---- Routes Web-UI (M2 Phase 6) ----
$router->get ('/routes',                                 fn($r) => $webRoutes->index($r));
$router->get ('/routes/new',                             fn($r) => $webRoutes->showUpload($r));
$router->post('/routes',                                 fn($r) => $webRoutes->doUpload($r),       [$csrf]);
$router->get ('/routes/{id}',                            fn($r) => $webRoutes->show($r));
$router->get ('/routes/{id}/edit',                       fn($r) => $webRoutes->showEdit($r));
$router->post('/routes/{id}/update',                     fn($r) => $webRoutes->doUpdate($r),       [$csrf]);
$router->post('/routes/{id}/delete',                     fn($r) => $webRoutes->doDelete($r),       [$csrf]);
$router->get ('/routes/{id}/download',                   fn($r) => $webRoutes->download($r));
// GeoJSON für die Detail-Karte (same-origin-Fetch, Owner-geschützt).
$router->get ('/routes/{id}/geojson',                    fn($r) => $webRoutes->geojson($r));
$router->post('/routes/{id}/shares',                     fn($r) => $webRoutes->doCreateShare($r),  [$csrf]);
$router->post('/routes/{id}/shares/{shareId}/revoke',    fn($r) => $webRoutes->doRevokeShare($r),  [$csrf]);

// Public Share-Page — kein Login, kein CSRF (read-only GET).
$router->get ('/share/{token}',                          fn($r) => $webShare->show($r));
$router->get ('/share/{token}/geojson',                  fn($r) => $webShare->geojson($r));

// ---- Settings Web-UI (M3 Phase 0) ----
$router->get ('/settings/handle',                        fn($r) => $webSetting->showHandle($r));
$router->post('/settings/handle',                        fn($r) => $webSetting->doHandle($r), [$csrf]);
$router->get ('/settings/avatar',                        fn($r) => $webSetting->showAvatar($r));
$router->post('/settings/avatar',                        fn($r) => $webSetting->doAvatar($r), [$csrf]);
$router->post('/settings/avatar/delete',                 fn($r) => $webSetting->doAvatarDelete($r), [$csrf]);

// M4e: Strava-Integration (Web-Flow + OAuth-Callback).
$router->get ('/settings/integrations',                  fn($r) => $webStrava->settings($r));
$router->get ('/auth/strava/connect',                    fn($r) => $webStrava->connect($r));
$router->get ('/auth/strava/callback',                   fn($r) => $webStrava->callback($r));
$router->post('/settings/integrations/import',           fn($r) => $webStrava->import($r),     [$csrf]);
$router->post('/settings/integrations/disconnect',       fn($r) => $webStrava->disconnect($r), [$csrf]);

// ---- Discovery / Profile / Feed Web-UI (M3 Phase 6) ----
// Anonym OK auf /discover/* und /u/{handle}*. /feed verlangt Login.
// Social-Aktionen (Follow/Unfollow/Block/Unblock) sind POST + CSRF + Login.
$router->get ('/discover',                               fn($r) => $webDiscover->discoverRoutes($r));
$router->get ('/discover/users',                         fn($r) => $webDiscover->discoverUsers($r));
$router->get ('/heatmap',                                fn($r) => $webDiscover->heatmap($r));
$router->get ('/u/{handle}',                             fn($r) => $webDiscover->profile($r));
$router->get ('/u/{handle}/r/{id}',                      fn($r) => $webDiscover->profileRoute($r));
$router->get ('/u/{handle}/r/{id}/geojson',              fn($r) => $webDiscover->profileRouteGeojson($r));
$router->get ('/feed',                                   fn($r) => $webDiscover->feed($r));
$router->get ('/notifications',                          fn($r) => $webDiscover->notifications($r));

// Avatar-Serving (public, eigene Bytes/Placeholder). M4d.
$router->get ('/u/{handle}/avatar',                      fn($r) => $apiAvatar->serve($r));
$router->post('/u/{handle}/follow',                      fn($r) => $webSocial->follow($r),    [$csrf]);
$router->post('/u/{handle}/unfollow',                    fn($r) => $webSocial->unfollow($r),  [$csrf]);
$router->post('/u/{handle}/block',                       fn($r) => $webSocial->block($r),     [$csrf]);
$router->post('/u/{handle}/unblock',                     fn($r) => $webSocial->unblock($r),   [$csrf]);

// ---- Engagement Web-UI (M4a Likes) ----
$router->post('/u/{handle}/r/{id}/like',                 fn($r) => $webEngage->like($r),          [$csrf]);
$router->post('/u/{handle}/r/{id}/unlike',               fn($r) => $webEngage->unlike($r),        [$csrf]);
$router->post('/u/{handle}/r/{id}/comment',              fn($r) => $webEngage->comment($r),       [$csrf]);
$router->post('/u/{handle}/r/{id}/comments/{cid}/delete', fn($r) => $webEngage->commentDelete($r), [$csrf]);

// Healthcheck
$router->get('/healthz', function ($r): void {
    Response::json(['status' => 'ok', 'time' => gmdate('Y-m-d\TH:i:s\Z')]);
});

$router->dispatch($request);
