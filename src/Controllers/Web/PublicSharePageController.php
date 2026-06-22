<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Http\GeoJsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Privacy\PrivacyZoneRepository;
use App\Privacy\RoutePrivacyTrimmer;
use App\Routes\RouteGeoJson;
use App\Routes\RouteInsights;
use App\Routes\RouteService;
use App\Routes\ShareTokenService;

/**
 * Public Web-Page für geteilte Routen — kein Login, kein CSRF.
 *
 * `/share/{token}` rendert die Detail-Seite für Read-Only-Besucher.
 * Token-Resolve passiert über {@see ShareTokenService::resolve()},
 * das auch view_count erhöht und null liefert, wenn der Token
 * unbekannt, abgelaufen oder revoked ist (oder die Route soft-
 * deleted wurde). Wir mappen das hart auf 410 Gone, damit
 * Suchmaschinen den Link aus dem Index nehmen.
 */
final class PublicSharePageController
{
    private readonly WebView $view;

    public function __construct(
        private readonly ShareTokenService $shares,
        string $viewsPath,
        private readonly ?RouteService $routes = null,
        private readonly ?RouteGeoJson $geo = null,
        private readonly ?RouteInsights $insights = null,
        private readonly ?PrivacyZoneRepository $privacyZones = null,
        private readonly ?RoutePrivacyTrimmer $trimmer = null,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function show(Request $req): void
    {
        $token = (string)($req->routeParams['token'] ?? '');
        $route = $this->shares->resolve($token);
        if ($route === null) {
            // 410 statt 404: der Link hat *existiert*, ist aber jetzt
            // ungültig. RFC 7231 §6.5.9 — explizit semantisch passend.
            $this->view->render('share_gone', [
                '_title' => 'Link nicht mehr verfügbar · GRAVA',
                'flash'  => null,
            ], 410);
        }

        $insights = null;
        if ($this->routes !== null && $this->insights !== null) {
            try {
                $loaded   = $this->routes->loadPayloadByPublicId((string)$route['id']);
                $insights = $this->insights->compute($loaded['payload']);
            } catch (\Throwable) {
                $insights = null;
            }
        }

        $this->view->render('share', [
            '_title' => $route['title'] . ' · GRAVA',
            '_layoutWide' => true,
            'route'  => $route,
            'shareToken' => $token,
            'insights' => $insights,
            'flash'  => null,
        ]);
    }

    // -----------------------------------------------------------------
    // GET /share/{token}/geojson — Geometrie der geteilten Route
    // -----------------------------------------------------------------
    public function geojson(Request $req): void
    {
        $token = (string)($req->routeParams['token'] ?? '');
        $route = $this->shares->resolve($token);
        if ($route === null || $this->routes === null || $this->geo === null) {
            GeoJsonResponse::error(404);
        }
        try {
            $loaded = $this->routes->loadPayloadByPublicId((string)$route['id']);
            $fc = $this->geo->toFeatureCollection(
                $loaded['payload'],
                [],
                $this->routes->hintsForPublicId((string)$route['id']),
            );
        } catch (\Throwable) {
            GeoJsonResponse::error(404);
        }
        // Privacy: geteilte Tracks werden an Fremde (hier immer anonyme
        // Besucher) innerhalb der Eigentümer-Zone getrimmt (§17 Punkt 2).
        if ($this->privacyZones !== null && $this->trimmer !== null) {
            $owner = $this->privacyZones->ownerZoneForRoute((string)$route['id']);
            if ($owner !== null) {
                $fc = $this->trimmer->trim($fc, $owner['zone']);
            }
        }
        GeoJsonResponse::emit($fc);
    }
}
