<?php
declare(strict_types=1);

namespace App\Game;

use PDO;

/**
 * Alle PDO-Operationen des Spiels. Keine Geschaeftslogik (die lebt in
 * GameMath / EdgeRecalculator / GameIngestionService) — nur Lesen/Schreiben.
 */
final class GameRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function riderClaimantId(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM game_claimant WHERE type = "rider" AND user_id = ?'
        );
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        $this->pdo->prepare(
            'INSERT IGNORE INTO game_claimant (type, user_id) VALUES ("rider", ?)'
        )->execute([$userId]);
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /** Wie riderClaimantId, aber legt KEINEN Claimant an (für Lese-Pfade). */
    public function findRiderClaimantId(int $userId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM game_claimant WHERE type = "rider" AND user_id = ?'
        );
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function upsertNode(int $osmNodeId, float $lat, float $lon): int
    {
        $this->pdo->prepare(
            'INSERT INTO game_node (osm_node_id, lat, lon) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE lat = VALUES(lat), lon = VALUES(lon)'
        )->execute([$osmNodeId, $lat, $lon]);
        $stmt = $this->pdo->prepare('SELECT id FROM game_node WHERE osm_node_id = ?');
        $stmt->execute([$osmNodeId]);
        return (int)$stmt->fetchColumn();
    }

    public function upsertEdge(
        int $wayId,
        int $nodeAId,
        int $nodeBId,
        float $lengthM,
        string $geomJson,
        ?string $surface,
        float $minLat,
        float $minLon,
        float $maxLat,
        float $maxLon,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO game_edge
                (way_id, node_a_id, node_b_id, length_m, geom_geojson, surface_character,
                 min_lat, min_lon, max_lat, max_lon)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                length_m = VALUES(length_m),
                geom_geojson = VALUES(geom_geojson),
                surface_character = COALESCE(VALUES(surface_character), surface_character)'
        )->execute([$wayId, $nodeAId, $nodeBId, $lengthM, $geomJson, $surface,
                    $minLat, $minLon, $maxLat, $maxLon]);
        $stmt = $this->pdo->prepare(
            'SELECT id FROM game_edge WHERE way_id = ? AND node_a_id = ? AND node_b_id = ?'
        );
        $stmt->execute([$wayId, $nodeAId, $nodeBId]);
        return (int)$stmt->fetchColumn();
    }

    /** @return bool true wenn ein NEUER Pass angelegt wurde (sonst Tages-Deckel). */
    public function insertPassIfAbsent(
        int $edgeId,
        int $claimantId,
        int $userId,
        int $routeId,
        string $riddenOn,
        string $riddenAt,
    ): bool {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_edge_pass
                (edge_id, claimant_id, user_id, route_id, ridden_on, ridden_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE ridden_at = GREATEST(ridden_at, VALUES(ridden_at))'
        );
        $stmt->execute([$edgeId, $claimantId, $userId, $routeId, $riddenOn, $riddenAt]);
        return $stmt->rowCount() === 1;
    }

    public function distinctRidersTotal(int $edgeId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM game_edge_pass WHERE edge_id = ?'
        );
        $stmt->execute([$edgeId]);
        return (int)$stmt->fetchColumn();
    }

    /** n90: verschiedene user_id mit Pass seit $sinceDate (Y-m-d). */
    public function distinctRidersSince(int $edgeId, string $sinceDate): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM game_edge_pass
              WHERE edge_id = ? AND ridden_on >= ?'
        );
        $stmt->execute([$edgeId, $sinceDate]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return list<array{claimant_id:int,user_id:int,ridden_at:string}>
     */
    public function passesForEdge(int $edgeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT claimant_id, user_id, ridden_at FROM game_edge_pass WHERE edge_id = ?'
        );
        $stmt->execute([$edgeId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'claimant_id' => (int)$r['claimant_id'],
                'user_id'     => (int)$r['user_id'],
                'ridden_at'   => (string)$r['ridden_at'],
            ];
        }
        return $out;
    }

    /**
     * @return list<array{user_id:int,claimant_id:int,first_ridden_at:string,handle:?string}>
     */
    public function firstPassPerUser(int $edgeId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.user_id, MIN(p.ridden_at) AS first_ridden_at,
                    MIN(p.claimant_id) AS claimant_id, u.public_handle AS handle
               FROM game_edge_pass p
               JOIN users u ON u.id = p.user_id
              WHERE p.edge_id = ?
              GROUP BY p.user_id, u.public_handle
              ORDER BY first_ridden_at ASC, p.user_id ASC
              LIMIT ?'
        );
        $stmt->bindValue(1, $edgeId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'user_id'         => (int)$r['user_id'],
                'claimant_id'     => (int)$r['claimant_id'],
                'first_ridden_at' => (string)$r['first_ridden_at'],
                'handle'          => $r['handle'] !== null ? (string)$r['handle'] : null,
            ];
        }
        return $out;
    }

    /** Setzt discovered_at/discoverer + distinct_riders_total aus den Pässen. */
    public function refreshEdgeDiscovery(int $edgeId): void
    {
        $this->pdo->prepare(
            'UPDATE game_edge e SET
                e.distinct_riders_total = (
                    SELECT COUNT(DISTINCT user_id) FROM game_edge_pass WHERE edge_id = e.id
                ),
                e.discovered_at = (
                    SELECT MIN(ridden_at) FROM game_edge_pass WHERE edge_id = e.id
                ),
                e.discoverer_claimant_id = (
                    SELECT claimant_id FROM game_edge_pass
                     WHERE edge_id = e.id
                     ORDER BY ridden_at ASC, id ASC LIMIT 1
                )
             WHERE e.id = ?'
        )->execute([$edgeId]);
    }

    /** Setzt alle materialisierten Live-Werte zurück (für vollen Recompute). */
    public function resetAllEdgeCaches(): void
    {
        $this->pdo->exec(
            'UPDATE game_edge SET
                owner_claimant_id = NULL, owner_since = NULL,
                value_cached = 0, freshness_cached = 0, last_pass_at = NULL'
        );
    }

    public function updateEdgeCached(
        int $edgeId,
        ?int $ownerClaimantId,
        ?string $ownerSince,
        float $value,
        float $freshness,
        ?string $lastPassAt,
    ): void {
        $this->pdo->prepare(
            'UPDATE game_edge SET
                owner_claimant_id = ?,
                owner_since = COALESCE(?, owner_since),
                value_cached = ?,
                freshness_cached = ?,
                last_pass_at = ?
             WHERE id = ?'
        )->execute([$ownerClaimantId, $ownerSince, $value, $freshness, $lastPassAt, $edgeId]);
    }

    /** @return array{user_id:int,public_id:string}|null */
    public function routeForIngest(int $routeId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT user_id, public_id FROM routes WHERE id = ?');
        $stmt->execute([$routeId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return ['user_id' => (int)$r['user_id'], 'public_id' => (string)$r['public_id']];
    }

    /** @return array<string,mixed>|null Roh-Zeile der Kante. */
    public function edgeById(int $edgeId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_edge WHERE id = ?');
        $stmt->execute([$edgeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<int> alle Kanten-IDs (für vollen Recompute). */
    public function allEdgeIds(): array
    {
        return array_map('intval', $this->pdo->query('SELECT id FROM game_edge ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function edgesInBbox(
        float $minLon,
        float $minLat,
        float $maxLon,
        float $maxLat,
        ?int $mineClaimantId,
        int $limit,
    ): array {
        $sql = 'SELECT * FROM game_edge
                 WHERE max_lat >= ? AND min_lat <= ? AND max_lon >= ? AND min_lon <= ?';
        $params = [$minLat, $maxLat, $minLon, $maxLon];
        if ($mineClaimantId !== null) {
            $sql .= ' AND owner_claimant_id = ?';
            $params[] = $mineClaimantId;
        }
        $sql .= ' ORDER BY id LIMIT ?';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $i => $v) {
            $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{claimant_id:int,type:string,handle:?string}|null */
    public function claimantInfo(int $claimantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.type, u.public_handle AS handle
               FROM game_claimant c
               LEFT JOIN users u ON u.id = c.user_id
              WHERE c.id = ?'
        );
        $stmt->execute([$claimantId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return [
            'claimant_id' => (int)$r['id'],
            'type'        => (string)$r['type'],
            'handle'      => $r['handle'] !== null ? (string)$r['handle'] : null,
        ];
    }

    /** @return array{held:int,pioneered:int,held_length_m:float} */
    public function meStats(int $claimantId): array
    {
        $held = $this->pdo->prepare(
            'SELECT COUNT(*) AS held, COALESCE(SUM(length_m),0) AS len
               FROM game_edge WHERE owner_claimant_id = ?'
        );
        $held->execute([$claimantId]);
        $h = $held->fetch(PDO::FETCH_ASSOC) ?: ['held' => 0, 'len' => 0];

        $pio = $this->pdo->prepare(
            'SELECT COUNT(*) FROM game_edge WHERE discoverer_claimant_id = ?'
        );
        $pio->execute([$claimantId]);

        return [
            'held'          => (int)$h['held'],
            'pioneered'     => (int)$pio->fetchColumn(),
            'held_length_m' => (float)$h['len'],
        ];
    }
}
