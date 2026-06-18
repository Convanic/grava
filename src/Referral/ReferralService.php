<?php
declare(strict_types=1);

namespace App\Referral;

use App\Config\Config;
use App\Database\Db;
use App\Support\Clock;
use PDO;

/**
 * M7: Empfehlungen / Referrals (Code-/Link-Attribution).
 *
 * Verantwortlich für:
 *   - Lazy-Erzeugung eines eindeutigen Referral-Codes je User
 *   - Verknüpfung bei der Registrierung (referred_by + referrals-Zeile)
 *   - Status-Fortschritt (registered → verified → activated)
 *   - Eigene Statistik (GET /referrals/me)
 *   - Admin-Auswertung (Werber-Liste + Conversion + Bestenliste)
 *
 * Datenschutz: Es werden KEINE fremden E-Mails gespeichert. Nach außen
 * (App) geben wir nur öffentliche Handles aus, niemals E-Mails.
 */
final class ReferralService
{
    /** Versuche, bei Code-Kollision (uq_users_referral_code) neu zu würfeln. */
    private const MAX_CODE_TRIES = 8;

    /** Zeichensatz für die Zufalls-Suffixe (base36, ohne Groß/Klein-Mix). */
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public function __construct(private readonly Config $config) {}

    // ------------------------------------------------------------------
    // Code-Erzeugung
    // ------------------------------------------------------------------

