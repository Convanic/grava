<?php
declare(strict_types=1);

namespace App\Auth;

use App\Config\Config;
use App\Database\Db;
use App\Http\Middleware\Csrf;
use App\Support\Clock;
use RuntimeException;

/**
 * Server-side Web-Session (M2 / H5).
 *
 * Heute war Web-Auth identisch zur API-Auth: dasselbe Refresh-Token-Paar,
 * dasselbe `path=/`-Cookie. Konsequenz: bei jedem Page-Load wurde — wenn der
 * Access-Token expired war — ein Refresh-Rotation getriggert. Das war
 * problematisch für mehrere Tabs und für CSRF/Replay-Resistenz.
 *
 * Neues Modell:
 *  - Primärer Web-Auth ist ein server-side `$_SESSION['user_id']`-Record
 *    mit sliding TTL (Default 30 Min).
 *  - `ge_refresh` wird auf `path=/auth/web-refresh` gescoped (siehe
 *    {@see CookieAuth}). Der Browser sendet das Cookie nur noch dorthin
 *    — also nicht mehr bei jedem Pageload.
 *  - Wenn die WebSession abgelaufen ist und der User eine geschützte
 *    Seite anfragt, leitet der Controller auf
 *    `/auth/web-refresh?next=<original>`. Das ist die einzige Stelle,
 *    an der eine Refresh-Token-Rotation passiert.
 *
 * Persistierte Felder in `$_SESSION`:
 *  - `web_user_id`     : int    — interne User-PK, primärer Identifier
 *  - `web_session_id`  : int    — DB-`sessions.id`, gebraucht für Logout
 *  - `web_expires_at`  : int    — UNIX-Timestamp, Sliding-Ablauf
 *
 * NICHT in `$_SESSION` gespeichert: das aktuelle Access-Token. Web-Pages
 * machen Server-Rendering; wenn JS aus dem Browser die API ruft, geht
 * das über das separate `ge_access`-Cookie + Bearer-Adapter — aber das
 * ist Phase 4-Thema.
 */
final class WebSession
{
    private const KEY_USER_ID    = 'web_user_id';
    private const KEY_SESSION_ID = 'web_session_id';
    private const KEY_EXPIRES_AT = 'web_expires_at';

    /** Sliding-TTL in Sekunden. */
    private readonly int $ttl;

    public function __construct(Config $config)
    {
        $this->ttl = $config->int('WEB_SESSION_TTL', 1800);
    }

    /**
     * Nach erfolgreichem Login/Reset/Refresh aufrufen. Setzt die drei
     * Session-Keys und initialisiert den sliding Ablauf-Timer.
     */
    public function establish(int $userId, int $dbSessionId): void
    {
        Csrf::ensureStarted();
        $_SESSION[self::KEY_USER_ID]    = $userId;
        $_SESSION[self::KEY_SESSION_ID] = $dbSessionId;
        $_SESSION[self::KEY_EXPIRES_AT] = time() + $this->ttl;
    }

    /**
     * Liefert User-PK + DB-Session-ID, wenn eine gültige Web-Session
     * vorliegt — und verlängert dabei die sliding TTL. Sonst null.
     *
     * @return array{user_id:int,session_id:int}|null
     */
    public function resolve(): ?array
    {
        Csrf::ensureStarted();
        $userId    = isset($_SESSION[self::KEY_USER_ID])    ? (int)$_SESSION[self::KEY_USER_ID]    : 0;
        $sessionId = isset($_SESSION[self::KEY_SESSION_ID]) ? (int)$_SESSION[self::KEY_SESSION_ID] : 0;
        $expiresAt = isset($_SESSION[self::KEY_EXPIRES_AT]) ? (int)$_SESSION[self::KEY_EXPIRES_AT] : 0;

        if ($userId <= 0 || $sessionId <= 0) {
            return null;
        }
        if ($expiresAt > 0 && $expiresAt <= time()) {
            // Abgelaufen — wir entfernen die Keys, damit der nächste
            // resolve() nicht versehentlich auf veralteten Werten landet.
            $this->destroy();
            return null;
        }

        // Verify die DB-Session ist nicht inzwischen revoked
        // (z. B. wegen logout-all von einem anderen Gerät, oder C5
        // Refresh-Reuse-Detection). Single-Query, indexed.
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT s.id
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.id = ?
                AND s.user_id = ?
                AND s.revoked_at IS NULL
                AND s.expires_at > ?
                AND u.status = ?
                AND u.deleted_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$sessionId, $userId, Clock::nowUtcString(), 'active']);
        if ($stmt->fetch() === false) {
            $this->destroy();
            return null;
        }

        // Sliding-Renewal — jeder authentifizierte Hit verlängert die TTL.
        $_SESSION[self::KEY_EXPIRES_AT] = time() + $this->ttl;

        return ['user_id' => $userId, 'session_id' => $sessionId];
    }

    /**
     * Wirft eine Exception, wenn keine gültige WebSession vorliegt.
     * Bequem für Controller, die hart authentifiziert arbeiten müssen.
     *
     * @return array{user_id:int,session_id:int}
     */
    public function requireResolved(): array
    {
        $ctx = $this->resolve();
        if ($ctx === null) {
            throw new RuntimeException('WebSession::requireResolved called without an active session.');
        }
        return $ctx;
    }

    /**
     * Web-Logout, oder Reaktion auf eine abgelaufene/widerrufene Session.
     * Lässt andere `$_SESSION`-Felder (insbesondere `flash`, `csrf_token`)
     * unangetastet — der Aufrufer entscheidet separat, ob er
     * {@see Csrf::rotateForAuthState()} dranschließt.
     */
    public function destroy(): void
    {
        Csrf::ensureStarted();
        unset(
            $_SESSION[self::KEY_USER_ID],
            $_SESSION[self::KEY_SESSION_ID],
            $_SESSION[self::KEY_EXPIRES_AT],
        );
    }
}
