<?php
declare(strict_types=1);

namespace App\Auth;

use App\Config\Config;
use App\Http\Request;

/**
 * Cookie helper used by the server-rendered web surface.
 *
 * Sets two cookies:
 *  - ge_access (path=/, lifetime = ACCESS_TOKEN_TTL)
 *  - ge_refresh (path=/, lifetime = REFRESH_TOKEN_TTL)
 *
 * NOTE (TODO milestone 2): path-scope the refresh cookie to a dedicated
 *  refresh endpoint so it is not sent on every page request.
 */
final class CookieAuth
{
    public const ACCESS  = 'ge_access';
    public const REFRESH = 'ge_refresh';

    public function __construct(
        private readonly Config $config,
        private readonly TokenService $tokens,
    ) {}

    public function setFromTokens(array $tokens): void
    {
        $this->set(self::ACCESS,  $tokens['access_token'],  (int)$tokens['access_expires_in']);
        $this->set(self::REFRESH, $tokens['refresh_token'], (int)$tokens['refresh_expires_in']);
    }

    public function clear(): void
    {
        $this->set(self::ACCESS,  '', -3600);
        $this->set(self::REFRESH, '', -3600);
    }

    /**
     * Resolve the current web user from cookies. Returns the standard
     * access context (user + session_id + access_token_id) or null.
     * Attempts a silent refresh if the access cookie is expired but the
     * refresh cookie is still valid.
     *
     * @return array{user:array<string,mixed>,session_id:int,access_token_id:int}|null
     */
    public function resolve(Request $req): ?array
    {
        $access = $req->cookie(self::ACCESS);
        if ($access !== null && $access !== '') {
            $ctx = $this->tokens->resolveAccess($access);
            if ($ctx !== null) {
                return $ctx;
            }
        }

        $refresh = $req->cookie(self::REFRESH);
        if ($refresh === null || $refresh === '') {
            return null;
        }
        $rotated = $this->tokens->rotateRefresh($refresh, $req->userAgent, $req->ipBinary());
        if ($rotated === null) {
            return null;
        }
        $this->setFromTokens($rotated);
        $ctx = $this->tokens->resolveAccess($rotated['access_token']);
        return $ctx;
    }

    private function set(string $name, string $value, int $maxAgeSeconds): void
    {
        $secure  = $this->config->bool('COOKIE_SECURE', false) || (($_SERVER['HTTPS'] ?? '') === 'on');
        $domain  = (string)$this->config->get('COOKIE_DOMAIN', '');
        $expires = $maxAgeSeconds > 0 ? time() + $maxAgeSeconds : 0;

        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'domain'   => $domain,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
