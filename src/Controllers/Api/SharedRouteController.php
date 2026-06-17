<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use App\Routes\ShareTokenService;

/**
 * Public-API für geteilte Routen — kein Bearer-Auth, dafür Token-
 * basierter Zugriff per `route_shares.share_token_hash`.
 *
 * GET /api/v1/share/{token}
 *
 *   Liefert die geteilte Route in Public Form mit `shared: true` und
 *   einem optionalen Payload-Download-Hinweis. Wenn der Token
 *   unbekannt, abgelaufen oder revoked ist, gibt es 410 Gone —
 *   semantisch passend zu §10/6 des Smoke-Plans und konsistent mit
 *   der Web-UI ({@see App\Controllers\Web\PublicSharePageController}).
 *
 *   Wir differenzieren bewusst NICHT im Response-Body zwischen
 *   „Token unbekannt" und „Token revoked" — beides wäre ein
 *   Probing-Side-Channel. Der einheitliche 410 sagt dem Aufrufer
 *   nur „der Link, den du hast, ist nicht (mehr) gültig".
 */
final class SharedRouteController
{
    public function __construct(private readonly ShareTokenService $shares) {}

    public function show(Request $req): void
    {
        $token = (string)($req->routeParams['token'] ?? '');
        $route = $this->shares->resolve($token);
        if ($route === null) {
            Response::error('share_gone', 'Dieser Share-Link ist nicht mehr verfügbar.', 410);
        }
        Response::json(['route' => $route]);
    }
}
