<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\TokenService;
use App\Http\Request;

/**
 * M3 Phase 2: optionales Bearer-Auth für anonym-erlaubte Endpoints
 * (Discovery, Public-Profile, etc.).
 *
 * Anders als RequireBearer wirft dieses Middleware nichts: kein Token
 * → request->user bleibt null. Ungültiger Token → ebenfalls null,
 * **kein 401**. Aufrufer können danach `request->user` auf null
 * prüfen und entsprechend Block-Filter anwenden.
 *
 * Warum „silent fail" statt 401 bei kaputten Tokens?
 *  - Eine abgelaufene Session beim Browsen einer public Page soll
 *    nicht die Page kaputt machen. Der User sieht halt die anonyme
 *    Sicht — kein Block-Filter, kein „du folgst diesem User"-Flag.
 *    Das ist die offene Web-Strava-Erfahrung.
 *  - Wer wirklich auth-only Funktionen will (z. B. Follow-Button),
 *    schickt sowieso einen frischen Bearer.
 */
final class OptionalBearer
{
    public function __construct(private readonly TokenService $tokens) {}

    public function __invoke(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            return;
        }
        $ctx = $this->tokens->resolveAccess($token);
        if ($ctx === null) {
            return;
        }
        $request->user = (object)$ctx['user'];
        $request->sessionId = $ctx['session_id'];
        $request->accessTokenId = $ctx['access_token_id'];
    }
}
