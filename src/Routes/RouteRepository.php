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
