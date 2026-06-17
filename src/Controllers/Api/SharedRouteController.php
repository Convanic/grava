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
 *   unbekannt, abgelaufen oder revoked ist, gibt es 404. Wir
 *   antworten **nicht** mit "Token vorhanden, aber abgelaufen" —
 *   das wäre ein Probing-Side-Channel.
 */
final class SharedRouteController
{
    public function __construct(private readonly ShareTokenService $shares) {}

    public function show(Request $req): void
    {
        $token = (string)($req->routeParams['token'] ?? '');
        $route = $this->shares->resolve($token);
        if ($route === null) {
            Response::error('not_found', 'Geteilte Route nicht gefunden.', 404);
        }
        Response::json(['route' => $route]);
    }
}
