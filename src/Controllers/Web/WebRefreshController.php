<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\CookieAuth;
use App\Auth\WebSession;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;

/**
 * GET `/auth/web-refresh?next=<path>`
 *
 * Single entry point für „die WebSession ist abgelaufen, aber wir haben
 * noch einen gültigen Refresh-Token". Sonst nirgends im Code wird ein
 * Refresh-Token rotiert (das war früher pro Page-Load der Fall — H5/M4).
 *
 * Flow:
 *  1. Lese `ge_refresh`-Cookie (path-scoped, nur hier sichtbar).
 *  2. Rotate via {@see CookieAuth::rotateFromRequest()} — liefert
 *     die neuen Tokens und setzt sofort die frischen Cookies.
 *  3. {@see Csrf::rotateForAuthState()} — neue PHP-Session-ID + frischer
 *     CSRF-Token. Wir behandeln den Refresh wie einen Auth-State-Wechsel.
 *  4. {@see WebSession::establish()} — server-side Session anlegen.
 *  5. Redirect zum `next`-Pfad (whitelisted). Sicherheitscheck siehe
 *     {@see self::sanitizeNext()}.
 *
 * Verhalten beim Fehlerfall (kein Cookie, abgelaufen, revoked, reuse-
 * detection in TokenService): leise Redirect auf `/login` mit Flash.
 * Wir signalisieren *nicht*, ob der Token gültig war oder nicht — das
 * wäre Account-Enumeration-/Probing-Material.
 */
final class WebRefreshController
{
    public function __construct(
        private readonly CookieAuth $cookieAuth,
        private readonly WebSession $webSession,
    ) {}

    public function handle(Request $req): never
    {
        $next = self::sanitizeNext((string)($req->query['next'] ?? '/dashboard'));

        $rotated = $this->cookieAuth->rotateFromRequest($req);
        if ($rotated === null) {
            // Kein Cookie, oder TokenService hat null geliefert (z. B.
            // expired, revoked, oder C5-Reuse-Detection hat alle Sessions
            // des Users entwertet). Wir sind höflich, sagen aber nichts
            // Konkretes — das Cookie ist sowieso schon mit ungültigem
            // Wert beantwortet worden.
            Csrf::ensureStarted();
            $_SESSION['flash'] = 'Bitte melde dich erneut an.';
            Response::redirect('/login');
        }

        // Auth-State-Wechsel — Session-Fixation-Schutz + neuer CSRF-Token.
        Csrf::rotateForAuthState();
        $this->webSession->establish(
            (int)$rotated['user_id'],
            (int)$rotated['session_id'],
        );

        Response::redirect($next);
    }

    /**
     * `next` darf nur auf eine *interne* Page zeigen, sonst könnten wir
     * als Open-Redirect missbraucht werden. Regel:
     *  - Muss mit `/` beginnen
     *  - Darf nicht mit `//` oder `/\` beginnen (Protocol-Relative URL)
     *  - Maximal 1024 Bytes
     *  - Default-Fallback `/dashboard`
     */
    private static function sanitizeNext(string $next): string
    {
        if ($next === '' || strlen($next) > 1024) {
            return '/dashboard';
        }
        if ($next[0] !== '/') {
            return '/dashboard';
        }
        if (str_starts_with($next, '//') || str_starts_with($next, '/\\')) {
            return '/dashboard';
        }
        return $next;
    }
}
