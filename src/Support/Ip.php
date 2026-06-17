<?php
declare(strict_types=1);

namespace App\Support;

use App\Config\Config;

/**
 * Helper für die Auflösung der echten Client-IP, inklusive
 * X-Forwarded-For-Behandlung hinter einem vertrauenswürdigen
 * Reverse-Proxy.
 *
 * L14: Vorher als `private static` in {@see \App\Http\Request}
 * versteckt. Eigene Klasse, weil
 *  - dieselbe Logik perspektivisch auch außerhalb des HTTP-Requests
 *    gebraucht wird (z. B. CLI-Tools, Logs).
 *  - die Trusted-Proxy-Logik damit isoliert testbar ist.
 *
 * Sicherheitsmodell (M9): Wir akzeptieren X-Forwarded-For NUR, wenn
 * `REMOTE_ADDR` in der explizit konfigurierten Whitelist
 * `TRUSTED_PROXIES` steht. Sonst könnte jeder Client eine beliebige
 * Source-IP spoofen.
 */
final class Ip
{
    /**
     * Liefert die effektive Client-IP. Quelle: PHP-Superglobals
     * (`$_SERVER`). Wenn keine Config übergeben wird, fällt der Aufruf
     * auf {@see Config::instance()} zurück, damit Bestandscode unverändert
     * funktioniert.
     */
    public static function clientFromGlobals(?Config $cfg = null): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $cfg ??= Config::instance();
        $trustedRaw = (string)$cfg->get('TRUSTED_PROXIES', '');
        $forwarded  = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');

        return self::resolve($remote, $trustedRaw, $forwarded);
    }

    /**
     * Pure-function-Variante für Tests und CLI-Aufrufer. Kein Zugriff
     * auf Superglobals.
     *
     * @param string $remoteAddr  REMOTE_ADDR (TCP-Peer aus PHP/Apache)
     * @param string $trustedRaw  Komma-Liste aus `TRUSTED_PROXIES`
     * @param string $forwarded   Roh-Wert von `X-Forwarded-For`
     */
    public static function resolve(string $remoteAddr, string $trustedRaw, string $forwarded): string
    {
        if ($remoteAddr === '') {
            $remoteAddr = '0.0.0.0';
        }
        if ($trustedRaw === '') {
            return $remoteAddr;
        }
        $trusted = array_filter(array_map('trim', explode(',', $trustedRaw)));
        if (!in_array($remoteAddr, $trusted, true)) {
            return $remoteAddr;
        }
        if ($forwarded === '') {
            return $remoteAddr;
        }
        $first = trim(explode(',', $forwarded)[0] ?? '');
        return filter_var($first, FILTER_VALIDATE_IP) !== false ? $first : $remoteAddr;
    }
}
