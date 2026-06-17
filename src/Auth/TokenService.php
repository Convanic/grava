<?php
declare(strict_types=1);

namespace App\Auth;

use App\Config\Config;
use App\Database\Db;
use App\Support\Clock;
use PDO;

/**
 * Opaque token issuance, lookup, rotation and revocation.
 *
 * Tokens are 32 raw random bytes encoded base64url. Only the sha256
 * hash of each token is stored, so an attacker with read access to
 * the database cannot impersonate users.
 */
final class TokenService
{
    public function __construct(private readonly Config $config) {}

    /**
     * Issue a brand-new session (refresh) + access token pair.
     *
     * @return array{access_token:string,refresh_token:string,session_id:int,user_id:int,access_token_id:int,access_expires_in:int,refresh_expires_in:int}
     */
    public function issueSession(int $userId, string $client, ?string $userAgent, ?string $ipBinary): array
    {
        $accessTtl  = $this->config->int('ACCESS_TOKEN_TTL', 3600);
        $refreshTtl = $this->config->int('REFRESH_TOKEN_TTL', 5_184_000);

        $refresh = self::randomToken();
        $access  = self::randomToken();

        $now = Clock::nowUtcString();
        $refreshExpires = Clock::utcPlusSeconds($refreshTtl);
        $accessExpires  = Clock::utcPlusSeconds($accessTtl);

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO sessions (user_id, refresh_hash, client, user_agent, ip, created_at, last_used_at, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                self::hashToken($refresh),
                self::normalizeClient($client),
                $userAgent !== null ? substr($userAgent, 0, 255) : null,
                $ipBinary,
                $now,
                $now,
                $refreshExpires,
            ]);
            $sessionId = (int)$pdo->lastInsertId();