    /**
     * Liefert den (ggf. erst jetzt erzeugten) eindeutigen Code des Users.
     * Idempotent: bereits gesetzte Codes bleiben unverändert.
     */
    public function ensureCode(int $userId): string
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT referral_code, public_handle, display_name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException("User #{$userId} nicht gefunden.");
        }
        if ($row['referral_code'] !== null && $row['referral_code'] !== '') {
            return (string)$row['referral_code'];
        }

        $base = $this->slugBase((string)($row['public_handle'] ?? ''), (string)($row['display_name'] ?? ''));

        for ($try = 0; $try < self::MAX_CODE_TRIES; $try++) {
            $code = $this->buildCandidate($base);
            $upd = $pdo->prepare('UPDATE users SET referral_code = ? WHERE id = ? AND (referral_code IS NULL OR referral_code = "")');
            try {
                $upd->execute([$code, $userId]);
            } catch (\PDOException $e) {
                // 1062 = Duplicate entry auf uq_users_referral_code → neu würfeln.
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    continue;
                }
                throw $e;
            }
            if ($upd->rowCount() > 0) {
                return $code;
            }
            // Kein Update (z. B. parallel gesetzt) → frisch lesen.
            $re = $pdo->prepare('SELECT referral_code FROM users WHERE id = ? LIMIT 1');
            $re->execute([$userId]);
            $existing = (string)($re->fetchColumn() ?: '');
            if ($existing !== '') {
                return $existing;
            }
        }

        throw new \RuntimeException('Konnte keinen eindeutigen Referral-Code erzeugen.');
    }

    // ------------------------------------------------------------------
    // Registrierung verknüpfen
    // ------------------------------------------------------------------

    /**
     * Löst einen Code zum Werber (aktiver User) auf. Liefert dessen interne
     * ID oder null, wenn der Code unbekannt ist.
     */
    public function resolveReferrer(string $code): ?int
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE referral_code = ? AND status = "active" LIMIT 1');
        $stmt->execute([$code]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * Verknüpft einen frisch registrierten User mit seinem Werber.
     *
     * Läuft bewusst auf der gemeinsamen PDO-Verbindung — wird von
     * {@see \App\Auth\AuthService::register()} INNERHALB der dortigen
     * Registrierungs-Transaktion aufgerufen, sodass User-Insert und
     * referrals-Zeile atomar zusammen committet werden.
     *
     * Verhalten:
     *  - leerer/unbekannter Code → No-Op (Registrierung wird nicht blockiert)
     *  - Selbst-Referral         → No-Op
     *  - sonst: users.referred_by setzen + referrals-Zeile 'registered'
     *           (uq_ref_referred verhindert Doppelzählung)
     */
    public function linkOnRegister(int $referredUserId, ?string $code): void
    {
        if ($code === null || trim($code) === '') {
            return;
        }
        $referrerId = $this->resolveReferrer($code);
        if ($referrerId === null || $referrerId === $referredUserId) {
            return;
        }

        $pdo = Db::pdo();
        $now = Clock::nowUtcString();

        $pdo->prepare('UPDATE users SET referred_by = ? WHERE id = ?')
            ->execute([$referrerId, $referredUserId]);

        // INSERT IGNORE deckt die Unique auf referred_user_id ab — ein
        // Geworbener zählt max. 1×.
        $pdo->prepare(
            'INSERT IGNORE INTO referrals
                (referrer_id, referred_user_id, code, status, registered_at)
             VALUES (?, ?, ?, "registered", ?)'
        )->execute([$referrerId, $referredUserId, trim($code), $now]);
    }

    // ------------------------------------------------------------------
    // Status-Fortschritt
    // ------------------------------------------------------------------

    /**
     * E-Mail des Geworbenen verifiziert → Stufe `verified` (die zählende).
     * Nur registered → verified; idempotent.
     */
    public function markVerified(int $referredUserId): void
    {
        Db::pdo()->prepare(
            'UPDATE referrals
                SET status = "verified", verified_at = ?
              WHERE referred_user_id = ? AND status = "registered"'
        )->execute([Clock::nowUtcString(), $referredUserId]);
    }

    /**
     * Erster erfolgreicher Routen-Upload des Geworbenen → Stufe `activated`.
     * Nur verified → activated; idempotent (spätere Uploads sind No-Ops).
     */
    public function markActivated(int $referredUserId): void
    {
        Db::pdo()->prepare(
            'UPDATE referrals
                SET status = "activated", activated_at = ?
              WHERE referred_user_id = ? AND status = "verified"'
        )->execute([Clock::nowUtcString(), $referredUserId]);
    }

    // ------------------------------------------------------------------
    // Eigene Statistik (App)
    // ------------------------------------------------------------------

    /**
     * Liefert Code, Link, kumulative Counts je erreichter Stufe und die
     * Liste der Geworbenen (öffentliche Handles, ohne E-Mails).
     *
     * @return array{code:string,url:string,counts:array{registered:int,verified:int,activated:int},referred:list<array{handle:?string,status:string,joined_at:string}>}
     */
    public function overviewForUser(int $userId): array
    {
        $code = $this->ensureCode($userId);
        $pdo = Db::pdo();

        // Kumulativ: registered = alle, verified = mit ≥verified,
        // activated = mit activated.
        $countsStmt = $pdo->prepare(
            'SELECT
                COUNT(*)                                              AS registered,
                SUM(status IN ("verified","activated"))               AS verified,
                SUM(status = "activated")                             AS activated
             FROM referrals WHERE referrer_id = ?'
        );
        $countsStmt->execute([$userId]);
        $c = $countsStmt->fetch() ?: [];

        $listStmt = $pdo->prepare(
            'SELECT u.public_handle AS handle, r.status, r.registered_at
               FROM referrals r
               JOIN users u ON u.id = r.referred_user_id
              WHERE r.referrer_id = ?
              ORDER BY r.registered_at DESC, r.id DESC'
        );
        $listStmt->execute([$userId]);

        $referred = [];
        foreach ($listStmt->fetchAll() as $r) {
            $referred[] = [
                'handle'    => $r['handle'] !== null ? (string)$r['handle'] : null,
                'status'    => (string)$r['status'],
                'joined_at' => Clock::toIso8601($r['registered_at']),
            ];
        }

        return [
            'code'   => $code,
            'url'    => $this->linkFor($code),
            'counts' => [
                'registered' => (int)($c['registered'] ?? 0),
                'verified'   => (int)($c['verified'] ?? 0),
                'activated'  => (int)($c['activated'] ?? 0),
            ],
            'referred' => $referred,
        ];
    }

    /** Baut den öffentlichen Einlade-Link zu einem Code. */
    public function linkFor(string $code): string
    {
        $base = (string)$this->config->get('REFERRAL_LINK_BASE', '');
        if ($base === '') {
            $base = (string)$this->config->get('APP_URL', '');
        }
        return rtrim($base, '/') . '/i/' . rawurlencode($code);
    }

    // ------------------------------------------------------------------
    // Admin-Auswertung
    // ------------------------------------------------------------------

    /**
     * Werber-Liste mit Conversion, optional auf einen Zeitraum (nach
     * registered_at) gefiltert. Sortiert als Bestenliste (verified desc).
     *
     * @param ?string $from ISO-/SQL-Datum (inklusive), z. B. 2026-06-01
     * @param ?string $to   ISO-/SQL-Datum (inklusive)
     * @return list<array<string,mixed>>
     */
    public function adminReport(?string $from = null, ?string $to = null): array
    {
        $pdo = Db::pdo();

        $where = [];
        $params = [];
        if ($from !== null && $from !== '') {
            $where[] = 'r.registered_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== null && $to !== '') {
            $where[] = 'r.registered_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

        $sql =
            'SELECT
                r.referrer_id,
                u.public_handle,
                u.display_name,
                u.email,
                COUNT(*)                                AS registered,
                SUM(r.status IN ("verified","activated")) AS verified,
                SUM(r.status = "activated")             AS activated,
                MIN(r.registered_at)                    AS first_at,
                MAX(r.registered_at)                    AS last_at
             FROM referrals r
             JOIN users u ON u.id = r.referrer_id'
            . $whereSql .
            ' GROUP BY r.referrer_id, u.public_handle, u.display_name, u.email
              ORDER BY verified DESC, registered DESC, last_at DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $registered = (int)$r['registered'];
            $verified   = (int)$r['verified'];
            $activated  = (int)$r['activated'];
            $rows[] = [
                'referrer_id'   => (int)$r['referrer_id'],
                'handle'        => $r['public_handle'] !== null ? (string)$r['public_handle'] : null,
                'display_name'  => $r['display_name'] !== null ? (string)$r['display_name'] : null,
                'email'         => (string)$r['email'],
                'registered'    => $registered,
                'verified'      => $verified,
                'activated'     => $activated,
                'conversion'    => $registered > 0 ? round($verified / $registered, 4) : 0.0,
                'first_at'      => Clock::toIso8601($r['first_at']),
                'last_at'       => Clock::toIso8601($r['last_at']),
            ];
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    private function slugBase(string $handle, string $displayName): string
    {
        $source = $handle !== '' ? $handle : $displayName;
        $slug = strtolower($source);
        // Umlaute grob transliterieren, damit z. B. "Björn" → "bjoern".
        $slug = strtr($slug, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e',
            'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);
        $slug = (string)preg_replace('/[^a-z0-9]+/', '', $slug);
        // Code-Spalte ist VARCHAR(16); Suffix "-XXXX" (5) → Slug max. 11.
        return substr($slug, 0, 11);
    }

    private function buildCandidate(string $base): string
    {
        if ($base === '') {
            // Kein Handle/Name → rein zufälliger 6-stelliger Code.
            return $this->randomString(6);
        }
        return $base . '-' . $this->randomString(4);
    }

    private function randomString(int $len): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }
}
