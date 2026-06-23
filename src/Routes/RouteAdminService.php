<?php
declare(strict_types=1);

namespace App\Routes;

use PDO;

/**
 * Lese-Aggregate für die Admin-Upload-Übersicht: alle Routen über alle User mit
 * Owner-Bezug, den Metadaten der lokal gespeicherten Datei (Head-Version) und
 * dem Spiel-Status. Bewusst getrennt vom owner-skopierten {@see RouteService}:
 * der Admin sieht alles (auch fremde/soft-deleted Routen).
 */
final class RouteAdminService
{
    public function __construct(private readonly PDO $pdo) {}

    public const SOURCES = ['app', 'import', 'strava', 'manual'];

    /**
     * @param array{source?:?string,q?:?string,include_deleted?:bool,limit?:int,offset?:int} $filters
     * @return array{rows:list<array<string,mixed>>, total:int, limit:int, offset:int}
     */
    public function listUploads(array $filters = []): array
    {
        $limit  = max(1, min(200, (int)($filters['limit'] ?? 50)));
        $offset = max(0, (int)($filters['offset'] ?? 0));

        $where  = [];
        $params = [];

        if (empty($filters['include_deleted'])) {
            $where[] = 'r.deleted_at IS NULL';
        }
        $source = $filters['source'] ?? null;
        if (is_string($source) && in_array($source, self::SOURCES, true)) {
            $where[] = 'r.source = ?';
            $params[] = $source;
        }
        $q = isset($filters['q']) ? trim((string)$filters['q']) : '';
        if ($q !== '') {
            $where[] = '(LOWER(r.title) LIKE ? OR LOWER(u.public_handle) LIKE ? OR LOWER(u.email) LIKE ? OR LOWER(u.display_name) LIKE ?)';
            $like = '%' . strtolower($q) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = $where === [] ? '1=1' : implode(' AND ', $where);

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM routes r JOIN users u ON u.id = r.user_id WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT
                    r.id, r.public_id, r.title, r.source, r.visibility,
                    r.distance_m, r.created_at, r.updated_at, r.deleted_at,
                    u.id AS user_id, u.public_handle, u.email, u.display_name, u.status,
                    v.version, v.format, v.payload_path, v.payload_bytes, v.payload_sha256,
                    (SELECT DATE_FORMAT(MIN(gp.created_at), '%Y-%m-%d %H:%i:%s')
                       FROM game_edge_pass gp
                      WHERE gp.route_id = r.id AND gp.user_id = r.user_id
                        AND gp.invalidated_at IS NULL) AS game_ingested_at,
                    (SELECT COUNT(DISTINCT gp.edge_id)
                       FROM game_edge_pass gp
                      WHERE gp.route_id = r.id AND gp.user_id = r.user_id
                        AND gp.invalidated_at IS NULL) AS game_edges_count
                  FROM routes r
                  JOIN users u ON u.id = r.user_id
                  LEFT JOIN route_versions v ON v.id = r.head_version_id
                 WHERE {$whereSql}
                 ORDER BY r.created_at DESC
                 LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $stmt->bindValue($i++, $p);
        }
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [
                'route_id'         => (int)$r['id'],
                'public_id'        => (string)$r['public_id'],
                'title'            => (string)$r['title'],
                'source'           => (string)$r['source'],
                'visibility'       => (string)$r['visibility'],
                'distance_m'       => $r['distance_m'] === null ? null : (int)$r['distance_m'],
                'created_at'       => (string)$r['created_at'],
                'deleted_at'       => $r['deleted_at'] === null ? null : (string)$r['deleted_at'],
                'user_id'          => (int)$r['user_id'],
                'handle'           => $r['public_handle'] === null ? null : (string)$r['public_handle'],
                'email'            => (string)$r['email'],
                'display_name'     => $r['display_name'] === null ? null : (string)$r['display_name'],
                'user_status'      => (string)$r['status'],
                'version'          => $r['version'] === null ? null : (int)$r['version'],
                'format'           => $r['format'] === null ? null : (string)$r['format'],
                'payload_path'     => $r['payload_path'] === null ? null : (string)$r['payload_path'],
                'payload_bytes'    => $r['payload_bytes'] === null ? null : (int)$r['payload_bytes'],
                'payload_sha256'   => $r['payload_sha256'] === null ? null : (string)$r['payload_sha256'],
                'game_ingested_at' => $r['game_ingested_at'] === null ? null : (string)$r['game_ingested_at'],
                'game_edges_count' => (int)($r['game_edges_count'] ?? 0),
            ];
        }

        return ['rows' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array{total:int,by_source:array<string,int>,deleted:int} */
    public function summary(): array
    {
        $bySource = [];
        foreach (self::SOURCES as $s) {
            $bySource[$s] = 0;
        }
        $rows = $this->pdo->query(
            "SELECT source, COUNT(*) AS c FROM routes WHERE deleted_at IS NULL GROUP BY source"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rows as $src => $c) {
            $bySource[(string)$src] = (int)$c;
        }
        $total   = (int)$this->pdo->query('SELECT COUNT(*) FROM routes WHERE deleted_at IS NULL')->fetchColumn();
        $deleted = (int)$this->pdo->query('SELECT COUNT(*) FROM routes WHERE deleted_at IS NOT NULL')->fetchColumn();
        return ['total' => $total, 'by_source' => $bySource, 'deleted' => $deleted];
    }
}
