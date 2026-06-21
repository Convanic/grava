<?php
declare(strict_types=1);

namespace App\Routes;

use App\Database\Db;
use App\Support\Clock;
use PDO;
use RuntimeException;

/**
 * Datenbank-Zugriffsschicht für Routen.
 *
 * Schreibmethoden geben numerische IDs zurück. Lesemethoden geben
 * **Public-Form**-Arrays zurück — das ist die Form, die der
 * Controller (und letztendlich die App/Web-View) erwartet.
 *
 * Centroid wird beim INSERT/UPDATE explizit als
 *
 *     ST_SRID(POINT(?lon, ?lat), 4326)
 *
 * geschrieben — empirisch verifiziert mit MAMP MySQL 8.0:
 * `ST_Latitude(POINT(a, b))` liefert `b`, also den zweiten Arg.
 * Damit nach einem Round-Trip die Werte stimmen, muss die
 * Reihenfolge **(LON, LAT)** sein. Das deckt sich mit der
 * GeoJSON-Konvention (`[lon, lat]`) und vermeidet Verwirrung.
 *
 * Phase 1 hatte ich aufgrund eines fehlinterpretierten Smoke-Tests
 * fälschlich (LAT, LON) als Konvention notiert — Phase 3-Smoke
 * mit `ST_Latitude` und `ST_Longitude` als Kreuztest hat den Fehler
 * aufgedeckt.
 *
 * Die `head_version_id`-FK-Schlaufe ({@see migrations/0003_routes.sql})
 * wird durch ein dreistufiges Insert-Pattern aufgelöst:
 *  1. Route mit `head_version_id = NULL` einfügen
 *  2. v1 in route_versions einfügen
 *  3. Route mit `head_version_id` aktualisieren
 *
 * Aufrufer bekommen das in {@see RouteService::createOrAddVersion()}
 * gekapselt — Repository hat nur die einzelnen Bausteine.
 */
