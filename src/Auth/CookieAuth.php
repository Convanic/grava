<?php
declare(strict_types=1);

namespace App\Auth;

use App\Config\Config;
use App\Http\Request;

/**
 * Cookie helper used by the server-rendered web surface.
 *
 * Sets two cookies:
 *  - `ge_access`  path=/ lifetime=ACCESS_TOKEN_TTL — für JS-fetch-Calls
 *    aus dem Browser, optionaler Bearer-Ersatz.
 *  - `ge_refresh` path=/auth/web-refresh lifetime=REFRESH_TOKEN_TTL —
 *    geht nur noch zum dedizierten Refresh-Endpoint (H5).
 *
 * Refresh-Pfadwechsel löst zwei alte Probleme:
 *  1. Refresh-Cookie wandert nicht mehr bei jedem Page-Load durchs Netz.
 *  2. Rotation-Storm bei mehreren Tabs wird sehr unwahrscheinlich, weil
 *     /auth/web-refresh nur dann angesteuert wird, wenn die WebSession
 *     wirklich abgelaufen ist (siehe {@see WebSession}).
 */
final class CookieAuth
{
    public const ACCESS              = 'ge_access';
    public const REFRESH             = 'ge_refresh';
    public const REFRESH_COOKIE_PATH = '/auth/web-refresh';

    public function __construct(
        private readonly Config $config,
        private readonly TokenService $tokens,
    ) {}

    public function setFromTokens(array $tokens): void
    {
        $this->set(self::ACCESS,  $tokens['access_token'],  (int)$tokens['access_expires_in'],  '/');
        $this->set(self::REFRESH, $tokens['refresh_token'], (int)$tokens['refresh_expires_in'], self::REFRESH_COOKIE_PATH);
    }

    public function clear(): void
    {
        // Wir müssen explizit denselben path mitgeben, sonst löscht der
        // Browser das Cookie nicht (Cookie-Identität ist Tuple aus
        // Name+Domain+Path).
        $this->set(self::ACCESS,  '', -3600, '/');
        $this->set(self::REFRESH, '', -3600, self::REFRESH_COOKIE_PATH);
    }

    /**
     * Tauscht den vom Browser mitgeschickten Refresh-Token gegen ein
     * frisches Token-Paar ein. Wird ausschließlich vom
     * {@see \App\Controllers\Web\WebRefreshController} aufgerufen — der
     * Pfad-Scope sorgt dafür, dass die Methode anderswo keinen Effekt hat.
     *
     * @return array{access_token:string,refresh_token:string,session_id:int,user_id:int,access_token_id:int,access_expires_in:int,refresh_expires_in:int}|null
     */
    public function rotateFromRequest(Request $req): ?array
    {
        $refresh = $req->cookie(self::REFRESH);
        if ($refresh === null || $refresh === '') {
            return null;
        }
        $rotated = $this->tokens->rotateRefresh($refresh, $req->userAgent, $req->ipBinary());
        if ($rotated === null) {
            return null;
        }
        $this->setFromTokens($rotated);
        return $rotated;
    }

    private function set(string $name, string $value, int $maxAgeSeconds, string $path): void
    {
        $secure  = $this->config->bool('COOKIE_SECURE', false) || self::requestIsHttps();
        $domain  = (string)$this->config->get('COOKIE_DOMAIN', '');
        $expires = $maxAgeSeconds > 0 ? time() + $maxAgeSeconds : 0;

        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * H3: Hinter einem TLS-terminierenden Reverse-Proxy ist `$_SERVER['HTTPS']`
     * oft leer — der Proxy reicht das Schema typischerweise als
     * `X-Forwarded-Proto: https` durch. Damit die Secure-Cookies nicht
     * versehentlich als plain markiert werden, prüfen wir beide Quellen.
     * Verlassen sich tut der Aufrufer trotzdem nicht darauf: in Production
     * sollte `COOKIE_SECURE=true` explizit gesetzt sein und nur einer
     * vertrauenswürdigen Proxy-Liste vertraut werden (siehe TRUSTED_PROXIES).
     */
    private static function requestIsHttps(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') === 'on') {
            return true;
        }
        $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        // Bei mehreren Proxies kann der Header eine Komma-Liste sein.
        $first = trim(explode(',', $proto)[0] ?? '');
        return $first === 'https';
    }
}
