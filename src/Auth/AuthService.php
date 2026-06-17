<?php
declare(strict_types=1);

namespace App\Auth;

use App\Config\Config;
use App\Database\Db;
use App\Mail\MailService;
use App\Support\Clock;
use App\Support\Uuid;
use PDO;

/**
 * Core authentication & account business logic. Controllers (API + web)
 * stay thin and delegate every rule to this service.
 */
final class AuthService
{
    public function __construct(
        private readonly Config $config,
        private readonly PasswordService $passwords,
        private readonly TokenService $tokens,
        private readonly MailService $mailer,
    ) {}

    /**
     * Registrierung. Antwortet aus Sicht des Aufrufers immer identisch
     * (kein Tokens-Response, generischer 202-Status), damit es keine
     * Account-Enumeration über diesen Endpoint gibt.
     *
     * Verhalten:
     *  - neue E-Mail            → User anlegen + Verify-Mail senden
     *  - bestehend, unverified  → Verify-Mail erneut senden
     *  - bestehend, verified    → silent no-op (Mail nur, falls man sich
     *                             später für eine "someone tried" Mail
     *                             entscheidet — aktuell bewusst nicht,
     *                             um keinen Mail-Spam-Vektor zu öffnen)
     *  - deleted/disabled       → silent no-op
     */
    public function register(string $email, string $password, ?string $displayName): void
    {
        $pdo = Db::pdo();
        $now = Clock::nowUtcString();

        $stmt = $pdo->prepare('SELECT id, status, email_verified_at FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ($existing['status'] === 'active' && $existing['email_verified_at'] === null) {
                $this->createAndSendVerification(
                    (int)$existing['id'],
                    $email,
                    $displayName, // nur als Mail-Anrede, ändert nichts am gespeicherten Profil
                );
            }
            return;
        }

        $publicId = Uuid::v4();
        $hash     = $this->passwords->hash($password);