final class RouteRepository
{
    /**
     * Legt eine neue Route ohne head-Version an. Centroid + BBox
     * stammen direkt aus den initialen Stats (= v1).
     *
     * @return int route_id (interner BIGINT-PK)
     */
    public function insertRouteShell(
        int $userId,
        string $publicId,
        ?string $clientRouteUuid,
        string $title,
        ?string $description,
        string $visibility,
        string $source,
        RouteStats $initialStats,
    ): int {
        self::assertEnum($visibility, ['private','unlisted','public'], 'visibility');
        self::assertEnum($source,     ['app','import','strava','manual'], 'source');

        $now = Clock::nowUtcString();
        $sql = 'INSERT INTO routes (
                    public_id, user_id, client_route_uuid,
                    title, description, visibility, source,
                    head_version_id,
                    distance_m, elevation_gain_m, point_count,
                    bbox_min_lat, bbox_min_lon, bbox_max_lat, bbox_max_lon,
                    centroid,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    NULL,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ST_SRID(POINT(?, ?), 4326),
                    ?, ?
                )';
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $publicId, $userId, $clientRouteUuid,
            $title, $description, $visibility, $source,
            $initialStats->distanceM, $initialStats->elevationGainM, $initialStats->pointCount,
            $initialStats->bboxMinLat, $initialStats->bboxMinLon, $initialStats->bboxMaxLat, $initialStats->bboxMaxLon,
            $initialStats->centroidLon, $initialStats->centroidLat,
            $now, $now,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Inserts a single version row. Returns the new route_versions.id.
     */
    public function insertVersion(
        int $routeId,
        int $version,
        string $format,
        string $payloadPath,
        string $sha256,
        int $bytes,
        RouteStats $stats,
    ): int {
        self::assertEnum($format, ['gpx','geojson'], 'format');
        $now = Clock::nowUtcString();
        $sql = 'INSERT INTO route_versions (
                    route_id, version, format,
                    payload_path, payload_sha256, payload_bytes,
                    point_count, distance_m, elevation_gain_m,
                    started_at, ended_at,
                    created_at
                ) VALUES (?, ?, ?,
                          ?, ?, ?,
                          ?, ?, ?,
                          ?, ?,
                          ?)';
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $routeId, $version, $format,
            $payloadPath, $sha256, $bytes,
            $stats->pointCount, $stats->distanceM, $stats->elevationGainM,
            $stats->startedAt?->format('Y-m-d H:i:s'),
            $stats->endedAt?->format('Y-m-d H:i:s'),
            $now,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Setzt `head_version_id` und denormalisiert die Stats vom Head
     * auf die `routes`-Zeile.
     */
    public function updateRouteHead(int $routeId, int $headVersionId, RouteStats $stats): void
    {
        $now = Clock::nowUtcString();
        $sql = 'UPDATE routes
                   SET head_version_id  = ?,
                       distance_m       = ?,
                       elevation_gain_m = ?,
                       point_count      = ?,
                       bbox_min_lat     = ?,
                       bbox_min_lon     = ?,
                       bbox_max_lat     = ?,
                       bbox_max_lon     = ?,
                       centroid         = ST_SRID(POINT(?, ?), 4326),
                       updated_at       = ?
                 WHERE id = ?';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([
            $headVersionId,
            $stats->distanceM, $stats->elevationGainM, $stats->pointCount,
            $stats->bboxMinLat, $stats->bboxMinLon, $stats->bboxMaxLat, $stats->bboxMaxLon,
            $stats->centroidLon, $stats->centroidLat,
            $now,
            $routeId,
        ]);
    }

    /**
     * Aktualisiert nur die Metadaten (Title, Description, Visibility).
     * Geometrie und Stats werden nicht angefasst — die sind je Version
     * immutable.
     */
    public function updateRouteMeta(int $routeId, string $title, ?string $description, string $visibility): void
    {
        self::assertEnum($visibility, ['private','unlisted','public'], 'visibility');
        $now = Clock::nowUtcString();
        $stmt = Db::pdo()->prepare(
            'UPDATE routes
                SET title = ?, description = ?, visibility = ?, updated_at = ?
              WHERE id = ?'
        );
        $stmt->execute([$title, $description, $visibility, $now, $routeId]);
    }

    public function softDelete(int $routeId): void
    {
        $now  = Clock::nowUtcString();
        $stmt = Db::pdo()->prepare(
            'UPDATE routes SET deleted_at = ?, updated_at = ?
              WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$now, $now, $routeId]);
    }

    /**
     * Findet eine Route über die public UUID, optional eingeschränkt
     * auf einen Owner. Liefert null oder die public Form (s. unten).
     *
     * @return array<string,mixed>|null
     */
    public function findByPublicId(string $publicId, ?int $ownerUserId = null, bool $includeSoftDeleted = false): ?array
    {
        $sql = self::publicSelect() . ' WHERE r.public_id = ?';
        $args = [$publicId];
        if ($ownerUserId !== null) {
            $sql .= ' AND r.user_id = ?';
            $args[] = $ownerUserId;
        }
        if (!$includeSoftDeleted) {
            $sql .= ' AND r.deleted_at IS NULL';
        }
        $sql .= ' LIMIT 1';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($args);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : self::publicShape($row);
    }

    /**
     * Findet die Owner-Route per `client_route_uuid`. Liefert das
     * minimale Tupel `(id, head_version, deleted_at)` zurück, was
     * der Service braucht, um zu entscheiden, ob er eine neue Version
     * anhängt oder einen neuen Datensatz erzeugt.
     *
     * @return array{id:int, public_id:string, head_version_id:int|null, deleted_at:?string}|null
     */
    public function findByClientUuid(int $userId, string $clientRouteUuid): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, public_id, head_version_id, deleted_at
               FROM routes
              WHERE user_id = ? AND client_route_uuid = ?
              LIMIT 1'
        );
        $stmt->execute([$userId, $clientRouteUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : [
            'id'              => (int)$row['id'],
            'public_id'       => (string)$row['public_id'],
            'head_version_id' => $row['head_version_id'] === null ? null : (int)$row['head_version_id'],
            'deleted_at'      => $row['deleted_at'] === null ? null : (string)$row['deleted_at'],
        ];
    }

    /**
     * Listet Routen eines Users in absteigender Reihenfolge der
     * Aktualisierung. Soft-deleted-Einträge werden ausgeblendet.
     *
     * @return list<array<string,mixed>>
     */
    public function listForUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $sql = self::publicSelect() . '
              WHERE r.user_id = ? AND r.deleted_at IS NULL
              ORDER BY r.updated_at DESC
              LIMIT ? OFFSET ?';
        $pdo = Db::pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map([self::class, 'publicShape'], $rows);
    }

    /**
     * M3 Phase 2: Discovery-Suche über alle public Routen, mit
     * optionalen BBox-/Tag-/Distance-/Volltext-Filtern.
     *
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
     * @param list<int> $excludeUserIds  blockierte User aus Sicht des Viewers (beide Richtungen).
     * @return array{routes: list<array<string,mixed>>, total: int}
     */
    public function searchPublic(array $filters, array $excludeUserIds): array
    {
        $limit  = max(1, min(50, (int)($filters['limit']  ?? 20)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $where  = ["r.visibility = 'public'", 'r.deleted_at IS NULL', 'u.public_handle IS NOT NULL', "u.status = 'active'"];
        $params = [];

        if (!empty($filters['bbox'])) {
            $bb = $filters['bbox'];
            // BBox-Filter über die Centroid-POINT-Spalte. Wir nutzen
            // hier KEINE ST_Contains-Polygonprüfung, sondern simple
            // Lat/Lon-Bandbreite — das ist exakt äquivalent für
            // axis-aligned BBoxes auf SRID 4326 und kommt mit dem
            // Spatial-Index aus M2 prima zurecht (er greift weniger
            // gut, wenn man einen BBox-Polygon konstruiert).
            $where[] = 'r.bbox_min_lat <= ? AND r.bbox_max_lat >= ?
                        AND r.bbox_min_lon <= ? AND r.bbox_max_lon >= ?';
            // Schnitt-Test: route-bbox überschneidet sich mit query-bbox,
            // wenn route.minLat <= query.maxLat && route.maxLat >= query.minLat
            // (und analog Lon).
            $params[] = (float)$bb['max_lat'];
            $params[] = (float)$bb['min_lat'];
            $params[] = (float)$bb['max_lon'];
            $params[] = (float)$bb['min_lon'];
        }

        if (!empty($filters['owner_user_id'])) {
            $where[]  = 'r.user_id = ?';
            $params[] = (int)$filters['owner_user_id'];
        }
        if (!empty($filters['min_distance_m'])) {
            $where[]  = 'r.distance_m >= ?';
            $params[] = (int)$filters['min_distance_m'];
        }
        if (!empty($filters['max_distance_m'])) {
            $where[]  = 'r.distance_m <= ?';
            $params[] = (int)$filters['max_distance_m'];
        }
        if (!empty($filters['q'])) {
            $where[]  = 'LOWER(r.title) LIKE ?';
            $params[] = '%' . strtolower((string)$filters['q']) . '%';
        }
        if (!empty($filters['tags'])) {
            // Alle gewünschten Tags müssen vorhanden sein → so viele
            // EXISTS-Subqueries wie Tags. Bei n=1..3 Tags ist das
            // performant; mehr ist semantisch ohnehin selten.
            foreach ($filters['tags'] as $tag) {
                $where[] = 'EXISTS (SELECT 1 FROM route_tags rt WHERE rt.route_id = r.id AND rt.tag = ?)';
                $params[] = (string)$tag;
            }
        }
        if ($excludeUserIds !== []) {
            $placeholders = implode(',', array_fill(0, count($excludeUserIds), '?'));
            $where[] = "r.user_id NOT IN ({$placeholders})";
            foreach ($excludeUserIds as $uid) {
                $params[] = (int)$uid;
            }
        }

        $orderBy = match ((string)($filters['sort'] ?? 'newest')) {
            'oldest'         => 'r.created_at ASC',
            'distance_asc'   => 'r.distance_m ASC, r.created_at DESC',
            'distance_desc'  => 'r.distance_m DESC, r.created_at DESC',
            default          => 'r.created_at DESC', // newest
        };

        $whereSql = implode("\n           AND ", $where);

        // Total-Count separat, ohne LIMIT/OFFSET. Bei <100k public
        // Routen ist das ein akzeptabler Roundtrip; wenn der Index
        // greift (idx_routes_public_discovery + Tag-EXISTS), liegt
        // die Latenz im einstelligen Millisekundenbereich.
        $countSql = "SELECT COUNT(*)
                       FROM routes r
                       JOIN users u ON u.id = r.user_id
                      WHERE {$whereSql}";
        $countStmt = Db::pdo()->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = self::publicSelect() . "
                       JOIN users u ON u.id = r.user_id
                      WHERE {$whereSql}
                      ORDER BY {$orderBy}
                      LIMIT ? OFFSET ?";

        // Kleiner Twist: publicSelect() macht einen LEFT JOIN
        // route_versions; wir hängen unseren INNER JOIN users hinten
        // an, was syntaktisch gültig ist (kommt nach dem LEFT JOIN
        // im FROM-Block).

        $stmt = Db::pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($i,   $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Owner-Daten in einer zweiten Query auflösen — pro Route
        // das gleiche User-Set wäre möglich, aber bei n=20 Items ist
        // das eine 20er-IN-Query, die immer noch schnell ist.
        // array_values: array_unique behält die Original-Keys, wodurch bei
        // doppelten Owner-IDs (zwei Routen desselben Users im Result) eine
        // Lücke in den Keys entsteht. PDO::execute() mit positionalen `?`
        // erwartet aber eine 0-basierte, lückenlose Liste — sonst wirft es
        // SQLSTATE[HY093] Invalid parameter number.
        $userIds = array_values(array_unique(array_map(fn($r) => (int)$r['user_id'], $rows)));
        $owners = [];
        if ($userIds !== []) {
            $ph = implode(',', array_fill(0, count($userIds), '?'));
            $oStmt = Db::pdo()->prepare("SELECT id, public_handle, display_name FROM users WHERE id IN ({$ph})");
            $oStmt->execute($userIds);
            foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $owners[(int)$o['id']] = [
                    'handle'       => (string)$o['public_handle'],
                    'display_name' => $o['display_name'] === null ? null : (string)$o['display_name'],
                ];
            }
        }

        $shaped = [];
        foreach ($rows as $row) {
            $shape = self::publicShape($row);
            $shape['owner'] = $owners[(int)$row['user_id']] ?? null;
            $shaped[] = $shape;
        }
        return ['routes' => $shaped, 'total' => $total];
    }

    /**
     * M3 Phase 5: Activity-Feed-Query — public Routen aller User,
     * denen `$followerUserId` folgt. Block-Filter werden vom
     * FeedService durch `$excludeUserIds` reingereicht.
     *
     * @param list<int> $excludeUserIds  blockierte User aus Sicht des Viewers
     * @return array{routes: list<array<string,mixed>>, total: int}
     */
    public function feedFor(int $followerUserId, array $excludeUserIds, int $limit, int $offset): array
    {
        $limit  = max(1, min(50, $limit));
        $offset = max(0, $offset);

        $where  = [
            'f.follower_id = ?',
            "r.visibility = 'public'",
            'r.deleted_at IS NULL',
        ];
        $params = [$followerUserId];
        if ($excludeUserIds !== []) {
            $ph = implode(',', array_fill(0, count($excludeUserIds), '?'));
            $where[] = "r.user_id NOT IN ({$ph})";
            foreach ($excludeUserIds as $uid) {
                $params[] = (int)$uid;
            }
        }
        $whereSql = implode("\n           AND ", $where);

        $countSql = "SELECT COUNT(*)
                       FROM routes r
                       JOIN follows f ON f.followee_id = r.user_id
                      WHERE {$whereSql}";
        $cnt = Db::pdo()->prepare($countSql);
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $sql = self::publicSelect() . "
                       JOIN follows f ON f.followee_id = r.user_id
                      WHERE {$whereSql}
                      ORDER BY r.created_at DESC
                      LIMIT ? OFFSET ?";

        $stmt = Db::pdo()->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($i,   $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Owner-Daten in einer zweiten Query (gleiches Pattern wie
        // searchPublic — DRY-Refactor wäre möglich, aber bei zwei
        // Aufrufstellen lohnt sich der Aufwand nicht).
        // array_values: siehe searchPublic — array_unique behält Keys, was
        // bei doppelten Owner-IDs eine Key-Lücke erzeugt und PDO::execute()
        // mit positionalen `?` als SQLSTATE[HY093] quittiert.
        $userIds = array_values(array_unique(array_map(fn($r) => (int)$r['user_id'], $rows)));
        $owners = [];
        if ($userIds !== []) {
            $ph = implode(',', array_fill(0, count($userIds), '?'));
            $oStmt = Db::pdo()->prepare("SELECT id, public_handle, display_name FROM users WHERE id IN ({$ph})");
            $oStmt->execute($userIds);
            foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
                $owners[(int)$o['id']] = [
                    'handle'       => $o['public_handle'] === null ? null : (string)$o['public_handle'],
                    'display_name' => $o['display_name']  === null ? null : (string)$o['display_name'],
                ];
            }
        }

        $shaped = [];
        foreach ($rows as $row) {
            $shape = self::publicShape($row);
            $shape['owner'] = $owners[(int)$row['user_id']] ?? null;
            $shaped[] = $shape;
        }
        return ['routes' => $shaped, 'total' => $total];
    }

    /**
     * Findet hart-zu-löschende Routen: alle, die seit mindestens
     * `$graceDays` Tagen soft-deleted sind. Liefert das Tupel,
     * das die Storage-Schicht für FS-Cleanup braucht.
     *
     * @return list<array{id:int, user_id:int, public_id:string, deleted_at:string}>
     */
    public function findHardDeleteCandidates(int $graceDays): array
    {
        $graceDays = max(0, $graceDays);
        $sql = 'SELECT id, user_id, public_id, deleted_at
                  FROM routes
                 WHERE deleted_at IS NOT NULL
                   AND deleted_at <= (UTC_TIMESTAMP() - INTERVAL ? DAY)
                 ORDER BY id ASC';
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([$graceDays]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'         => (int)$r['id'],
                'user_id'    => (int)$r['user_id'],
                'public_id'  => (string)$r['public_id'],
                'deleted_at' => (string)$r['deleted_at'],
            ];
        }
        return $out;
    }

    /**
     * Hart-Löschen einer Route. FK-CASCADE räumt route_versions,
     * route_tags und route_shares automatisch mit weg.
     */
    public function hardDelete(int $routeId): void
    {
        Db::pdo()->prepare('DELETE FROM routes WHERE id = ?')
            ->execute([$routeId]);
    }

    /**
     * Liefert die nächste Versionsnummer für eine Route. Erwartet,
     * dass die Route existiert.
     */
    public function nextVersion(int $routeId): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT COALESCE(MAX(version), 0) + 1
               FROM route_versions WHERE route_id = ?'
        );
        $stmt->execute([$routeId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Liefert die Versions-Zeile (für Download). Optional auf eine
     * spezifische Version eingeschränkt — sonst die aktuelle Head.
     *
     * @return array{id:int,route_id:int,version:int,format:string,payload_path:string,payload_sha256:string,payload_bytes:int}|null
     */
    public function findVersion(int $routeId, ?int $version = null): ?array
    {
        $pdo = Db::pdo();
        if ($version === null) {
            $stmt = $pdo->prepare(
                'SELECT v.id, v.route_id, v.version, v.format,
                        v.payload_path, v.payload_sha256, v.payload_bytes
                   FROM route_versions v
                   JOIN routes r ON r.head_version_id = v.id
                  WHERE r.id = ? LIMIT 1'
            );
            $stmt->execute([$routeId]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id, route_id, version, format,
                        payload_path, payload_sha256, payload_bytes
                   FROM route_versions
                  WHERE route_id = ? AND version = ? LIMIT 1'
            );
            $stmt->execute([$routeId, $version]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id'             => (int)$row['id'],
            'route_id'       => (int)$row['route_id'],
            'version'        => (int)$row['version'],
            'format'         => (string)$row['format'],
            'payload_path'   => (string)$row['payload_path'],
            'payload_sha256' => (string)$row['payload_sha256'],
            'payload_bytes'  => (int)$row['payload_bytes'],
        ];
    }

    /**
     * Ersetzt die Tag-Liste einer Route atomar (DELETE + INSERT in
     * einer Transaktion vom Aufrufer gewrapt — Service-Schicht ist
     * dafür zuständig). Tags werden lowercased + getrimmt; leere
     * Strings werden ignoriert; Duplikate werden entfernt.
     *
     * @param list<string> $tags
     */
    public function replaceTags(int $routeId, array $tags): void
    {
        $normalized = [];
        foreach ($tags as $t) {
            if (!is_string($t)) { continue; }
            $clean = strtolower(trim($t));
            if ($clean === '' || mb_strlen($clean) > 40) { continue; }
            $normalized[$clean] = true;
        }

        $pdo = Db::pdo();
        $pdo->prepare('DELETE FROM route_tags WHERE route_id = ?')->execute([$routeId]);
        if ($normalized === []) {
            return;
        }
        $now  = Clock::nowUtcString();
        $stmt = $pdo->prepare('INSERT INTO route_tags (route_id, tag, created_at) VALUES (?, ?, ?)');
        foreach (array_keys($normalized) as $tag) {
            $stmt->execute([$routeId, $tag, $now]);
        }
    }

    /**
     * Setzt das Radar-Ride-Aggregat (Vorbeifahrten/km) einer Route. `null`
     * löscht den Wert (Fahrt ohne Radar / Fremd-GPX).
     */
    public function updateTrafficPassesPerKm(int $routeId, ?float $passesPerKm): void
    {
        $stmt = Db::pdo()->prepare(
            'UPDATE routes SET traffic_passes_per_km = ? WHERE id = ?'
        );
        $stmt->bindValue(1, $passesPerKm, $passesPerKm === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(2, $routeId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @return list<string>
     */
    public function listTags(int $routeId): array
    {
        $stmt = Db::pdo()->prepare('SELECT tag FROM route_tags WHERE route_id = ? ORDER BY tag ASC');
        $stmt->execute([$routeId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Reines SELECT-Skelett für die Public Form. Liefert Stats vom
     * denormalisierten Head + Centroid via SRS-bewussten ST_*-Funktionen.
     */
    private static function publicSelect(): string
    {
        return 'SELECT
                    r.id,
                    r.public_id,
                    r.user_id,
                    r.client_route_uuid,
                    r.title,
                    r.description,
                    r.visibility,
                    r.source,
                    r.head_version_id,
                    r.distance_m,
                    r.elevation_gain_m,
                    r.traffic_passes_per_km,
                    r.point_count,
                    r.bbox_min_lat,
                    r.bbox_min_lon,
                    r.bbox_max_lat,
                    r.bbox_max_lon,
                    ST_Latitude(r.centroid)  AS centroid_lat,
                    ST_Longitude(r.centroid) AS centroid_lon,
                    r.created_at,
                    r.updated_at,
                    r.deleted_at,
                    v.version    AS head_version,
                    v.format     AS head_format,
                    v.started_at AS head_started_at,
                    v.ended_at   AS head_ended_at
                  FROM routes r
                  LEFT JOIN route_versions v ON v.id = r.head_version_id';
    }

    /**
     * Wandelt eine DB-Zeile in die in der API+Web verwendete Public Form um.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function publicShape(array $row): array
    {
        return [
            'id'                => (string)$row['public_id'],
            'client_route_uuid' => $row['client_route_uuid'] === null ? null : (string)$row['client_route_uuid'],
            'title'             => (string)$row['title'],
            'description'       => $row['description'] === null ? null : (string)$row['description'],
            'visibility'        => (string)$row['visibility'],
            'source'            => (string)$row['source'],
            'version'           => $row['head_version'] === null ? null : (int)$row['head_version'],
            'format'            => $row['head_format']  === null ? null : (string)$row['head_format'],
            'stats'             => [
                'distance_m'        => $row['distance_m']       === null ? null : (int)$row['distance_m'],
                'elevation_gain_m'  => $row['elevation_gain_m'] === null ? null : (int)$row['elevation_gain_m'],
                'traffic_passes_per_km' => !array_key_exists('traffic_passes_per_km', $row) || $row['traffic_passes_per_km'] === null
                    ? null : round((float)$row['traffic_passes_per_km'], 2),
                'point_count'       => $row['point_count']      === null ? null : (int)$row['point_count'],
                'started_at'        => $row['head_started_at']  === null ? null : self::isoUtc((string)$row['head_started_at']),
                'ended_at'          => $row['head_ended_at']    === null ? null : self::isoUtc((string)$row['head_ended_at']),
                'bbox'              => $row['bbox_min_lat'] === null ? null : [
                    'min_lat' => (float)$row['bbox_min_lat'],
                    'min_lon' => (float)$row['bbox_min_lon'],
                    'max_lat' => (float)$row['bbox_max_lat'],
                    'max_lon' => (float)$row['bbox_max_lon'],
                ],
                'centroid'          => $row['centroid_lat'] === null ? null : [
                    'lat' => (float)$row['centroid_lat'],
                    'lon' => (float)$row['centroid_lon'],
                ],
            ],
            'created_at'        => self::isoUtc((string)$row['created_at']),
            'updated_at'        => self::isoUtc((string)$row['updated_at']),
            'deleted_at'        => $row['deleted_at'] === null ? null : self::isoUtc((string)$row['deleted_at']),
            // Internal fields — Controller übergibt die nicht weiter
            // an den Client, kann sie aber für Folge-Ops nutzen.
            '_internal' => [
                'route_id'        => (int)$row['id'],
                'user_id'         => (int)$row['user_id'],
                'head_version_id' => $row['head_version_id'] === null ? null : (int)$row['head_version_id'],
            ],
        ];
    }

    /** Wandelt 'YYYY-MM-DD HH:MM:SS' in ISO-8601 mit UTC-Suffix. */
    private static function isoUtc(string $datetime): string
    {
        return str_replace(' ', 'T', $datetime) . 'Z';
    }

    private static function assertEnum(string $value, array $allowed, string $name): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new RuntimeException("Invalid {$name}: {$value}");
        }
    }
}
