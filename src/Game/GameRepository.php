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

    /**
     * Effektiver Claimant je user_id (Stufe 2): Crew-Group-Claimant falls Mitglied,
     * sonst der Rider-Claimant. Legt KEINE Claimants an (Lesepfad-sicher).
     *
     * @param list<int> $userIds
     * @return array<int,array{claimant_id:int,is_group:bool}>
     */
    public function effectiveClaimantMap(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT rc.user_id AS user_id,
                    rc.id      AS rider_claimant_id,
                    gc.claimant_id AS group_claimant_id
               FROM game_claimant rc
               LEFT JOIN game_crew_member m ON m.user_id = rc.user_id
               LEFT JOIN game_crew gc       ON gc.id = m.crew_id
              WHERE rc.type = 'rider' AND rc.user_id IN ($ph)"
        );
        $stmt->execute($userIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $uid = (int)$r['user_id'];
            if ($r['group_claimant_id'] !== null) {
                $out[$uid] = ['claimant_id' => (int)$r['group_claimant_id'], 'is_group' => true];
            } else {
                $out[$uid] = ['claimant_id' => (int)$r['rider_claimant_id'], 'is_group' => false];
            }
        }
        return $out;
    }

    /** Effektiver Claimant eines Users (legt Rider bei Bedarf an — für Schreib-/Endpunkt-Pfad). */
    public function effectiveClaimantId(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT gc.claimant_id
               FROM game_crew_member m
               JOIN game_crew gc ON gc.id = m.crew_id
              WHERE m.user_id = ?'
        );
        $stmt->execute([$userId]);
        $gid = $stmt->fetchColumn();
        if ($gid !== false) {
            return (int)$gid;
        }
        return $this->riderClaimantId($userId);
    }

    /**
     * @return list<int> Kanten-IDs, auf denen der User im Fenster (seit $sinceDate)
     *                   gültige Pässe hat. Für den synchronen Teil-Recompute bei
     *                   Mitgliedschaftsänderung (Spec §4.4).
     */
    public function affectedEdgeIdsForUser(int $userId, string $sinceDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT edge_id FROM game_edge_pass
              WHERE user_id = ? AND invalidated_at IS NULL AND ridden_on >= ?
              ORDER BY edge_id'
        );
        $stmt->execute([$userId, $sinceDate]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<int> Kanten, die aktuell von $claimantId gehalten werden.
     *                   Safety-Net bei Crew-Auflösung (Owner muss weg vom
     *                   Group-Claimant, bevor er gelöscht wird).
     */
    public function edgeIdsOwnedByClaimant(int $claimantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM game_edge WHERE owner_claimant_id = ? ORDER BY id'
        );
        $stmt->execute([$claimantId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
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
            'SELECT COUNT(DISTINCT user_id) FROM game_edge_pass
              WHERE edge_id = ? AND invalidated_at IS NULL'
        );
        $stmt->execute([$edgeId]);
        return (int)$stmt->fetchColumn();
    }

    /** n90: verschiedene user_id mit Pass seit $sinceDate (Y-m-d). */
    public function distinctRidersSince(int $edgeId, string $sinceDate): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM game_edge_pass
              WHERE edge_id = ? AND ridden_on >= ? AND invalidated_at IS NULL'
        );
        $stmt->execute([$edgeId, $sinceDate]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * @return list<array{claimant_id:int,user_id:int,ridden_on:string,ridden_at:string}>
     */
    public function passesForEdge(int $edgeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT claimant_id, user_id, ridden_on, ridden_at FROM game_edge_pass
              WHERE edge_id = ? AND invalidated_at IS NULL'
        );
        $stmt->execute([$edgeId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'claimant_id' => (int)$r['claimant_id'],
                'user_id'     => (int)$r['user_id'],
                'ridden_on'   => (string)$r['ridden_on'],
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
              WHERE p.edge_id = ? AND p.invalidated_at IS NULL
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
                    SELECT COUNT(DISTINCT user_id) FROM game_edge_pass
                     WHERE edge_id = e.id AND invalidated_at IS NULL
                ),
                e.discovered_at = (
                    SELECT MIN(ridden_at) FROM game_edge_pass
                     WHERE edge_id = e.id AND invalidated_at IS NULL
                ),
                e.discoverer_claimant_id = (
                    SELECT claimant_id FROM game_edge_pass
                     WHERE edge_id = e.id AND invalidated_at IS NULL
                     ORDER BY ridden_at ASC, id ASC LIMIT 1
                )
             WHERE e.id = ?'
        )->execute([$edgeId]);
    }

    /** Inspector: ALLE Pässe inkl. invalidierte (mit Handle + Route + Invalidierungs-Info). */
    public function allPassesForEdge(int $edgeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.user_id, u.public_handle AS handle, p.route_id,
                    p.ridden_on, p.ridden_at, p.invalidated_at, p.invalid_reason
               FROM game_edge_pass p
               JOIN users u ON u.id = p.user_id
              WHERE p.edge_id = ?
              ORDER BY p.ridden_at ASC, p.id ASC'
        );
        $stmt->execute([$edgeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<int> Kanten-IDs im BBox (für Region-Recompute). */
    public function edgeIdsInBbox(float $minLon, float $minLat, float $maxLon, float $maxLat): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM game_edge
              WHERE max_lat >= ? AND min_lat <= ? AND max_lon >= ? AND min_lon <= ?
              ORDER BY id'
        );
        $stmt->execute([$minLat, $maxLat, $minLon, $maxLon]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Spiel-Sperre eines Users (Dashboard). */
    public function isUserBanned(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT banned FROM game_user_flag WHERE user_id = ?');
        $stmt->execute([$userId]);
        $v = $stmt->fetchColumn();
        return $v !== false && (int)$v === 1;
    }

    /** Schreibt eine Ingest-Log-Zeile. */
    public function insertIngestLog(int $routeId, int $userId, string $status, int $matchedEdges, int $newPasses, ?array $skipped, ?string $valhallaError, ?int $durationMs): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_ingest_log (route_id, user_id, status, matched_edges, new_passes, skipped_json, valhalla_error, duration_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $routeId, $userId, $status, $matchedEdges, $newPasses,
            $skipped !== null ? json_encode($skipped, JSON_THROW_ON_ERROR) : null,
            $valhallaError, $durationMs,
        ]);
    }

    /** Setzt alle materialisierten Live-Werte zurück (für vollen Recompute). */
    public function resetAllEdgeCaches(): void
    {
        $this->pdo->exec(
            'UPDATE game_edge SET
                owner_claimant_id = NULL, owner_since = NULL,
                value_cached = 0, freshness_cached = 0, last_pass_at = NULL,
                traffic_factor_cached = 1.0, traffic_pass_count = 0, traffic_observations = 0'
        );
    }

    /** Setzt die materialisierten Live-Werte NUR der angegebenen Kanten zurück. @param list<int> $edgeIds */
    public function resetEdgeCaches(array $edgeIds): void
    {
        if ($edgeIds === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($edgeIds), '?'));
        $this->pdo->prepare(
            "UPDATE game_edge SET
                owner_claimant_id = NULL, owner_since = NULL,
                value_cached = 0, freshness_cached = 0, last_pass_at = NULL,
                traffic_factor_cached = 1.0, traffic_pass_count = 0, traffic_observations = 0
             WHERE id IN ($in)"
        )->execute(array_values($edgeIds));
    }

    public function updateEdgeCached(
        int $edgeId,
        ?int $ownerClaimantId,
        ?string $ownerSince,
        float $value,
        float $freshness,
        ?string $lastPassAt,
        float $trafficFactor = 1.0,
        int $trafficPassCount = 0,
        int $trafficObservations = 0,
    ): void {
        $this->pdo->prepare(
            'UPDATE game_edge SET
                owner_claimant_id = ?,
                owner_since = COALESCE(?, owner_since),
                value_cached = ?,
                freshness_cached = ?,
                last_pass_at = ?,
                traffic_factor_cached = ?,
                traffic_pass_count = ?,
                traffic_observations = ?
             WHERE id = ?'
        )->execute([
            $ownerClaimantId, $ownerSince, $value, $freshness, $lastPassAt,
            $trafficFactor, $trafficPassCount, $trafficObservations, $edgeId,
        ]);
    }

    /**
     * Trägt die map-gematchten Vorbeifahrten einer Fahrt auf einer Kante ein
     * (Quelle der Wahrheit für den Verkehrs-Faktor). Idempotent über den
     * PK (edge_id, route_id) — Re-Ingest derselben Route überschreibt den
     * Zähler statt zu addieren.
     */
    public function upsertEdgeTraffic(int $edgeId, int $routeId, int $passCount): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_edge_traffic (edge_id, route_id, pass_count)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE pass_count = VALUES(pass_count)'
        )->execute([$edgeId, $routeId, $passCount]);
    }

    /**
     * Aggregat über alle Fahrten: Summe Vorbeifahrten + Anzahl Beobachtungen
     * (distinct Fahrten mit Radar) auf der Kante.
     *
     * @return array{pass_count:int, observations:int}
     */
    public function trafficAggregateForEdge(int $edgeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(pass_count), 0) AS pass_count, COUNT(*) AS observations
               FROM game_edge_traffic WHERE edge_id = ?'
        );
        $stmt->execute([$edgeId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['pass_count' => 0, 'observations' => 0];
        return [
            'pass_count'   => (int)$r['pass_count'],
            'observations' => (int)$r['observations'],
        ];
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

    /**
     * Löst eine Eingabe (interne Route-ID als Zahl ODER Public-ID/UUID) zu
     * Route auf. Für die manuelle Admin-Ingestion beliebiger Routen.
     *
     * @return array{route_id:int,user_id:int,public_id:string}|null
     */
    public function resolveRouteForIngest(string $idOrPublicId): ?array
    {
        $idOrPublicId = trim($idOrPublicId);
        if ($idOrPublicId === '') {
            return null;
        }
        if (ctype_digit($idOrPublicId)) {
            $stmt = $this->pdo->prepare('SELECT id, user_id, public_id FROM routes WHERE id = ?');
            $stmt->bindValue(1, (int)$idOrPublicId, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare('SELECT id, user_id, public_id FROM routes WHERE public_id = ?');
            $stmt->bindValue(1, $idOrPublicId, PDO::PARAM_STR);
        }
        $stmt->execute();
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return [
            'route_id'  => (int)$r['id'],
            'user_id'   => (int)$r['user_id'],
            'public_id' => (string)$r['public_id'],
        ];
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

    /**
     * Kanten für die Admin-Übersichtskarte: Geometrie + Anzeige-Props inkl.
     * Owner-Handle. BBox optional (null = alle, bis $limit). Rein lesend.
     *
     * @return list<array<string,mixed>>
     */
    public function edgesGeoForMap(
        ?float $minLon,
        ?float $minLat,
        ?float $maxLon,
        ?float $maxLat,
        int $limit,
    ): array {
        $sql = 'SELECT e.id, e.geom_geojson, e.length_m, e.surface_character,
                       e.owner_claimant_id, u.public_handle AS owner_handle,
                       e.value_cached, e.freshness_cached, e.distinct_riders_total,
                       e.min_lat, e.min_lon, e.max_lat, e.max_lon
                  FROM game_edge e
                  LEFT JOIN game_claimant c ON c.id = e.owner_claimant_id
                  LEFT JOIN users u ON u.id = c.user_id';
        $params = [];
        $hasBbox = $minLon !== null && $minLat !== null && $maxLon !== null && $maxLat !== null;
        if ($hasBbox) {
            $sql .= ' WHERE e.max_lat >= ? AND e.min_lat <= ? AND e.max_lon >= ? AND e.min_lon <= ?';
            $params = [$minLat, $maxLat, $minLon, $maxLon];
        }
        $sql .= ' ORDER BY e.id LIMIT ?';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $i => $v) {
            $stmt->bindValue($i + 1, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{claimant_id:int,type:string,handle:?string,name:?string}|null */
    public function claimantInfo(int $claimantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.type,
                    u.public_handle AS rider_handle, u.display_name AS rider_name,
                    cr.slug AS crew_slug, cr.name AS crew_name
               FROM game_claimant c
               LEFT JOIN users u      ON u.id = c.user_id
               LEFT JOIN game_crew cr ON cr.claimant_id = c.id
              WHERE c.id = ?'
        );
        $stmt->execute([$claimantId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        $type = (string)$r['type'];
        if ($type === 'group') {
            return [
                'claimant_id' => (int)$r['id'],
                'type'        => 'group',
                'handle'      => $r['crew_slug'] !== null ? (string)$r['crew_slug'] : null,
                'name'        => $r['crew_name'] !== null ? (string)$r['crew_name'] : null,
            ];
        }
        return [
            'claimant_id' => (int)$r['id'],
            'type'        => $type,
            'handle'      => $r['rider_handle'] !== null ? (string)$r['rider_handle'] : null,
            'name'        => $r['rider_name'] !== null ? (string)$r['rider_name'] : null,
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
