<?php
declare(strict_types=1);

namespace App\Discovery;

use App\Database\Db;
use App\Routes\RouteRepository;
use PDO;

/**
 * M3 Phase 3: Public-Profil-Lookup für /u/{handle} und API-Profile.
 *
 * Privacy-Garantien:
 *  - Block in irgendeiner Richtung (Viewer hat User geblockt ODER
 *    User hat Viewer geblockt) liefert 404 — und zwar **bevor** die
 *    Profil-Daten überhaupt zusammengebaut werden, damit ein
 *    Angreifer nicht aus Response-Differenzen Existenz/Nicht-Existenz
 *    ableiten kann.
 *  - User ohne `public_handle` sind unsichtbar (404). Selbst wenn der
 *    Handle theoretisch im URL stehen würde, gäbe es keinen Treffer
 *    — die Spalte ist UNIQUE NULL und WHERE handle = ? matcht NULL
 *    nicht.
 *  - Soft-deleted User (`status != 'active'`) sind unsichtbar.
 */
final class ProfileService
{
    public function __construct(
        private readonly DiscoveryService $discovery,
        private readonly RouteRepository $routes,
    ) {}

    /**
     * Liefert das Profil eines Users mit Stats und Viewer-Flags, oder
     * `null`, wenn der User nicht existiert / nicht sichtbar ist
     * (kein Handle, gelöscht, blockiert).
     *
     * @return array<string,mixed>|null
     */
    public function getProfile(string $handle, ?int $viewerUserId): ?array
    {
        $row = $this->resolveHandle($handle);
        if ($row === null) {
            return null;
        }
        $userId = (int)$row['id'];

        // Bidirektionale Block-Prüfung BEVOR wir Profil-Daten sammeln.
        if ($viewerUserId !== null && $this->isBlocked($viewerUserId, $userId)) {
            return null;
        }

        $pdo = Db::pdo();

        $publicRouteCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM routes
              WHERE user_id = {$userId}
                AND visibility = 'public'
                AND deleted_at IS NULL"
        )->fetchColumn();

