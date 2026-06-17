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
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }

        $ctx = $this->tokens->resolveAccess($token);
        if ($ctx === null) {
            Response::error('token_expired', 'Token ist abgelaufen oder ungültig.', 401);
        }

        $request->user = (object)$ctx['user'];
        $request->sessionId = $ctx['session_id'];
        $request->accessTokenId = $ctx['access_token_id'];
    }
}
