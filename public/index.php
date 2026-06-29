<?php
declare(strict_types=1);

/*
 * GRAVA — front controller for API, web and CLI.
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
use App\Controllers\Api\HeatmapLinesController;
use App\Controllers\Api\IntegrationsController;
use App\Controllers\Api\LikeController;
use App\Controllers\Api\NotificationController;
use App\Controllers\Api\PushDeviceController;
use App\Controllers\Api\ProfileController;
use App\Controllers\Api\ReferralController;
use App\Controllers\Api\SocialController;
use App\Controllers\Web\AuthPagesController;
use App\Controllers\Web\DashboardController;
use App\Controllers\Web\DiscoveryPagesController;
use App\Controllers\Web\EngagementPagesController;
use App\Controllers\Web\StravaPagesController;
use App\Controllers\Web\PublicSharePageController;
use App\Controllers\Web\ReferralPagesController;
use App\Controllers\Web\AdminReferralPagesController;
use App\Controllers\Web\RoutePagesController;
use App\Controllers\Web\SettingsPagesController;
use App\Controllers\Web\SurfaceCheckController;
use App\Controllers\Web\LegalPagesController;
use App\Controllers\Web\SocialPagesController;
use App\Controllers\Web\WebRefreshController;
use App\Controllers\Web\LandingController;
use App\Http\Middleware\Csrf;
use App\Discovery\BlockService;
use App\Discovery\DiscoveryService;
use App\Discovery\FeedService;
use App\Discovery\FollowService;
use App\Discovery\ProfileService;
use App\Engagement\CommentService;
use App\Engagement\LikeService;
use App\Engagement\NotificationService;
use App\Push\ApnsConfig;
use App\Push\ApnsHttpClient;
use App\Push\PushDeviceRepository;
use App\Push\PushService;
use App\Heatmap\HeatmapService;
use App\Heatmap\HeatmapLinesService;
use App\Heatmap\RouteSurfaceService;
use App\Heatmap\SurfaceProjector;
use App\Heatmap\ValhallaClient;
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
use App\Referral\ReferralService;
use App\Routes\GeometryParser;
use App\Routes\GeometryStats;
use App\Routes\RouteGeoJson;
use App\Routes\RouteHintParser;
use App\Routes\RouteHintRepository;
use App\Routes\RouteHintService;
use App\Routes\RouteRepository;
use App\Routes\RouteService;
use App\Routes\RouteStorage;
use App\Routes\ShareTokenService;
use App\Routes\SurfaceTrack;
use App\Routes\RouteInsights;
use App\Game\GameConfig;
use App\Game\GameRepository;
use App\Game\EdgeRecalculator;
use App\Game\ValhallaEdgeMatcher;
use App\Game\GameIngestionService;
use App\Game\TerritoryTakeoverNotifier;
use App\Game\GameReadService;
use App\Game\GameRecomputeService;
use App\Game\EdgeRecordService;
use App\Game\EdgeRecordBackfillService;
use App\Game\PlayerLeaderboardService;
use App\Game\SegmentSpeedService;
use App\Controllers\Api\GameController;
use App\Controllers\Api\EdgeRecordController;
use App\Controllers\Api\PlayerLeaderboardController;
use App\Controllers\Api\SegmentSpeedController;
use App\Database\Db;

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
    // Verzeichnis bei Bedarf anlegen, sonst schlägt file_put_contents still
    // fehl und /internal/logtail sieht nie ein Logfile.
    @mkdir($basePath . '/storage/logs', 0775, true);
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
// M7: Referrals — vor AuthService, weil AuthService es für die
// Werber-Verknüpfung bei Registrierung/Verifizierung benötigt.
$referrals  = new ReferralService($config);
$auth       = new AuthService($config, $passwords, $tokens, $mailer, $referrals);
$cookieAuth = new CookieAuth($config, $tokens);
$webSession = new WebSession($config);

// Push (APNs) + Notifications. Früh verdrahtet, weil sowohl die
// Game-Ingestion (territory_taken) als auch die Engagement-Dienste
// (follow/like/comment) den NotificationService brauchen. Ohne
// APNS_*-Config ist Push deaktiviert (Notifications werden weiter erzeugt).
$apnsKeyPath = (string)($config->get('APNS_KEY_PATH', '') ?? '');
$apnsKeyPem  = '';
if ($apnsKeyPath !== '') {
    $apnsKeyAbs = str_starts_with($apnsKeyPath, '/') ? $apnsKeyPath : $basePath . '/' . $apnsKeyPath;
    $apnsKeyPem = (string)(@file_get_contents($apnsKeyAbs) ?: '');
}
$apnsConfig  = new ApnsConfig(
    enabled:  $config->bool('APNS_ENABLED', false),
    keyId:    (string)($config->get('APNS_KEY_ID', '') ?? ''),
    teamId:   (string)($config->get('APNS_TEAM_ID', '98JR57G9M7') ?? ''),
    bundleId: (string)($config->get('APNS_BUNDLE_ID', 'world.grava.app') ?? ''),
    keyPem:   $apnsKeyPem,
);
$pushDevices = new PushDeviceRepository();
$pushServ    = new PushService($pushDevices, new ApnsHttpClient($apnsConfig));
// S9: Per-Typ-Push-Präferenzen — gaten den APNs-Versand (In-App bleibt).
$notifPrefs  = new \App\Engagement\NotificationPreferenceRepository();
$notifServ   = new NotificationService($pushServ, $notifPrefs);

// Stufe 1 Gamification. Lokaler Valhalla via VALHALLA_BASE_URL (Fallback VALHALLA_URL).
$gameEnabled  = $config->bool('GAME_ENABLED', true);
$gameConfig   = new GameConfig(Db::pdo());
$gameRepo     = new GameRepository(Db::pdo());
$gameRecalc   = new EdgeRecalculator($gameRepo, $gameConfig);
// S8 Privatzonen (§17): Repository früh, da Ingestion + Heatmap es brauchen.
$privacyZoneRepo    = new \App\Privacy\PrivacyZoneRepository(Db::pdo());
$privacyTrimmer     = new \App\Privacy\RoutePrivacyTrimmer();
$gameValhalla = new ValhallaClient(
    (string)($config->get('VALHALLA_BASE_URL', $config->get('VALHALLA_URL', 'http://localhost:8002')) ?? 'http://localhost:8002'),
    (string)($config->get('VALHALLA_COSTING', 'bicycle') ?? 'bicycle'),
);
$gameMatcher   = new ValhallaEdgeMatcher($gameValhalla);
$gameTakeovers = new TerritoryTakeoverNotifier($gameRepo, $notifServ);
$gameIngest    = new GameIngestionService($gameMatcher, $gameRepo, $gameRecalc, $gameConfig, Db::pdo(), $gameTakeovers, $privacyZoneRepo);
$edgeRecords   = new EdgeRecordService($gameRepo, $gameConfig);
$gameRead      = new GameReadService($gameRepo, $gameConfig, $edgeRecords);
$gameRecompute = new GameRecomputeService($gameRepo, $gameRecalc);
$privacyZoneSvc = new \App\Privacy\PrivacyZoneService($privacyZoneRepo, $gameRepo, $gameRecalc, Db::pdo());

// Rush / Group-Ride-Übernahme (GAME_RUSH_BACKEND.md). Früh verdrahtet, weil der
// Cron-Tick (game:rush-tick) bereits im CLI-Dispatch unten verfügbar sein muss.
// CrewRepository wird hier (statt erst beim API-Block) erzeugt und unten
// wiederverwendet.
$gameCrewRepo = new \App\Game\Crew\CrewRepository(Db::pdo());
$gameFactionRepo = new \App\Game\Faction\FactionRepository(Db::pdo());
$gameRushRepo = new \App\Game\Rush\RushRepository(Db::pdo());
$gameRushSvc  = new \App\Game\Rush\RushService(
    Db::pdo(), $gameRushRepo, $gameCrewRepo, $gameRepo, $gameRecalc, $gameConfig,
    $notifServ, new \App\Game\Admin\GameAuditService(Db::pdo()),
);
// CrewService ebenfalls früh: der CLI-Befehl game:heal-crews (§12.1) braucht ihn
// vor dem CLI-Dispatch. Unten im API-Block nur noch wiederverwendet.
$gameCrewSvc  = new \App\Game\Crew\CrewService(
    Db::pdo(), $gameCrewRepo, $gameRepo, $gameRecalc, $gameConfig,
    new \App\Game\Admin\GameAuditService(Db::pdo()),
    $gameFactionRepo,
);
// §12.1: Crew-Invariante bei Account-Löschung (Captain promoten / Solo-Crew lösen).
$auth->setCrewService($gameCrewSvc);

// M2: Routes-Stack
$routeStorage = new RouteStorage($config);
$routeRepo    = new RouteRepository();
// M8: Wegpunkt-Hinweise — parst <wpt>-Hinweise aus dem GPX-Payload beim
// Upload und liefert sie für Route-JSON + GeoJSON-Antworten.
$routeHints   = new RouteHintService(new RouteHintParser(), new RouteHintRepository());
$elevationThresholdM = (float)($config->get('ROUTE_ELEVATION_THRESHOLD_M', GeometryStats::DEFAULT_ELEVATION_HYSTERESIS_M) ?? GeometryStats::DEFAULT_ELEVATION_HYSTERESIS_M);
$routeService = new RouteService($routeRepo, $routeStorage, new GeometryParser(), new GeometryStats($elevationThresholdM), $routeHints, $gameEnabled ? $gameIngest : null);
$edgeBackfill = new EdgeRecordBackfillService($gameRepo, $gameIngest, $routeService, new GeometryParser());
$shareTokens  = new ShareTokenService($routeRepo);
// GeoJSON-Konverter für die Web-Karten (eigener Parser, zustandslos).
// SurfaceTrack färbt GPX-Tracks mit <ge:surfaceScore> ein.
$routeGeoJson = new RouteGeoJson(new GeometryParser(), new SurfaceTrack());
$routeGpxExport = new \App\Routes\RouteGpxExportService(
    $routeService,
    $routeGeoJson,
    $privacyTrimmer,
    $privacyZoneRepo,
);
// Höhenprofil + Untergrund-Verteilung für die Detail-Seiten.
$routeInsights = new RouteInsights(new GeometryParser(), new SurfaceTrack());

// M6: Heatmap-Streckenlinien via Map-Matching (Valhalla). Der Valhalla-Client
// wird nur im Precompute (CLI cron:heatmap-lines) benutzt, nie im Request-Pfad.
$valhalla = new ValhallaClient(
    (string)($config->get('VALHALLA_URL', 'http://localhost:8002') ?? 'http://localhost:8002'),
    (string)($config->get('VALHALLA_COSTING', 'bicycle') ?? 'bicycle'),
);
$heatmapLines = new HeatmapLinesService(
    $valhalla,
    $routeService,
    new GeometryParser(),
    new SurfaceTrack(),
    (int)($config->get('HEATMAP_LINES_MIN_ROUTES', 1) ?? 1),
    (int)($config->get('HEATMAP_LINES_RESAMPLE_M', 20) ?? 20),
    15000,
    $privacyZoneRepo,
);

// M9: Surface-Check — projiziert vorhandene Crowd-Belagsdaten (heatmap_edges)
// auf eine hochgeladene Fremd-Route. Standard ist die geometrische Projektion
// (kein Valhalla im Request-Pfad, prod-tauglich); der Valhalla-Client dient
// nur dem optionalen "Details"-Pfad und ist hier wiederverwendet.
$surfaceProjector = new SurfaceProjector(
    (float)($config->get('SURFACE_PROJECT_THRESHOLD_M', 25) ?? 25),
    (int)($config->get('SURFACE_PROJECT_RESAMPLE_M', 20) ?? 20),
);
$routeSurface = new RouteSurfaceService(
    new GeometryParser(),
    new SurfaceTrack(),
    $surfaceProjector,
    $valhalla,
);

// ---------------------------------------------------------------------------
// CLI dispatch
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli') {
    $cli = new Commands($basePath, $tokens, $routeService, $config, new NotificationService(), new HeatmapService(), $heatmapLines, $gameRecompute, $gameRushSvc, $gameCrewSvc, $edgeBackfill);
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
    "Content-Security-Policy: default-src 'self'; "
  . "script-src 'self' https://www.googletagmanager.com; "
  . "style-src 'self' 'unsafe-inline'; "
  . "img-src 'self' data: https://*.tile.openstreetmap.org https://www.googletagmanager.com https://*.google-analytics.com; "
  . "connect-src 'self' https://www.googletagmanager.com https://*.google-analytics.com https://*.analytics.google.com; "
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
// $notifServ / $pushDevices wurden bereits oben (vor dem Game-Block) verdrahtet.
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
    $routeGpxExport,
    $routeRepo,
    $gameRepo,
);
$gameRideSummary = new \App\Game\GameRideSummaryService($gameRepo, $gameRushRepo, $privacyZoneRepo, $privacyTrimmer);
$gameAtRisk      = new \App\Game\GameEdgesAtRiskService($gameRepo, $gameConfig, $gameRecalc, $privacyZoneRepo);

$apiAuth    = new AuthController($auth, $rate);
$apiUsers   = new UserController($auth);
$apiRoutes  = new RouteController($routeService, $shareTokens, $config, $referrals);
$apiShared  = new SharedRouteController($shareTokens);
$apiDiscover = new DiscoverController($discovery);
$apiProfile  = new ProfileController($profileServ);
$apiSocial   = new SocialController($followServ, $blockServ);
$apiFeed     = new FeedController($feedServ);
$apiLike     = new LikeController($likeServ);
$apiComment  = new CommentController($commentServ, $rate);
$apiNotif    = new NotificationController($notifServ, $notifPrefs);
$apiPushDev  = new PushDeviceController($pushDevices);
$apiAvatar   = new AvatarController($avatarServ);
$apiIntegr   = new IntegrationsController($stravaServ);
$apiHeatmap  = new HeatmapController($heatmapServ);
$personalHeatmap = new \App\Heatmap\PersonalHeatmapService($routeStorage, new GeometryParser(), $privacyZoneRepo);
$apiMeHeatmap = new \App\Controllers\Api\MeHeatmapController($personalHeatmap);
$apiHeatmapLines = new HeatmapLinesController($heatmapLines);
$apiReferral = new ReferralController($referrals);
$apiGame = new GameController($gameRead, $gameRepo, $gameIngest, $gameConfig, $routeService, new GeometryParser(), $gameRideSummary, $gameAtRisk);
$apiEdgeRecords = new EdgeRecordController($edgeRecords);
$apiPlayerBoard = new PlayerLeaderboardController(new PlayerLeaderboardService($gameRepo, $gameConfig));
$apiSegment = new SegmentSpeedController(new SegmentSpeedService($gameRepo, $gameConfig));
$apiPrivacyZone = new \App\Controllers\Api\PrivacyZoneController($privacyZoneSvc);
// $gameCrewRepo, $gameFactionRepo, $gameCrewSvc wurden bereits oben (vor dem
// CLI-Dispatch) verdrahtet.
$apiCrew = new \App\Controllers\Api\CrewController($gameCrewSvc);
$apiCrewLogo = new \App\Controllers\Api\CrewLogoController(
    new \App\Game\Crew\CrewLogoService($gameCrewRepo, $config),
);
$apiRush = new \App\Controllers\Api\RushController($gameRushSvc);
$gameFactionSvc = new \App\Game\Faction\FactionService(
    Db::pdo(), $gameCrewRepo, $gameFactionRepo, $gameCrewSvc, $gameConfig,
    new \App\Game\Admin\GameAuditService(Db::pdo()),
);
$apiFaction = new \App\Controllers\Api\FactionController($gameFactionSvc);
$presenceRepo = new \App\Presence\PresenceRepository(Db::pdo());
$presenceSvc  = new \App\Presence\PresenceService($presenceRepo, $gameConfig);
$apiPresence  = new \App\Controllers\Api\PresenceController($presenceSvc);
$communitySvc = new \App\Community\CommunityTodayService($routeRepo, $presenceSvc, $gameRepo);
$apiCommunity = new \App\Controllers\Api\CommunityTodayController($communitySvc);
$webAuth    = new AuthPagesController($auth, $cookieAuth, $webSession, $rate, $basePath . '/views');
$webHome    = new DashboardController($webSession, $auth, $basePath . '/views');
$webFeatures = new \App\Controllers\Web\FeaturesPagesController($webSession, $auth, $basePath . '/views');
$webRefresh = new WebRefreshController($cookieAuth, $webSession);
$webRoutes  = new RoutePagesController($webSession, $auth, $routeService, $shareTokens, $config, $routeGeoJson, $basePath . '/views', $routeInsights);
$webShare   = new PublicSharePageController($shareTokens, $basePath . '/views', $routeService, $routeGeoJson, $routeInsights, $privacyZoneRepo, $privacyTrimmer);
$webSetting = new SettingsPagesController($webSession, $auth, $basePath . '/views', $avatarServ);
$webDiscover = new DiscoveryPagesController($webSession, $auth, $discovery, $profileServ, $feedServ, $basePath . '/views', $likeServ, $commentServ, $notifServ, $heatmapServ, $routeService, $routeGeoJson, $routeInsights, $privacyZoneRepo, $privacyTrimmer);
$webSocial   = new SocialPagesController($webSession, $auth, $followServ, $blockServ);
$webEngage   = new EngagementPagesController($webSession, $likeServ, $commentServ, $auth, $rate);
$webStrava   = new StravaPagesController($webSession, $auth, $stravaServ, $basePath . '/views');
$webSurface  = new SurfaceCheckController($webSession, $auth, $routeSurface, $config, $basePath . '/views');
$webReferral = new ReferralPagesController($config, $basePath . '/views');
$webLegal    = new LegalPagesController($basePath . '/views');
$webAdminRef = new AdminReferralPagesController($webSession, $auth, $referrals, $config, $basePath . '/views');
$webLanding  = new LandingController($basePath . '/views');

// ---- Game-Admin-Dashboard (Stufe 1) — nur unter admin.grava.world erreichbar ----
$adminGuard      = new \App\Game\Admin\AdminGuard((string)$config->get('ADMIN_EMAILS', ''));
$gameAudit       = new \App\Game\Admin\GameAuditService(Db::pdo());
$gameAdminSvc    = new \App\Game\Admin\GameAdminService(Db::pdo(), $gameRepo, $gameConfig);
$gameCfgAdmin    = new \App\Game\Admin\GameConfigAdminService(Db::pdo(), $gameConfig, $gameAudit);
$gameModeration  = new \App\Game\Admin\GameModerationService(Db::pdo(), $gameConfig);
$gamePassAdmin   = new \App\Game\Admin\GamePassAdminService(Db::pdo(), $gameRepo, $gameRecalc, $gameAudit);
$gameUserFlag    = new \App\Game\Admin\GameUserFlagService(Db::pdo(), $gameAudit);
$webGameAdmin    = new \App\Controllers\Web\Admin\GameAdminController(
    $webSession, $auth, $adminGuard, $gameAdminSvc, $gameCfgAdmin, $gameConfig,
    $gameModeration, $gameRecompute, $gameAudit, $gameRepo, $gameIngest, $routeService,
    new GeometryParser(), $gameValhalla, $basePath . '/views',
);
$webGameEdge     = new \App\Controllers\Web\Admin\GameEdgeInspectorController(
    $webSession, $auth, $adminGuard, $gameAdminSvc, $gamePassAdmin, $gameUserFlag,
    $gameRecalc, $gameRepo, $gameAudit, $basePath . '/views',
);
$routeAdminSvc   = new \App\Routes\RouteAdminService(Db::pdo());
$webAdminUploads = new \App\Controllers\Web\Admin\AdminUploadsController(
    $webSession, $auth, $adminGuard, $routeAdminSvc, $routeService, $basePath . '/views',
);

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

// S8 Privatzonen / Heimat-Schutz (§17). Reine Account-Operation (Bearer).
$router->get("{$apiBase}/me/privacy-zone",                fn($r) => $apiPrivacyZone->show($r),   [$requireBearer]);
$router->put("{$apiBase}/me/privacy-zone",                fn($r) => $apiPrivacyZone->put($r),    [$requireBearer]);
$router->delete("{$apiBase}/me/privacy-zone",             fn($r) => $apiPrivacyZone->delete($r), [$requireBearer]);

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
// Personensuche: Teilstring über Handle + Anzeigename. Anonym OK
// (OptionalBearer ergänzt is_self/is_followed_by_viewer). Statische
// Route vor {handle}, kollidiert aber ohnehin nicht (eigenes Segment).
// Antwortform deckungsgleich mit /followers/following.
$router->get("{$apiBase}/users/search",                           fn($r) => $apiProfile->search($r),  [$optionalBearer]);
// Anonym OK. 404 bei nicht existentem oder gegenseitig blockierten
// User. Routes-Endpoint erbt die Discovery-Filter (limit/offset/sort/q).
$router->get("{$apiBase}/users/by-handle/{handle}",               fn($r) => $apiProfile->show($r),    [$optionalBearer]);
$router->get("{$apiBase}/users/by-handle/{handle}/routes",        fn($r) => $apiProfile->routes($r),  [$optionalBearer]);
// Follower-/Following-Listen: anonym OK (OptionalBearer ergänzt nur die
// viewer-relativen Flags is_self/is_followed_by_viewer + Block-Filter).
// Volle PublicProfile-Objekte als users[] plus pagination.
$router->get("{$apiBase}/users/by-handle/{handle}/followers",     fn($r) => $apiProfile->followers($r), [$optionalBearer]);
$router->get("{$apiBase}/users/by-handle/{handle}/following",     fn($r) => $apiProfile->following($r), [$optionalBearer]);

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
// S9: Per-Typ-Push-Präferenzen (vor der {nid}-Route, sonst greift {nid}).
$router->get("{$apiBase}/notifications/preferences",              fn($r) => $apiNotif->preferences($r),    [$requireBearer]);
$router->put("{$apiBase}/notifications/preferences",              fn($r) => $apiNotif->setPreferences($r), [$requireBearer]);
$router->post("{$apiBase}/notifications/{nid}/read",              fn($r) => $apiNotif->markOne($r),     [$requireBearer]);

// ---- Push-Geräte (APNs) ----
// Token-Registrierung/-Abmeldung, auth-required. Upsert auf Token.
$router->post("{$apiBase}/notifications/devices",                 fn($r) => $apiPushDev->register($r),   [$requireBearer]);
$router->delete("{$apiBase}/notifications/devices/{token}",       fn($r) => $apiPushDev->unregister($r), [$requireBearer]);

// ---- Avatare (M4d) ----
// Upload/Delete auth-required (+verifiziert für Upload). Serving ist
// public (eigene URL /u/{handle}/avatar weiter unten bei den Web-Routen).
// POST statt PUT: PHP parst multipart-Bodies nur bei POST in $_FILES.
$router->post("{$apiBase}/users/me/avatar",                       fn($r) => $apiAvatar->upload($r), [$requireBearer, $requireVerified]);
$router->delete("{$apiBase}/users/me/avatar",                     fn($r) => $apiAvatar->delete($r), [$requireBearer]);

// ---- Integrationen / Strava (M4e) ----
$router->get("{$apiBase}/integrations/strava",                    fn($r) => $apiIntegr->stravaStatus($r),     [$requireBearer]);
$router->get("{$apiBase}/integrations/strava/connect-url",        fn($r) => $apiIntegr->stravaConnectUrl($r), [$requireBearer]);
$router->post("{$apiBase}/integrations/strava/import",            fn($r) => $apiIntegr->stravaImport($r),     [$requireBearer, $requireVerified]);
$router->post("{$apiBase}/integrations/strava/share",             fn($r) => $apiIntegr->stravaShare($r),      [$requireBearer]);
$router->delete("{$apiBase}/integrations/strava",                 fn($r) => $apiIntegr->stravaDisconnect($r), [$requireBearer]);

$router->get("{$apiBase}/heatmap",                                fn($r) => $apiHeatmap->index($r));
$router->get("{$apiBase}/heatmap/lines",                          fn($r) => $apiHeatmapLines->index($r));
$router->get("{$apiBase}/me/heatmap",                             fn($r) => $apiMeHeatmap->me($r), [$requireBearer]);

// ---- Referrals (M7) ----
// Eigener Code/Link + Statistik. Kein öffentliches Leaderboard.
$router->get("{$apiBase}/referrals/me",                           fn($r) => $apiReferral->me($r), [$requireBearer]);

// ---- Game (Stufe 1 — Territorialspiel) ----
$router->get("{$apiBase}/game/ownership/map",      fn($r) => $apiGame->ownershipMap($r), [$optionalBearer]);
$router->get("{$apiBase}/game/edges",              fn($r) => $apiGame->edges($r),    [$optionalBearer]);
$router->get("{$apiBase}/game/edges/{id}",         fn($r) => $apiGame->edge($r),     [$optionalBearer]);
$router->get("{$apiBase}/game/edges/{id}/records", fn($r) => $apiEdgeRecords->records($r), [$optionalBearer]);
$router->get("{$apiBase}/game/me",                 fn($r) => $apiGame->me($r),       [$requireBearer]);
$router->get("{$apiBase}/game/me/at-risk",         fn($r) => $apiGame->atRisk($r),   [$requireBearer]);
$router->get("{$apiBase}/game/config",             fn($r) => $apiGame->config($r),   [$requireBearer]);
// Solo-/Spieler-Rangliste (S7): world anonym, friends/me brauchen Bearer.
$router->get("{$apiBase}/game/leaderboard",        fn($r) => $apiPlayerBoard->index($r), [$optionalBearer]);
// Segment-Speed / Tempo-Wertung: Leaderboard je Kante (OptionalBearer; friends/me
// brauchen Bearer), eigene Bestzeiten (Bearer).
$router->get("{$apiBase}/game/segments/{id}/leaderboard", fn($r) => $apiSegment->leaderboard($r), [$optionalBearer]);
$router->get("{$apiBase}/game/me/segments",        fn($r) => $apiSegment->mySegments($r), [$requireBearer]);
$router->post("{$apiBase}/game/ingest/{route_id}", fn($r) => $apiGame->reingest($r), [$requireBearer]);
$router->get("{$apiBase}/game/rides/{route_id}/summary", fn($r) => $apiGame->rideSummary($r), [$requireBearer]);
$router->get ("{$apiBase}/game/crews/me",          fn($r) => $apiCrew->me($r),       [$requireBearer]);
$router->post("{$apiBase}/game/crews/join",        fn($r) => $apiCrew->join($r),     [$requireBearer]);
$router->post("{$apiBase}/game/crews/leave",       fn($r) => $apiCrew->leave($r),    [$requireBearer]);
$router->post("{$apiBase}/game/crews/transfer",    fn($r) => $apiCrew->transfer($r), [$requireBearer]);
$router->post("{$apiBase}/game/crews",             fn($r) => $apiCrew->create($r),   [$requireBearer]);
// ---- Game (Rush / Group-Ride-Übernahme — GAME_RUSH_BACKEND.md §5) ----
$router->post  ("{$apiBase}/game/crews/me/rush",   fn($r) => $apiRush->create($r),   [$requireBearer]);
$router->get   ("{$apiBase}/game/crews/me/rush",   fn($r) => $apiRush->myRush($r),   [$requireBearer]);
$router->post  ("{$apiBase}/game/rush/{id}/rsvp",  fn($r) => $apiRush->rsvp($r),     [$requireBearer]);
$router->delete("{$apiBase}/game/rush/{id}",       fn($r) => $apiRush->cancel($r),   [$requireBearer]);
// ---- Game (Stufe 3 — Fraktionen) ----
$router->get   ("{$apiBase}/game/factions/map",          fn($r) => $apiFaction->map($r),       [$optionalBearer]);
$router->get   ("{$apiBase}/game/factions",              fn($r) => $apiFaction->standings($r));
$router->post  ("{$apiBase}/game/crews/{slug}/faction",  fn($r) => $apiFaction->set($r),       [$requireBearer]);
$router->delete("{$apiBase}/game/crews/{slug}/faction",  fn($r) => $apiFaction->clear($r),     [$requireBearer]);
$router->post  ("{$apiBase}/game/crews/{slug}/captain",  fn($r) => $apiCrew->claimCaptain($r), [$requireBearer]);
// ---- Crew-Logo (GAME_CREW_LOGO_BACKEND.md) — Captain schreibt; Serving public unten ----
$router->post  ("{$apiBase}/game/crews/{slug}/logo",     fn($r) => $apiCrewLogo->upload($r),   [$requireBearer]);
$router->delete("{$apiBase}/game/crews/{slug}/logo",     fn($r) => $apiCrewLogo->delete($r),   [$requireBearer]);
$router->get ("{$apiBase}/game/crews/{slug}/leaderboard", fn($r) => $apiCrew->leaderboard($r), [$requireBearer]);
$router->get ("{$apiBase}/game/crews/{slug}",      fn($r) => $apiCrew->show($r),     [$requireBearer]);

// ---- Presence (Live-Aktiv-Zähler — PRESENCE_BACKEND.md) ----
$router->post("{$apiBase}/presence/heartbeat",     fn($r) => $apiPresence->heartbeat($r), [$optionalBearer]);
$router->post("{$apiBase}/presence/stop",          fn($r) => $apiPresence->stop($r),      [$optionalBearer]);
$router->get ("{$apiBase}/presence/active",       fn($r) => $apiPresence->active($r));

// ---- Community (Tages-Aggregat — COMMUNITY_TODAY_BACKEND.md) ----
$router->get ("{$apiBase}/community/today",       fn($r) => $apiCommunity->today($r));

// ---- Web pages ----
$router->get('/',                  fn($r) => Response::redirect('/dashboard'));
$router->get('/landing',           fn($r) => $webLanding->home());
$router->get('/login',             fn($r) => $webAuth->showLogin($r));
$router->post('/login',            fn($r) => $webAuth->doLogin($r),           [$csrf]);
$router->get('/register',          fn($r) => $webAuth->showRegister($r));
$router->post('/register',         fn($r) => $webAuth->doRegister($r),        [$csrf]);
$router->get('/forgot-password',   fn($r) => $webAuth->showForgot($r));
$router->post('/forgot-password',  fn($r) => $webAuth->doForgot($r),          [$csrf]);
$router->get('/reset-password',    fn($r) => $webAuth->showReset($r));
$router->post('/reset-password',   fn($r) => $webAuth->doReset($r),           [$csrf]);
$router->get('/verify-email',      fn($r) => $webAuth->showVerify($r));
// Öffentliche Rechtsseiten (M5): anonym, kein Login, kein Redirect.
$router->get('/privacy',           fn($r) => $webLegal->privacy($r));
$router->get('/terms',             fn($r) => $webLegal->terms($r));
$router->get('/dashboard',         fn($r) => $webHome->show($r));
$router->get('/features',          fn($r) => $webFeatures->show($r));
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

// ---- Surface-Check (M9) — Crowd-Belag auf eine hochgeladene Route projizieren ----
$router->get ('/surface-check',                          fn($r) => $webSurface->showForm($r));
$router->post('/surface-check',                          fn($r) => $webSurface->analyze($r),  [$csrf]);
// Präziser Valhalla-Pfad (on-demand, same-origin-Fetch -> JSON).
$router->get ('/surface-check/details',                  fn($r) => $webSurface->details($r));

// Public Share-Page — kein Login, kein CSRF (read-only GET).
$router->get ('/share/{token}',                          fn($r) => $webShare->show($r));
$router->get ('/share/{token}/geojson',                  fn($r) => $webShare->geojson($r));

// ---- Referral-Landingpage (M7) — öffentlich, kein Login ----
// iOS fängt /i/{code} als Universal Link ab; im Browser ist dies die
// Fallback-Werbeseite mit sichtbarem Code + App-Store-/Register-Link.
$router->get ('/i/{code}',                               fn($r) => $webReferral->landing($r));

// ---- Admin-Auswertung Empfehlungen (M7) — ADMIN_EMAILS-Gate ----
$router->get ('/admin/referrals',                        fn($r) => $webAdminRef->index($r));
$router->get ('/admin/referrals.csv',                    fn($r) => $webAdminRef->csv($r));

$router->get ('/admin/uploads',                          fn($r) => $webAdminUploads->index($r));
$router->get ('/admin/uploads/{id}/download',            fn($r) => $webAdminUploads->download($r));

// ---- Game-Admin-Dashboard (A–F) — ADMIN_EMAILS-Gate in den Controllern, Host-Gate vor dispatch ----
$router->get ('/admin/game',                           fn($r) => $webGameAdmin->health($r));
$router->get ('/admin/game/config',                    fn($r) => $webGameAdmin->config($r));
$router->post('/admin/game/config',                    fn($r) => $webGameAdmin->saveConfig($r),  [$csrf]);
$router->post('/admin/game/recompute',                 fn($r) => $webGameAdmin->recompute($r),   [$csrf]);
$router->get ('/admin/game/ingest',                    fn($r) => $webGameAdmin->ingest($r));
$router->post('/admin/game/ingest',                    fn($r) => $webGameAdmin->ingestByRoute($r), [$csrf]);
$router->post('/admin/game/ingest/{route_id}',         fn($r) => $webGameAdmin->reingest($r),    [$csrf]);
$router->get ('/admin/game/moderation',                fn($r) => $webGameAdmin->moderation($r));
$router->get ('/admin/game/players',                   fn($r) => $webGameAdmin->players($r));
$router->get ('/admin/game/player',                    fn($r) => $webGameAdmin->player($r));
$router->get ('/admin/game/crews',                     fn($r) => $webGameAdmin->crews($r));
$router->get ('/admin/game/map',                       fn($r) => $webGameAdmin->map($r));
$router->get ('/admin/game/edges.geojson',             fn($r) => $webGameAdmin->edgesGeoJson($r));
$router->get ('/admin/game/edge',                      fn($r) => $webGameEdge->show($r));
$router->get ('/admin/game/edge/{id}',                 fn($r) => $webGameEdge->show($r));
$router->post('/admin/game/edge/{id}/recalc',          fn($r) => $webGameEdge->recalcEdge($r),       [$csrf]);
$router->post('/admin/game/pass/{pass_id}/invalidate', fn($r) => $webGameEdge->invalidatePass($r),   [$csrf]);
$router->post('/admin/game/pass/{pass_id}/reactivate', fn($r) => $webGameEdge->reactivatePass($r),   [$csrf]);
$router->post('/admin/game/user/{user_id}/ban',        fn($r) => $webGameEdge->banUser($r),          [$csrf]);

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
// Crew-Logo-Serving (public, JPEG oder 404). Liegt am Web-Root, NICHT unter
// /api/v1 — analog zur Avatar-Route (GAME_CREW_LOGO_BACKEND.md §3.3).
$router->get ('/game/crews/{slug}/logo',                 fn($r) => $apiCrewLogo->serve($r));
$router->post('/u/{handle}/follow',                      fn($r) => $webSocial->follow($r),    [$csrf]);
$router->post('/u/{handle}/unfollow',                    fn($r) => $webSocial->unfollow($r),  [$csrf]);
$router->post('/u/{handle}/block',                       fn($r) => $webSocial->block($r),     [$csrf]);
$router->post('/u/{handle}/unblock',                     fn($r) => $webSocial->unblock($r),   [$csrf]);

// ---- Engagement Web-UI (M4a Likes) ----
$router->post('/u/{handle}/r/{id}/like',                 fn($r) => $webEngage->like($r),          [$csrf]);
$router->post('/u/{handle}/r/{id}/unlike',               fn($r) => $webEngage->unlike($r),        [$csrf]);
$router->post('/u/{handle}/r/{id}/comment',              fn($r) => $webEngage->comment($r),       [$csrf]);
$router->post('/u/{handle}/r/{id}/comments/{cid}/delete', fn($r) => $webEngage->commentDelete($r), [$csrf]);

// ---- Interne, per Token abgesicherte Endpoints (Migration & Cron) ----
// Auf Shared-Hosting ohne SSH lassen sich die CLI-Aufgaben so über HTTP
// auslösen. Schutz: geheimer Token aus der .env (INTERNAL_TOKEN), verglichen
// per hash_equals. Ist kein Token gesetzt, sind die Endpoints deaktiviert
// und verhalten sich wie eine unbekannte Route (404).
$internalToken = (string)($config->get('INTERNAL_TOKEN', '') ?? '');
$runInternal = function (Request $r, string $command)
    use ($internalToken, $basePath, $tokens, $routeService, $config, $heatmapLines, $gameRecompute, $gameRushSvc, $gameCrewSvc, $edgeBackfill) {
    if ($internalToken === '') {
        Response::error('not_found', 'Nicht gefunden.', 404);
    }
    $provided = (string)($r->query['token'] ?? $r->header('X-Internal-Token', ''));
    if ($provided === '' || !hash_equals($internalToken, $provided)) {
        Response::error('not_found', 'Nicht gefunden.', 404);
    }
    $cli = new Commands($basePath, $tokens, $routeService, $config, new NotificationService(), new HeatmapService(), $heatmapLines, $gameRecompute, $gameRushSvc, $gameCrewSvc, $edgeBackfill);
    $argv = ['internal', $command];
    foreach (['limit', 'sleep-ms', 'after-route-id', 'bbox'] as $opt) {
        if (isset($r->query[$opt]) && (string)$r->query[$opt] !== '') {
            $argv[] = '--' . $opt . '=' . (string)$r->query[$opt];
        }
    }
    ob_start();
    try {
        $code = $cli->run($argv);
        $output = trim((string)ob_get_clean());
    } catch (\Throwable $e) {
        // Aufräumen des Buffers, dann den ECHTEN Fehler an den (per Token
        // authentifizierten) Aufrufer zurückgeben. Ohne SSH ist das der
        // einzige Weg, die sonst nur ins Logfile geschriebene Ursache zu
        // sehen. Der Endpoint ist durch INTERNAL_TOKEN geschützt, daher ist
        // die Detail-Ausgabe hier vertretbar.
        $output = trim((string)ob_get_clean());
        error_log("internal {$command} fehlgeschlagen: " . $e->getMessage());
        Response::json([
            'ok'      => false,
            'command' => $command,
            'output'  => $output,
            'error'   => $e->getMessage(),
        ], 500);
    }
    Response::json([
        'ok'      => $code === 0,
        'command' => $command,
        'output'  => $output,
    ], $code === 0 ? 200 : 500);
};

$router->get('/internal/migrate',       fn($r) => $runInternal($r, 'cli:migrate'));
$router->post('/internal/migrate',      fn($r) => $runInternal($r, 'cli:migrate'));
$router->get('/internal/cron/cleanup',  fn($r) => $runInternal($r, 'cron:cleanup'));
$router->post('/internal/cron/cleanup', fn($r) => $runInternal($r, 'cron:cleanup'));
$router->get('/internal/cron/heatmap',  fn($r) => $runInternal($r, 'cron:heatmap'));
$router->post('/internal/cron/heatmap', fn($r) => $runInternal($r, 'cron:heatmap'));
$router->get('/internal/cron/heatmap-lines',  fn($r) => $runInternal($r, 'cron:heatmap-lines'));
$router->post('/internal/cron/heatmap-lines', fn($r) => $runInternal($r, 'cron:heatmap-lines'));
$router->get('/internal/cron/game-recompute',  fn($r) => $runInternal($r, 'game:recompute'));
$router->post('/internal/cron/game-recompute', fn($r) => $runInternal($r, 'game:recompute'));
$router->get('/internal/cron/game-backfill-speed',  fn($r) => $runInternal($r, 'game:backfill-speed'));
$router->post('/internal/cron/game-backfill-speed', fn($r) => $runInternal($r, 'game:backfill-speed'));
$router->get('/internal/cron/rush-tick',  fn($r) => $runInternal($r, 'game:rush-tick'));
$router->post('/internal/cron/rush-tick', fn($r) => $runInternal($r, 'game:rush-tick'));
$router->get('/internal/game/heal-crews',  fn($r) => $runInternal($r, 'game:heal-crews'));
$router->post('/internal/game/heal-crews', fn($r) => $runInternal($r, 'game:heal-crews'));
// Read-only Log-Tail für Diagnose ohne SSH (z. B. frischer PDO/SQLSTATE-Stacktrace).
$router->get('/internal/logtail',  fn($r) => $runInternal($r, 'internal:logtail'));
$router->post('/internal/logtail', fn($r) => $runInternal($r, 'internal:logtail'));
// APNs-Diagnose: prüft Key-Lesbarkeit + JWT-Erzeugung ohne Secret-Ausgabe.
$router->get('/internal/push/doctor',  fn($r) => $runInternal($r, 'internal:apns-check'));
$router->post('/internal/push/doctor', fn($r) => $runInternal($r, 'internal:apns-check'));
// Cutover-Hinweg (Modell A): Manifest der public Routen für den lokalen
// Rebuild. Reines Lesen (kein Valhalla), daher auch auf PROD unbedenklich.
$router->get('/internal/heatmap/manifest',  fn($r) => $runInternal($r, 'heatmap:manifest'));
$router->post('/internal/heatmap/manifest', fn($r) => $runInternal($r, 'heatmap:manifest'));

// Cutover-Rückweg: vorberechnete heatmap_edges als JSON-Body entgegennehmen und
// serverseitig in eine Shadow-Tabelle laden + atomar swappen. Eigener Handler
// (statt $runInternal), weil der Request-Body verarbeitet wird. Sicher: nur
// parametrisierte INSERTs, kein beliebiges SQL.
$router->post('/internal/heatmap/import', function (Request $r) use ($internalToken, $heatmapLines): void {
    if ($internalToken === '') {
        Response::error('not_found', 'Nicht gefunden.', 404);
    }
    $provided = (string)($r->query['token'] ?? $r->header('X-Internal-Token', ''));
    if ($provided === '' || !hash_equals($internalToken, $provided)) {
        Response::error('not_found', 'Nicht gefunden.', 404);
    }
    $body = $r->rawBody;
    if ($body === '') {
        Response::error('bad_request', 'Leerer Body (Body-Limit/post_max_size prüfen).', 400);
    }
    try {
        $res = $heatmapLines->importEdges($body);
    } catch (\Throwable $e) {
        error_log('internal heatmap/import fehlgeschlagen: ' . $e->getMessage());
        Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        return;
    }
    Response::json([
        'ok'       => $res['swapped'],
        'received' => $res['received'],
        'imported' => $res['imported'],
        'swapped'  => $res['swapped'],
    ], $res['swapped'] ? 200 : 422);
});

// Hinweis: Universal Links (Apple App Site Association) werden NICHT hier
// als Route ausgeliefert, sondern als statische Datei unter
// public/.well-known/apple-app-site-association (+ eigene .htaccess).
// Grund: Shared-Hosting blockt den /.well-known/-Pfad, bevor PHP greift —
// die Datei muss als echtes Verzeichnis erreichbar bleiben. Siehe
// backend/UNIVERSAL_LINKS.md.

// Healthcheck
$router->get('/healthz', function ($r) use ($basePath, $gameValhalla, $apnsConfig): void {
    // Build-/Versionsinfo aus der vom Deploy geschriebenen VERSION-Datei
    // (Projekt-Root). Erlaubt zu prüfen, welcher Commit produktiv liegt —
    // ohne .git auf dem Server. Fehlt die Datei (z. B. lokal), ist version null.
    $version = null;
    $versionFile = $basePath . '/VERSION';
    if (is_file($versionFile)) {
        $raw = @file_get_contents($versionFile);
        if ($raw !== false && trim($raw) !== '') {
            $decoded = json_decode(trim($raw), true);
            $version = is_array($decoded) ? $decoded : ['commit' => trim($raw)];
        }
    }

    $body = [
        'status'  => 'ok',
        'time'    => gmdate('Y-m-d\TH:i:s\Z'),
        'version' => $version,
    ];

    // Opt-in Komponenten-Checks via ?check=valhalla,push (oder ?check=all). Der
    // bare /healthz bleibt ein schlanker Liveness-Probe (kein externer Ping). Ist
    // eine angeforderte Komponente unten, wird der Gesamtstatus "degraded" und der
    // HTTP-Code 503 — so können Uptime-Monitore direkt darauf reagieren.
    $check = strtolower((string)($r->query['check'] ?? ''));
    $wants = $check === '' ? [] : array_map('trim', explode(',', $check));
    $wantValhalla = in_array('valhalla', $wants, true) || in_array('all', $wants, true);
    $wantPush     = in_array('push', $wants, true)     || in_array('all', $wants, true);

    $httpStatus = 200;
    if ($wantValhalla) {
        $v = $gameValhalla->status();
        $body['checks']['valhalla'] = $v;
        if (!$v['reachable']) {
            $body['status'] = 'degraded';
            $httpStatus = 503;
        }
    }

    // Push-Readiness (siehe backend/PUSH_BACKEND.md): rein informativ, OHNE
    // Secrets — nur Booleans/Zahlen, damit nach dem Deploy per URL prüfbar ist,
    // ob der APNs-Versand scharf geschaltet ist. Verändert den Liveness-Status
    // bewusst NICHT (fehlende Config bedeutet nicht "Server ungesund").
    if ($wantPush) {
        $curlHttp2 = false;
        if (function_exists('curl_version')) {
            $cv = curl_version();
            $curlHttp2 = is_array($cv) && defined('CURL_VERSION_HTTP2')
                && (bool)($cv['features'] & CURL_VERSION_HTTP2);
        }
        $devicesTable = false;
        $registered   = null;
        try {
            $pdo = \App\Database\Db::pdo();
            $devicesTable = $pdo->query("SHOW TABLES LIKE 'push_devices'")->fetchColumn() !== false;
            if ($devicesTable) {
                $registered = (int)$pdo->query('SELECT COUNT(*) FROM push_devices')->fetchColumn();
            }
        } catch (\Throwable $e) {
            // best effort — DB-Fehler nicht nach außen tragen
        }
        $body['checks']['push'] = [
            'apns_configured'    => $apnsConfig->usable(),
            'key_present'        => $apnsConfig->keyPem !== '',
            'curl_http2'         => $curlHttp2,
            'devices_table'      => $devicesTable,
            'registered_devices' => $registered,
        ];
    }

    Response::json($body, $httpStatus);
});

// ---- Host-aware Admin-Split: /admin/* nur unter admin.grava.world, sonst 404 ----
// Das PHP-Session-Cookie setzt keine Domain → die Admin-Session auf der
// Subdomain ist automatisch host-gebunden (eigenes Cookie + CSRF).
$isAdminHost = \App\Game\Admin\AdminHost::isAdmin(
    (string)$request->header('Host', ''),
    (string)$config->get('ADMIN_HOST', ''),
    (string)$config->get('APP_URL', ''),
);
$reqPath = $request->path;
$isAdminPath = ($reqPath === '/admin' || str_starts_with($reqPath, '/admin/'));
if ($isAdminHost) {
    // Root + Post-Login-Ziel (/dashboard) auf das Admin-Board umleiten,
    // damit der Login-Flow auf der Subdomain nicht in einem 404 endet.
    if ($reqPath === '/' || $reqPath === '/dashboard') {
        Response::redirect('/admin/game');
    }
    $allowed = $isAdminPath
        || in_array($reqPath, ['/login', '/logout', '/auth/web-refresh', '/healthz'], true)
        || str_starts_with($reqPath, '/internal/')
        || str_starts_with($reqPath, '/assets/')
        || $reqPath === '/favicon.ico'
        || $reqPath === '/favicon.svg';
    if (!$allowed) {
        Response::error('not_found', 'Nicht gefunden.', 404);
    }
} elseif ($isAdminPath) {
    // Hauptdomain: Admin-Seiten sind hier nicht erreichbar.
    Response::error('not_found', 'Nicht gefunden.', 404);
}

$router->dispatch($request);
