<?php
declare(strict_types=1);

namespace App\Routes;

use App\Database\Db;
use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;

/**
 * Verwaltet `route_shares` — geteilte Links auf Routen.
 *
 * Token-Format: 32 zufällige Bytes → base64url (43 Zeichen). In der
 * DB speichern wir nur den SHA-256-Hash, exakt analog zum Reset-/
 * Verify-Token-Pattern aus M1. Der Klartext-Token sieht der Server
 * nur in der Sekunde der Erzeugung und beim Resolve einmal —
 * schiefgehende DB-Snapshots können den Token nie offenlegen.
 *
 * Lifecycle:
 *  - {@see create()}: erzeugt + persistiert Hash, gibt Klartext-Token
 *    + URL-Path zurück.
 *  - {@see resolve()}: Token → Route-Daten (oder null), inkrementiert
 *    den View-Counter für Statistik.
 *  - {@see revoke()}: Owner kann einen Share invalidieren.
 *  - {@see listForRoute()}: Owner sieht aktive Shares einer Route.
 *  - {@see purgeForRoute()}: Hard-Delete aller Shares (Cleanup).
 */
final class ShareTokenService
{
    public function __construct(private readonly RouteRepository $routes) {}

    /**
     * Erzeugt einen neuen Share-Link für eine Route. Owner-Check
     * passiert hier — der Aufrufer muss `userId` mitschicken.
     *
     * @return array{token:string, share_id:int, expires_at:?string}
     */
    public function create(int $userId, string $routePublicId, ?DateTimeImmutable $expiresAt = null): array
    {
        $route = $this->routes->findByPublicId($routePublicId, $userId);
        if ($route === null) {
            throw new RouteNotFoundException();
        }
        $routeId = (int)$route['_internal']['route_id'];

        $tokenBytes = random_bytes(32);
        // base64url: kompakt, URL-safe, kein Padding.
        $token = rtrim(strtr(base64_encode($tokenBytes), '+/', '-_'), '=');
        $hash  = hash('sha256', $token);

        $now = Clock::nowUtcString();
        $expiresStr = $expiresAt !== null
            ? $expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
            : null;

        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO route_shares (route_id, share_token_hash, created_by, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$routeId, $hash, $userId, $expiresStr, $now]);

        return [
            'token'      => $token,
            'share_id'   => (int)$pdo->lastInsertId(),
            'expires_at' => $expiresStr === null ? null : str_replace(' ', 'T', $expiresStr) . 'Z',
        ];
    }

    /**
     * Token (Klartext aus URL) → Route-Daten + Tags. Liefert null,
     * wenn Token unbekannt, abgelaufen, revoked oder die Route soft-
     * deleted ist.
     *
     * Side effect: erhöht den view_count um 1. Wird auch dann erhöht,
     * wenn sich später beim Resolve der Route herausstellt, dass sie
     * nicht mehr existiert — wir wollen Probing-Statistik abdecken,
     * nicht nur erfolgreiche Aufrufe. Bei abgelaufenen Tokens gibt's
     * **keinen** Increment, weil der Token sonst über die Zeit
     * offenbart, dass er existiert.
     *
     * @return array<string,mixed>|null
     */
    public function resolve(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $hash = hash('sha256', $token);

        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT s.id, s.route_id, s.expires_at, s.revoked_at,
                    r.public_id AS route_public_id, r.deleted_at AS route_deleted_at
               FROM route_shares s
               JOIN routes r ON r.id = s.route_id
              WHERE s.share_token_hash = ? LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        if ($row['revoked_at'] !== null) {
            return null;
        }
        if ($row['expires_at'] !== null) {
            $expires = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$row['expires_at'], new DateTimeZone('UTC'));
            if ($expires === false || $expires <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                return null;
            }
        }
        if ($row['route_deleted_at'] !== null) {
            return null;
        }

        // Increment View-Count. Race-condition-frei genug, da nur Statistik;
        // ein verlorenes UPDATE bei zwei parallelen Resolves ist OK.
        $pdo->prepare('UPDATE route_shares SET view_count = view_count + 1 WHERE id = ?')
            ->execute([(int)$row['id']]);

        // Public Form der Route fetchen — ohne user-id-Constraint, weil
        // der Share-Link die Identität ersetzt.
        $route = $this->routes->findByPublicId((string)$row['route_public_id']);
        if ($route === null) {
            return null;
        }
        $routeId = (int)$route['_internal']['route_id'];
        $route['tags'] = $this->routes->listTags($routeId);

        // Owner-Internals entfernen, plus „nur lesen" Flag setzen.
        unset($route['_internal']);
        $route['shared'] = true;
        return $route;
    }

    /**
     * Markiert einen Share als revoked. Owner-Check über JOIN.
     */
    public function revoke(int $userId, int $shareId): void
    {
        $now = Clock::nowUtcString();
        $stmt = Db::pdo()->prepare(
            'UPDATE route_shares s
                JOIN routes r ON r.id = s.route_id
                SET s.revoked_at = ?
              WHERE s.id = ? AND r.user_id = ? AND s.revoked_at IS NULL'
        );
        $stmt->execute([$now, $shareId, $userId]);
    }

    /**
     * Liste aller (auch abgelaufenen, auch revoked) Shares einer Route.
     * Owner-Check erfolgt im Aufrufer (Controller hat den User-Kontext).
     *
     * @return list<array{id:int, expires_at:?string, revoked_at:?string, view_count:int, created_at:string}>
     */
    public function listForRoute(int $userId, string $routePublicId): array
    {
        $route = $this->routes->findByPublicId($routePublicId, $userId);
        if ($route === null) {
            return [];
        }
        $routeId = (int)$route['_internal']['route_id'];

        $stmt = Db::pdo()->prepare(
            'SELECT id, expires_at, revoked_at, view_count, created_at
               FROM route_shares
              WHERE route_id = ?
              ORDER BY created_at DESC'
        );
        $stmt->execute([$routeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'         => (int)$r['id'],
                'expires_at' => $r['expires_at'] === null ? null : str_replace(' ', 'T', (string)$r['expires_at']) . 'Z',
                'revoked_at' => $r['revoked_at'] === null ? null : str_replace(' ', 'T', (string)$r['revoked_at']) . 'Z',
                'view_count' => (int)$r['view_count'],
                'created_at' => str_replace(' ', 'T', (string)$r['created_at']) . 'Z',
            ];
        }
        return $out;
    }
}