        $followerCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM follows WHERE followee_id = {$userId}"
        )->fetchColumn();

        $followingCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM follows WHERE follower_id = {$userId}"
        )->fetchColumn();

        $isFollowed = false;
        if ($viewerUserId !== null && $viewerUserId !== $userId) {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM follows WHERE follower_id = ? AND followee_id = ?'
            );
            $stmt->execute([$viewerUserId, $userId]);
            $isFollowed = (bool)$stmt->fetchColumn();
        }

        return [
            'handle'                => (string)$row['public_handle'],
            'display_name'          => $row['display_name'] === null ? null : (string)$row['display_name'],
            'joined_at'             => str_replace(' ', 'T', (string)$row['created_at']) . 'Z',
            'route_count_public'    => $publicRouteCount,
            'follower_count'        => $followerCount,
            'following_count'       => $followingCount,
            // Anonymous viewer → null (nicht false), damit der Client
            // sofort sieht „diese Info gibt es für dich nicht".
            'is_followed_by_viewer' => $viewerUserId === null ? null : $isFollowed,
            'is_self'               => $viewerUserId !== null && $viewerUserId === $userId,
        ];
    }

    /**
     * Listet die public Routen eines Users (per Handle). Nutzt
     * RouteRepository::searchPublic mit dem owner_user_id-Filter,
     * deshalb gelten dieselben Filter-Optionen wie bei Discovery.
     *
     * @param array{
     *     limit?: int, offset?: int,
     *     sort?: 'newest'|'oldest'|'distance_asc'|'distance_desc',
     *     bbox?: array{min_lat:float,min_lon:float,max_lat:float,max_lon:float}|null,
     *     tags?: list<string>,
     *     min_distance_m?: int|null,
     *     max_distance_m?: int|null,
     *     q?: string|null,
     * } $filters
     * @param bool $viewerIsAdmin  Wenn true (Viewer-E-Mail in ADMIN_EMAILS),
     *        werden auch nicht-öffentliche Routen des Profils gelistet —
     *        für Inspektion in der Test-/Entwicklungsphase. Der Aufrufer
     *        (Controller) verantwortet die Admin-Feststellung.
     * @return array{routes: list<array<string,mixed>>, pagination: array<string,mixed>}|null
     */
    public function getProfileRoutes(string $handle, ?int $viewerUserId, array $filters, bool $viewerIsAdmin = false): ?array
    {
        $row = $this->resolveHandle($handle);
        if ($row === null) {
            return null;
        }
        $userId = (int)$row['id'];
        if ($viewerUserId !== null && $this->isBlocked($viewerUserId, $userId)) {
            return null;
        }

        $filters['owner_user_id'] = $userId;
        if ($viewerIsAdmin) {
            $filters['include_non_public'] = true;
        }
        $filters['limit']  = max(1, min(50, (int)($filters['limit']  ?? 20)));
        $filters['offset'] = max(0, (int)($filters['offset'] ?? 0));

        // searchPublic nimmt eine Block-Liste — wenn der Viewer kein
        // Block-Verhältnis zum Owner hat (sonst wäre er hier nicht
        // angekommen), filtern wir gegen die Standard-Block-Liste
        // des Viewers, weil sich darunter andere Co-Routen-User
        // verstecken könnten. Das ist hier vermutlich No-op (eine
        // Route hat genau einen Owner = der profilierte User), aber
        // wir bleiben konsistent mit Discovery.
        $excluded = $viewerUserId !== null ? $this->discovery->blockedUserIds($viewerUserId) : [];

        $res = $this->routes->searchPublic($filters, $excluded);

        // _internal-Felder rausfiltern für Public-Form.
        $clean = [];
        foreach ($res['routes'] as $r) {
            unset($r['_internal']);
            $clean[] = $r;
        }
        return [
            'routes' => $clean,
            'pagination' => [
                'limit'    => $filters['limit'],
                'offset'   => $filters['offset'],
                'total'    => $res['total'],
                'has_more' => ($filters['offset'] + $filters['limit']) < $res['total'],
            ],
        ];
    }

    /**
     * Listet die Follower bzw. Followees eines Profils (per Handle) als
     * volle PublicProfile-Objekte plus Pagination — oder `null`, wenn
     * der Profil-User nicht existiert / nicht sichtbar ist (kein Handle,
     * gelöscht, Block Viewer↔Profil). Gleiche Privacy-Garantien wie
     * `getProfile`: die Block-Prüfung läuft, BEVOR die Liste gebaut wird.
     *
     * @param 'followers'|'following' $direction  `followers` = wer dem
     *        Profil folgt; `following` = wem das Profil folgt.
     * @param array{limit?: int, offset?: int} $filters
     * @return array{
     *     users: list<array<string,mixed>>,
     *     pagination: array{limit: int, offset: int, total: int, has_more: bool}
     * }|null
     */
    public function getProfileFollowList(string $handle, ?int $viewerUserId, string $direction, array $filters): ?array
    {
        $row = $this->resolveHandle($handle);
        if ($row === null) {
            return null;
        }
        $profileId = (int)$row['id'];

        if ($viewerUserId !== null && $this->isBlocked($viewerUserId, $profileId)) {
            return null;
        }

        $limit  = max(1, min(100, (int)($filters['limit']  ?? 50)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        // Richtung bestimmt, welche Spalte der Follow-Beziehung den
        // gelisteten User trägt und welche das Profil fixiert.
        if ($direction === 'followers') {
            $listColumn   = 'f.follower_id';
            $anchorColumn = 'f.followee_id';
        } else {
            $listColumn   = 'f.followee_id';
            $anchorColumn = 'f.follower_id';
        }

        $where  = ["{$anchorColumn} = ?", "u.public_handle IS NOT NULL", "u.status = 'active'"];
        $params = [$profileId];

        // Block-Filter aus Sicht des Viewers: User, die der Viewer
        // blockt ODER die den Viewer blocken, fallen aus Liste UND
        // total heraus (konsistent mit Discovery).
        if ($viewerUserId !== null) {
            $excluded = $this->discovery->blockedUserIds($viewerUserId);
            if ($excluded !== []) {
                $ph = implode(',', array_fill(0, count($excluded), '?'));
                $where[] = "u.id NOT IN ({$ph})";
                foreach ($excluded as $uid) {
                    $params[] = (int)$uid;
                }
            }
        }

        $whereSql = implode("\n           AND ", $where);

        $countSql = "SELECT COUNT(*)
                       FROM follows f
                       JOIN users u ON u.id = {$listColumn}
                      WHERE {$whereSql}";
        $cnt = Db::pdo()->prepare($countSql);
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        // EXISTS für is_followed_by_viewer; Counts als korrelierte
        // Subselects (analog DiscoveryService::searchUsers).
        $viewerExpr = $viewerUserId !== null
            ? "EXISTS(SELECT 1 FROM follows vf WHERE vf.follower_id = ? AND vf.followee_id = u.id)"
            : 'NULL';

        $sql = "SELECT
                    u.id AS uid,
                    u.public_handle, u.display_name, u.created_at,
                    (SELECT COUNT(*) FROM routes r
                       WHERE r.user_id = u.id
                         AND r.visibility = 'public'
                         AND r.deleted_at IS NULL) AS route_count_public,
                    (SELECT COUNT(*) FROM follows fr WHERE fr.followee_id = u.id) AS follower_count,
                    (SELECT COUNT(*) FROM follows fg WHERE fg.follower_id = u.id) AS following_count,
                    {$viewerExpr} AS is_followed_by_viewer
                  FROM follows f
                  JOIN users u ON u.id = {$listColumn}
                 WHERE {$whereSql}
                 ORDER BY f.created_at DESC, u.id DESC
                 LIMIT ? OFFSET ?";

        $stmt = Db::pdo()->prepare($sql);
        $i = 1;
        if ($viewerUserId !== null) {
            $stmt->bindValue($i++, $viewerUserId, PDO::PARAM_INT);
        }
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p, PDO::PARAM_INT);
        }
        $stmt->bindValue($i++, $limit,  PDO::PARAM_INT);
        $stmt->bindValue($i,   $offset, PDO::PARAM_INT);
        $stmt->execute();

        $users = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $uid = (int)$r['uid'];
            $users[] = [
                'handle'                => (string)$r['public_handle'],
                'display_name'          => $r['display_name'] === null ? null : (string)$r['display_name'],
                'joined_at'             => str_replace(' ', 'T', (string)$r['created_at']) . 'Z',
                'route_count_public'    => (int)$r['route_count_public'],
                'follower_count'        => (int)$r['follower_count'],
                'following_count'       => (int)$r['following_count'],
                'is_followed_by_viewer' => $viewerUserId === null ? null : (bool)$r['is_followed_by_viewer'],
                'is_self'               => $viewerUserId !== null && $viewerUserId === $uid,
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
     * Personensuche: Teilstring über Handle UND Anzeigename
     * (case-insensitive). Liefert volle PublicProfile-Objekte plus
     * Pagination — **deckungsgleich** mit `getProfileFollowList`
     * (`/followers`/`/following`), damit der Client beide mit
     * demselben Modell (`UserListEnvelope`) dekodiert.
     *
     * Privacy/Enumeration-Schutz:
     *  - Nur öffentliche, aktive Profile (`public_handle IS NOT NULL`,
     *    `status = 'active'`). Es gibt (Stand jetzt) kein „privates
     *    Profil"-/„nicht auffindbar"-Flag — käme es hinzu, hier mit
     *    in die WHERE-Bedingung aufnehmen.
     *  - Gegenüber dem Viewer blockierte User fallen aus Liste UND
     *    `total` (konsistent mit Discovery/Follow-Listen).
     *  - Das eigene Konto (`is_self`) darf erscheinen.
     *  - Mindestlänge: leeres/zu kurzes `q` (< 2 Zeichen, nach trim)
     *    → leere Liste, **kein Fehler**.
     *
     * Relevanz-Sortierung: exakter Handle-Treffer zuerst, dann
     * Handle-Präfix, dann Anzeigename-Präfix, sonst (Teilstring
     * irgendwo) zuletzt — innerhalb gleicher Stufe alphabetisch
     * nach Handle.
     *
     * @param array{q?: string|null, limit?: int, offset?: int} $filters
     * @return array{
     *     users: list<array<string,mixed>>,
     *     pagination: array{limit: int, offset: int, total: int, has_more: bool}
     * }
     */
    public function searchProfiles(?int $viewerUserId, array $filters): array
    {
        $limit  = max(1, min(100, (int)($filters['limit']  ?? 30)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $q = isset($filters['q']) ? trim((string)$filters['q']) : '';

        // Mindestlänge gegen Enumeration. Zu kurz → leere, aber
        // wohlgeformte Envelope (kein 4xx), wie die App es erwartet.
        if (mb_strlen($q) < 2) {
            return [
                'users'      => [],
                'pagination' => [
                    'limit'    => $limit,
                    'offset'   => $offset,
                    'total'    => 0,
                    'has_more' => false,
                ],
            ];
        }

        // Konsistent mit DiscoveryService::searchUsers: LOWER()+LIKE,
        // damit die Suche unabhängig von der Spalten-Collation
        // case-insensitive ist. Wildcards (% _) werden bewusst NICHT
        // escaped — gleiche Konvention wie der bestehende
        // /discover/users-Suchpfad.
        $qLower   = mb_strtolower($q);
        $contains = '%' . $qLower . '%';
        $prefix   = $qLower . '%';

        $where  = [
            'u.public_handle IS NOT NULL',
            "u.status = 'active'",
            '(LOWER(u.public_handle) LIKE ? OR LOWER(u.display_name) LIKE ?)',
        ];
        $whereParams = [$contains, $contains];

        if ($viewerUserId !== null) {
            $excluded = $this->discovery->blockedUserIds($viewerUserId);
            if ($excluded !== []) {
                $ph = implode(',', array_fill(0, count($excluded), '?'));
                $where[] = "u.id NOT IN ({$ph})";
                foreach ($excluded as $uid) {
                    $whereParams[] = (int)$uid;
                }
            }
        }

        $whereSql = implode("\n           AND ", $where);

        $countSql = "SELECT COUNT(*) FROM users u WHERE {$whereSql}";
        $cnt = Db::pdo()->prepare($countSql);
        $cnt->execute($whereParams);
        $total = (int)$cnt->fetchColumn();

        // EXISTS für is_followed_by_viewer; Counts als korrelierte
        // Subselects — identisch zu getProfileFollowList.
        $viewerExpr = $viewerUserId !== null
            ? "EXISTS(SELECT 1 FROM follows vf WHERE vf.follower_id = ? AND vf.followee_id = u.id)"
            : 'NULL';

        $sql = "SELECT
                    u.id AS uid,
                    u.public_handle, u.display_name, u.created_at,
                    (SELECT COUNT(*) FROM routes r
                       WHERE r.user_id = u.id
                         AND r.visibility = 'public'
                         AND r.deleted_at IS NULL) AS route_count_public,
                    (SELECT COUNT(*) FROM follows fr WHERE fr.followee_id = u.id) AS follower_count,
                    (SELECT COUNT(*) FROM follows fg WHERE fg.follower_id = u.id) AS following_count,
                    {$viewerExpr} AS is_followed_by_viewer
                  FROM users u
                 WHERE {$whereSql}
                 ORDER BY
                    CASE
                        WHEN LOWER(u.public_handle) = ?    THEN 0
                        WHEN LOWER(u.public_handle) LIKE ? THEN 1
                        WHEN LOWER(u.display_name)  LIKE ? THEN 2
                        ELSE 3
                    END,
                    u.public_handle ASC
                 LIMIT ? OFFSET ?";

        $stmt = Db::pdo()->prepare($sql);
        $i = 1;
        if ($viewerUserId !== null) {
            $stmt->bindValue($i++, $viewerUserId, PDO::PARAM_INT);
        }
        foreach ($whereParams as $p) {
            $stmt->bindValue($i++, $p, is_int($p) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        // Relevanz-Parameter (ORDER BY CASE): exakter Handle, Handle-
        // Präfix, Anzeigename-Präfix.
        $stmt->bindValue($i++, $qLower, PDO::PARAM_STR);
        $stmt->bindValue($i++, $prefix, PDO::PARAM_STR);
        $stmt->bindValue($i++, $prefix, PDO::PARAM_STR);
        $stmt->bindValue($i++, $limit,  PDO::PARAM_INT);
        $stmt->bindValue($i,   $offset, PDO::PARAM_INT);
        $stmt->execute();

        $users = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $uid = (int)$r['uid'];
            $users[] = [
                'handle'                => (string)$r['public_handle'],
                'display_name'          => $r['display_name'] === null ? null : (string)$r['display_name'],
                'joined_at'             => str_replace(' ', 'T', (string)$r['created_at']) . 'Z',
                'route_count_public'    => (int)$r['route_count_public'],
                'follower_count'        => (int)$r['follower_count'],
                'following_count'       => (int)$r['following_count'],
                'is_followed_by_viewer' => $viewerUserId === null ? null : (bool)$r['is_followed_by_viewer'],
                'is_self'               => $viewerUserId !== null && $viewerUserId === $uid,
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
     * @return array<string,mixed>|null  raw user row oder null
     */
    private function resolveHandle(string $handle): ?array
    {
        $stmt = Db::pdo()->prepare(
            "SELECT id, public_handle, display_name, created_at
               FROM users
              WHERE public_handle = ?
                AND status = 'active'
              LIMIT 1"
        );
        $stmt->execute([$handle]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function isBlocked(int $viewerUserId, int $targetUserId): bool
    {
        if ($viewerUserId === $targetUserId) {
            return false;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT 1 FROM user_blocks
              WHERE (blocker_id = ? AND blocked_id = ?)
                 OR (blocker_id = ? AND blocked_id = ?)
              LIMIT 1'
        );
        $stmt->execute([$viewerUserId, $targetUserId, $targetUserId, $viewerUserId]);
        return (bool)$stmt->fetchColumn();
    }
}
