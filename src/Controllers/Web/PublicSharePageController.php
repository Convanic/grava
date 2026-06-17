<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Http\GeoJsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Routes\RouteGeoJson;
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
                '_title' => 'Link nicht mehr verfügbar · GravelExplorer',
                'flash'  => null,
            ], 410);
        }

        $this->view->render('share', [
            '_title' => $route['title'] . ' · GravelExplorer',
            '_layoutWide' => true,
            'route'  => $route,
            'shareToken' => $token,
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
            $fc = $this->geo->toFeatureCollection($loaded['payload']);
        } catch (\Throwable) {
            GeoJsonResponse::error(404);
        }
        GeoJsonResponse::emit($fc);
    }
}