            $atStmt = $pdo->prepare(
                'INSERT INTO access_tokens (session_id, user_id, token_hash, created_at, expires_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $atStmt->execute([$sessionId, $userId, self::hashToken($access), $now, $accessExpires]);
            $accessId = (int)$pdo->lastInsertId();

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'access_token'       => $access,
            'refresh_token'      => $refresh,
            'session_id'         => $sessionId,
            'user_id'            => $userId,
            'access_token_id'    => $accessId,
            'access_expires_in'  => $accessTtl,
            'refresh_expires_in' => $refreshTtl,
        ];
    }

    /**
     * Rotate the refresh token of an existing session and issue a new access token.
     * Returns the new pair, or null if the refresh token is invalid/expired/revoked.
     *
     * @return array{access_token:string,refresh_token:string,session_id:int,user_id:int,access_token_id:int,access_expires_in:int,refresh_expires_in:int}|null
     */
    public function rotateRefresh(string $refreshToken, ?string $userAgent, ?string $ipBinary): ?array
    {
        $pdo = Db::pdo();
        $now = Clock::nowUtcString();
        $tokenHash = self::hashToken($refreshToken);

        $stmt = $pdo->prepare(
            'SELECT id, user_id FROM sessions
             WHERE refresh_hash = ? AND revoked_at IS NULL AND expires_at > ?
             LIMIT 1'
        );
        $stmt->execute([$tokenHash, $now]);
        $session = $stmt->fetch();

        if (!$session) {
            // C5: Wenn der Token ein bereits rotierter Refresh-Token ist und
            // die zugehörige Session noch aktiv ist, liegt vermutlich ein
            // gestohlener Token vor (ein Angreifer benutzt ihn parallel zum
            // legitimen Client). Reaktion: alle Sessions des Users invalidieren.
            $reuse = $pdo->prepare(
                'SELECT id, user_id FROM sessions
                 WHERE previous_refresh_hash = ? AND revoked_at IS NULL
                 LIMIT 1'
            );
            $reuse->execute([$tokenHash]);
            $reuseRow = $reuse->fetch();
            if ($reuseRow) {
                $this->revokeAllForUser((int)$reuseRow['user_id']);
            }
            return null;
        }

        $accessTtl  = $this->config->int('ACCESS_TOKEN_TTL', 3600);
        $refreshTtl = $this->config->int('REFRESH_TOKEN_TTL', 5_184_000);

        $newRefresh = self::randomToken();
        $newAccess  = self::randomToken();
        $refreshExpires = Clock::utcPlusSeconds($refreshTtl);
        $accessExpires  = Clock::utcPlusSeconds($accessTtl);

        $pdo->beginTransaction();
        try {
            // Neuer refresh_hash aktiv, alter Wert wandert in previous_refresh_hash
            // — dort dient er als Reuse-Sensor, bis die Session erneut rotiert.
            $upd = $pdo->prepare(
                'UPDATE sessions
                 SET refresh_hash = ?, previous_refresh_hash = ?, last_used_at = ?, expires_at = ?, user_agent = ?, ip = ?
                 WHERE id = ?'
            );
            $upd->execute([
                self::hashToken($newRefresh),
                $tokenHash,
                $now,
                $refreshExpires,
                $userAgent !== null ? substr($userAgent, 0, 255) : null,
                $ipBinary,
                (int)$session['id'],
            ]);

            // M4: Nur abgelaufene Access-Tokens dieser Session löschen.
            // Frühere Variante killte ALLE Tokens der Session bei jedem
            // Rotate — das löste mit mehreren offenen Tabs einen Rotation-
            // Storm aus, weil Tab B den noch gültigen Token von Tab A
            // verlor. Jetzt kann eine Session mehrere parallele gültige
            // Access-Tokens haben (eine pro Tab/Refresh).
            $del = $pdo->prepare('DELETE FROM access_tokens WHERE session_id = ? AND expires_at <= ?');
            $del->execute([(int)$session['id'], $now]);

            $atStmt = $pdo->prepare(
                'INSERT INTO access_tokens (session_id, user_id, token_hash, created_at, expires_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $atStmt->execute([
                (int)$session['id'],
                (int)$session['user_id'],
                self::hashToken($newAccess),
                $now,
                $accessExpires,
            ]);
            $accessId = (int)$pdo->lastInsertId();

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'access_token'       => $newAccess,
            'refresh_token'      => $newRefresh,
            'session_id'         => (int)$session['id'],
            'user_id'            => (int)$session['user_id'],
            'access_token_id'    => $accessId,
            'access_expires_in'  => $accessTtl,
            'refresh_expires_in' => $refreshTtl,
        ];
    }

    /**
     * Look up an access token and return the associated context.
     *
     * @return array{user:array<string,mixed>,session_id:int,access_token_id:int}|null
     */
    public function resolveAccess(string $accessToken): ?array
    {
        $pdo = Db::pdo();
        $now = Clock::nowUtcString();

        $stmt = $pdo->prepare(
            'SELECT at.id AS at_id, at.session_id, at.user_id,
                    u.public_id, u.email, u.email_verified_at, u.display_name, u.created_at, u.status
             FROM access_tokens at
             JOIN sessions s ON s.id = at.session_id
             JOIN users    u ON u.id = at.user_id
             WHERE at.token_hash = ?
               AND at.expires_at > ?
               AND s.revoked_at IS NULL
               AND u.status = "active"
             LIMIT 1'
        );
        $stmt->execute([self::hashToken($accessToken), $now]);
        $row = $stmt->fetch();
        if (!$row) return null;

        return [
            'user' => [
                'id'                => $row['public_id'],
                'internal_id'       => (int)$row['user_id'],
                'email'             => $row['email'],
                'display_name'      => $row['display_name'],
                'email_verified'    => $row['email_verified_at'] !== null,
                'email_verified_at' => $row['email_verified_at'],
                'created_at'        => $row['created_at'],
            ],
            'session_id'      => (int)$row['session_id'],
            'access_token_id' => (int)$row['at_id'],
        ];
    }

    public function revokeSession(int $sessionId): void
    {
        $pdo = Db::pdo();
        $now = Clock::nowUtcString();
        $pdo->prepare('UPDATE sessions SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')
            ->execute([$now, $sessionId]);
        $pdo->prepare('DELETE FROM access_tokens WHERE session_id = ?')->execute([$sessionId]);
    }

    public function revokeAllForUser(int $userId, ?int $exceptSessionId = null): void
    {
        $pdo = Db::pdo();
        $now = Clock::nowUtcString();
        if ($exceptSessionId !== null) {
            $pdo->prepare('UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND id != ?')
                ->execute([$now, $userId, $exceptSessionId]);
            $pdo->prepare('DELETE FROM access_tokens WHERE user_id = ? AND session_id != ?')
                ->execute([$userId, $exceptSessionId]);
        } else {
            $pdo->prepare('UPDATE sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL')
                ->execute([$now, $userId]);
            $pdo->prepare('DELETE FROM access_tokens WHERE user_id = ?')->execute([$userId]);
        }
    }

    public function cleanup(): array
    {
        $pdo = Db::pdo();
        $now = Clock::nowUtcString();
        $a = $pdo->prepare('DELETE FROM access_tokens WHERE expires_at <= ?');
        $a->execute([$now]);
        $accessDeleted = $a->rowCount();

        $b = $pdo->prepare('DELETE FROM sessions WHERE expires_at <= ? OR (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(?, INTERVAL 30 DAY))');
        $b->execute([$now, $now]);
        $sessionsDeleted = $b->rowCount();

        $c = $pdo->prepare('DELETE FROM email_verifications WHERE expires_at <= ? OR consumed_at IS NOT NULL');
        $c->execute([$now]);
        $emailDeleted = $c->rowCount();

        $d = $pdo->prepare('DELETE FROM password_resets WHERE expires_at <= ? OR consumed_at IS NOT NULL');
        $d->execute([$now]);
        $resetDeleted = $d->rowCount();

        $e = $pdo->prepare('DELETE FROM rate_limits WHERE window_start < DATE_SUB(?, INTERVAL 1 DAY)');
        $e->execute([$now]);
        $rlDeleted = $e->rowCount();

        return [
            'access_tokens'      => $accessDeleted,
            'sessions'           => $sessionsDeleted,
            'email_verifications'=> $emailDeleted,
            'password_resets'    => $resetDeleted,
            'rate_limits'        => $rlDeleted,
        ];
    }

    public static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function normalizeClient(string $client): string
    {
        $c = strtolower(trim($client));
        return in_array($c, ['ios', 'web', 'other'], true) ? $c : 'other';
    }
}
