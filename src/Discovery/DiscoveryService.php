<?php
declare(strict_types=1);

namespace App\Discovery;

use App\Database\Db;
use App\Routes\RouteRepository;
use PDO;

/**
 * M3 Phase 2: Discovery-Suche über öffentliche Routen und User mit
 * gesetztem `public_handle`.
 *
 * Anonymous-Aufrufer übergeben `$viewerUserId = null` → es wird
 * kein Block-Filter angewendet (es gibt schließlich keine Block-
 * Beziehung gegenüber „der Welt"). Eingeloggte Aufrufer übergeben
 * ihre interne User-ID; wir blenden Routen/User aus, die in
 * irgendeiner Block-Richtung mit dem Viewer involviert sind.
 *
 * Die heavy-lifting-SQL-Logik liegt in RouteRepository::searchPublic;
 * dieser Service zentralisiert nur die Block-Liste und die
 * User-Suche.
 */
final class DiscoveryService
{
    public function __construct(private readonly RouteRepository $routes) {}

    /**
     * @param array{
     *     bbox?: array{min_lat: float, min_lon: float, max_lat: float, max_lon: float}|null,
     *     tags?: list<string>,
     *     min_distance_m?: int|null,
     *     max_distance_m?: int|null,
     *     q?: string|null,
     *     sort?: 'newest'|'oldest'|'distance_asc'|'distance_desc',
     *     limit: int,
     *     offset: int,
     * } $filters
     * @return array{
     *     routes: list<array<string,mixed>>,
     *     pagination: array{limit: int, offset: int, total: int, has_more: bool}
     * }
     */
    public function searchRoutes(array $filters, ?int $viewerUserId): array
    {
        $excluded = $viewerUserId !== null ? $this->blockedUserIds($viewerUserId) : [];
        $res = $this->routes->searchPublic($filters, $excluded);
        $limit  = max(1, min(50, (int)($filters['limit']  ?? 20)));
        $offset = max(0, (int)($filters['offset'] ?? 0));
        return [
            'routes'     => $res['routes'],
            'pagination' => [
                'limit'    => $limit,
                'offset'   => $offset,
                'total'    => $res['total'],
                'has_more' => ($offset + $limit) < $res['total'],
            ],
        ];
    }

    /**
     * @param array{q?: string|null, limit: int, offset: int} $filters
     * @return array{
     *     users: list<array<string,mixed>>,
     *     pagination: array{limit: int, offset: int, total: int, has_more: bool}
     * }
     */
    public function searchUsers(array $filters, ?int $viewerUserId): array
    {
        $limit  = max(1, min(50, (int)($filters['limit']  ?? 20)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $where  = ["u.public_handle IS NOT NULL", "u.status = 'active'"];
        $params = [];

        if (!empty($filters['q'])) {
            $where[]  = '(LOWER(u.public_handle) LIKE ? OR LOWER(u.display_name) LIKE ?)';
            $params[] = '%' . strtolower((string)$filters['q']) . '%';
            $params[] = '%' . strtolower((string)$filters['q']) . '%';
        }

        if ($viewerUserId !== null) {
            $excluded = $this->blockedUserIds($viewerUserId);
            if ($excluded !== []) {
                $ph = implode(',', array_fill(0, count($excluded), '?'));
                $where[] = "u.id NOT IN ({$ph})";
                foreach ($excluded as $uid) {
                    $params[] = (int)$uid;
                }
            }
        }

        $whereSql = implode("\n           AND ", $where);

        $countSql = "SELECT COUNT(*) FROM users u WHERE {$whereSql}";
        $cnt = Db::pdo()->prepare($countSql);
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        // Sub-Select für public-Route-Count statt JOIN+GROUP BY:
        // einfacher zu lesen und MySQL kompiliert das in einen
        // korrelierten Sub-Query, was bei n=20 Result-Zeilen
        // billig genug ist.
        $sql = "SELECT
                    u.public_handle, u.display_name, u.created_at,
                    (SELECT COUNT(*) FROM routes r
                       WHERE r.user_id = u.id
                         AND r.visibility = 'public'
                         AND r.deleted_at IS NULL) AS route_count_public
                  FROM users u
                 WHERE {$whereSql}
                 ORDER BY route_count_public DESC, u.created_at DESC
                 LIMIT ? OFFSET ?";

        $stmt = Db::pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($i,   $offset, PDO::PARAM_INT);
        $stmt->execute();

        $users = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $users[] = [
                'handle'             => (string)$row['public_handle'],
                'display_name'       => $row['display_name'] === null ? null : (string)$row['display_name'],
                'route_count_public' => (int)$row['route_count_public'],
                'joined_at'          => str_replace(' ', 'T', (string)$row['created_at']) . 'Z',
            ];
        }

        return [
            'users'      => $users,
            'pagination' => [
                'limit'    => $limit,
                'offset'   => $offset,
                'total'    => $total,
                'has_more' => ($offset + $limit) < $total,
            ],
        ];
    }

    /**
     * Liefert alle User-IDs, die gegenüber `$viewerUserId` in
     * irgendeiner Richtung blockiert sind — d. h. der Viewer hat
     * sie blockiert ODER sie haben den Viewer blockiert. Diese
     * Liste fließt als NOT-IN-Filter in alle Discovery-/Profile-
     * Queries.
     *
     * @return list<int>
     */
    public function blockedUserIds(int $viewerUserId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT blocked_id AS uid FROM user_blocks WHERE blocker_id = ?
             UNION
             SELECT blocker_id AS uid FROM user_blocks WHERE blocked_id = ?'
        );
        $stmt->execute([$viewerUserId, $viewerUserId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int)$id;
        }
        return $ids;
    }
}