        $rawVerify = TokenService::randomToken();
        $verifyExpires = Clock::utcPlusSeconds($this->config->int('EMAIL_VERIFY_TTL', 86400));

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO users (public_id, email, password_hash, display_name, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, "active", ?, ?)'
            );
            $ins->execute([$publicId, $email, $hash, $displayName, $now, $now]);
            $userId = (int)$pdo->lastInsertId();

            $vins = $pdo->prepare(
                'INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at)
                 VALUES (?, ?, ?, ?)'
            );
            $vins->execute([$userId, TokenService::hashToken($rawVerify), $verifyExpires, $now]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->sendVerifyMail($email, $displayName, $rawVerify);
    }

    /**
     * @return array{tokens:array,user:array}
     * @throws AuthException
     */
    public function login(string $email, string $password, string $client, ?string $ua, ?string $ipBin): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT id, email, password_hash, status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active' || !$this->passwords->verify($password, $row['password_hash'])) {
            throw new AuthException('invalid_credentials', 'Ungültige Anmeldedaten.', 401);
        }

        if ($this->passwords->needsRehash($row['password_hash'])) {
            $newHash = $this->passwords->hash($password);
            $upd = $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
            $upd->execute([$newHash, Clock::nowUtcString(), (int)$row['id']]);
        }

        $tokens = $this->tokens->issueSession((int)$row['id'], $client, $ua, $ipBin);
        return [
            'tokens' => $tokens,
            'user'   => $this->loadUserPublic((int)$row['id']),
        ];
    }

    /**
     * @return array{tokens:array,user:array}
     * @throws AuthException
     */
    public function refresh(string $refreshToken, ?string $ua, ?string $ipBin): array
    {
        $rotated = $this->tokens->rotateRefresh($refreshToken, $ua, $ipBin);
        if ($rotated === null) {
            throw new AuthException('invalid_token', 'Refresh-Token ist ungültig oder abgelaufen.', 401);
        }

        return [
            'tokens' => $rotated,
            'user'   => $this->loadUserPublic($rotated['user_id']),
        ];
    }

    public function logout(int $sessionId): void
    {
        $this->tokens->revokeSession($sessionId);
    }

    public function logoutAll(int $userId): void
    {
        $this->tokens->revokeAllForUser($userId);
    }

    public function updateProfile(int $userId, ?string $displayName): array
    {
        $pdo = Db::pdo();
        $pdo->prepare('UPDATE users SET display_name = ?, updated_at = ? WHERE id = ?')
            ->execute([$displayName, Clock::nowUtcString(), $userId]);
        return $this->loadUserPublic($userId);
    }

    /**
     * @throws AuthException
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT password_hash, email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || !$this->passwords->verify($currentPassword, $row['password_hash'])) {
            throw new AuthException('invalid_credentials', 'Aktuelles Passwort ist falsch.', 401);
        }

        $hash = $this->passwords->hash($newPassword);
        $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')
            ->execute([$hash, Clock::nowUtcString(), $userId]);

        // H1: Auch die aufrufende Session entwerten — wer ein Passwort
        // ändert, soll den Vorgang ggf. mit frischem Login bestätigen.
        // Schützt zusätzlich, falls Tokens vor dem Wechsel abgegriffen wurden.
        $this->tokens->revokeAllForUser($userId);
    }

    public function requestPasswordReset(string $email, ?string $ipBin): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT id, email, display_name, status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active') {
            // M3: Timing-Side-Channel mindern — der "echte" Pfad führt einen
            // Argon2id-Hash plus DB-Insert plus Mailversand aus, was deutlich
            // länger dauert als ein simples SELECT-no-row. Wir gleichen die
            // Latenz an, indem wir hier ebenfalls einen Argon2id-Hash auf
            // einem Dummy-Wert berechnen. Das ist nicht perfekt (kein
            // DB-Insert/Mail-Roundtrip), reduziert aber das Signal um
            // Größenordnungen — und ist deutlich billiger als asynchroner
            // Mailversand für jetzt.
            $this->passwords->hash(TokenService::randomToken());
            return;
        }

        $raw = TokenService::randomToken();
        $expires = Clock::utcPlusSeconds($this->config->int('PASSWORD_RESET_TTL', 3600));
        $now = Clock::nowUtcString();

        $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at, request_ip)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([(int)$row['id'], TokenService::hashToken($raw), $expires, $now, $ipBin]);

        $this->sendResetMail($row['email'], $row['display_name'], $raw);
    }

    /**
     * @throws AuthException
     */
    public function resetPassword(string $token, string $newPassword): void
    {
        $pdo = Db::pdo();
        $now = Clock::nowUtcString();
        $tokenHash = TokenService::hashToken($token);

        // C3: Atomar konsumieren statt SELECT-then-UPDATE — verhindert Races,
        // bei denen zwei parallele Requests denselben Token zweimal einlösen.
        $claim = $pdo->prepare(
            'UPDATE password_resets
                SET consumed_at = ?
              WHERE token_hash = ? AND expires_at > ? AND consumed_at IS NULL'
        );
        $claim->execute([$now, $tokenHash, $now]);
        if ($claim->rowCount() === 0) {
            throw new AuthException(
                'invalid_token',
                'Reset-Token ist ungültig oder abgelaufen.',
                410,
            );
        }

        $stmt = $pdo->prepare(
            'SELECT id, user_id FROM password_resets WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AuthException('invalid_token', 'Reset-Token ist ungültig oder abgelaufen.', 410);
        }

        $hash = $this->passwords->hash($newPassword);
        $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')
            ->execute([$hash, $now, (int)$row['user_id']]);

        // M11: Token-Hash nicht bis zum Cleanup-Cron aufbewahren — direkt
        // löschen. Reduziert das Zeitfenster, in dem aus einer evtl.
        // kompromittierten DB-Kopie verbrauchte Hashes mit Klartext-Tokens
        // korreliert werden könnten.
        $pdo->prepare('DELETE FROM password_resets WHERE id = ?')
            ->execute([(int)$row['id']]);

        $this->tokens->revokeAllForUser((int)$row['user_id']);
    }

    /**
     * @return array user
     * @throws AuthException
     */
    public function verifyEmail(string $token): array
    {
        $pdo = Db::pdo();
        $now = Clock::nowUtcString();
        $tokenHash = TokenService::hashToken($token);

        // C3: Atomarer Token-Consume (siehe resetPassword).
        $claim = $pdo->prepare(
            'UPDATE email_verifications
                SET consumed_at = ?
              WHERE token_hash = ? AND expires_at > ? AND consumed_at IS NULL'
        );
        $claim->execute([$now, $tokenHash, $now]);
        if ($claim->rowCount() === 0) {
            throw new AuthException(
                'invalid_token',
                'Verifizierungstoken ist ungültig oder abgelaufen.',
                410,
            );
        }

        $stmt = $pdo->prepare(
            'SELECT id, user_id FROM email_verifications WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new AuthException('invalid_token', 'Verifizierungstoken ist ungültig oder abgelaufen.', 410);
        }

        $pdo->prepare('UPDATE users SET email_verified_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$now, $now, (int)$row['user_id']]);

        // M11: konsumierten Token sofort entfernen (siehe resetPassword).
        $pdo->prepare('DELETE FROM email_verifications WHERE id = ?')
            ->execute([(int)$row['id']]);

        return $this->loadUserPublic((int)$row['user_id']);
    }

    public function resendVerification(string $email): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT id, email, display_name, email_verified_at, status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active' || $row['email_verified_at'] !== null) {
            return;
        }
        $this->createAndSendVerification((int)$row['id'], $row['email'], $row['display_name']);
    }

    public function resendVerificationForUser(int $userId): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT email, display_name, email_verified_at FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || $row['email_verified_at'] !== null) {
            return;
        }
        $this->createAndSendVerification($userId, $row['email'], $row['display_name']);
    }

    /**
     * @throws AuthException
     */
    public function deleteAccount(int $userId, string $password): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND status = "active" LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || !$this->passwords->verify($password, $row['password_hash'])) {
            throw new AuthException('invalid_credentials', 'Ungültiges Passwort.', 401);
        }

        $now = Clock::nowUtcString();
        $scrubbedEmail = "deleted+{$userId}@invalid";

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE users SET status = "deleted", deleted_at = ?, email = ?, display_name = NULL, updated_at = ?
                 WHERE id = ?'
            )->execute([$now, $scrubbedEmail, $now, $userId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->tokens->revokeAllForUser($userId);
    }

    /**
     * L2: wirft, statt ein leeres Array zu liefern. Aufrufer können sich
     * darauf verlassen, dass der Rückgabewert die public-User-Form hat.
     *
     * @return array<string,mixed> public user representation
     */
    public function loadUserPublic(int $userId): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT public_id, email, display_name, email_verified_at, created_at
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException("User #{$userId} nicht gefunden — Datenintegritätsverletzung.");
        }
        return [
            'id'             => $row['public_id'],
            'email'          => $row['email'],
            'display_name'   => $row['display_name'],
            'email_verified' => $row['email_verified_at'] !== null,
            'created_at'     => Clock::toIso8601($row['created_at']),
        ];
    }

    private function createAndSendVerification(int $userId, string $email, ?string $displayName): void
    {
        $raw = TokenService::randomToken();
        $expires = Clock::utcPlusSeconds($this->config->int('EMAIL_VERIFY_TTL', 86400));
        Db::pdo()->prepare(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?)'
        )->execute([$userId, TokenService::hashToken($raw), $expires, Clock::nowUtcString()]);

        $this->sendVerifyMail($email, $displayName, $raw);
    }

    private function sendVerifyMail(string $email, ?string $displayName, string $rawToken): void
    {
        $url = rtrim((string)$this->config->get('APP_URL', ''), '/') . '/verify-email?token=' . urlencode($rawToken);
        $hours = max(1, (int)round($this->config->int('EMAIL_VERIFY_TTL', 86400) / 3600));
        $ok = $this->mailer->send($email, $displayName, 'verify_email', [
            'display_name' => $displayName,
            'verify_url'   => $url,
            'hours_valid'  => $hours,
            'app_name'     => 'GravelExplorer',
        ]);
        // H7/L6: bei Mail-Fehlern den Operator informieren, aber den
        // User-Flow nicht hart brechen — der Resend-Endpoint kann es
        // wiederholen. Token wurde bereits in DB persistiert.
        if (!$ok) {
            error_log("AuthService: Verify-Mail an {$email} konnte nicht versendet werden.");
        }
    }

    private function sendResetMail(string $email, ?string $displayName, string $rawToken): void
    {
        $url = rtrim((string)$this->config->get('APP_URL', ''), '/') . '/reset-password?token=' . urlencode($rawToken);
        $minutes = max(1, (int)round($this->config->int('PASSWORD_RESET_TTL', 3600) / 60));
        $ok = $this->mailer->send($email, $displayName, 'reset_password', [
            'display_name' => $displayName,
            'reset_url'    => $url,
            'minutes_valid'=> $minutes,
            'app_name'     => 'GravelExplorer',
        ]);
        if (!$ok) {
            error_log("AuthService: Reset-Mail an {$email} konnte nicht versendet werden.");
        }
    }
}
