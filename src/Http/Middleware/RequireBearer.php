<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\TokenService;
use App\Http\Request;
use App\Http\Response;

final class RequireBearer
{
    public function __construct(private readonly TokenService $tokens) {}

    public function __invoke(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            // H4: WWW-Authenticate per RFC 6750, damit Clients trotz
            // einheitlichem Error-Code nuancierte Reaktionen zeigen können.
            header('WWW-Authenticate: Bearer realm="api"');
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }

        $ctx = $this->tokens->resolveAccess($token);
        if ($ctx === null) {
            // H4: gleicher generischer Error-Code wie bei "kein Token", damit
            // ein Angreifer nicht "kein Header geschickt" von "Token ungültig"
            // unterscheiden kann. Der WWW-Authenticate-Header informiert
            // legitime Clients darüber, dass der präsentierte Token ungültig
            // ist und ein Refresh nötig sein kann.
            header('WWW-Authenticate: Bearer realm="api", error="invalid_token"');
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }

        $request->user = (object)$ctx['user'];
        $request->sessionId = $ctx['session_id'];
        $request->accessTokenId = $ctx['access_token_id'];
    }
}
