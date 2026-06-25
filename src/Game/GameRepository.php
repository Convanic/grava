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

    /**
     * @return bool true wenn ein NEUER Pass angelegt wurde (sonst Tages-Deckel).
     *
     * $rushId (Auto-Tag, §3.1): kollabiert ein Tag auf einen Pass, gewinnt der
     * rush_id des getaggten (im Fenster liegenden) Passes — COALESCE bewahrt
     * einen bereits gesetzten Tag, übernimmt aber einen neuen, wenn er fehlte.
     */
    public function insertPassIfAbsent(
        int $edgeId,
        int $claimantId,
        int $userId,
        int $routeId,
        string $riddenOn,
        string $riddenAt,
        ?int $rushId = null,
    ): bool {
        $stmt = $this->pdo->prepare(
            'INSERT INTO game_edge_pass
                (edge_id, claimant_id, user_id, route_id, ridden_on, ridden_at, rush_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                ridden_at = GREATEST(ridden_at, VALUES(ridden_at)),
                rush_id   = COALESCE(VALUES(rush_id), rush_id)'
        );
        $stmt->execute([$edgeId, $claimantId, $userId, $routeId, $riddenOn, $riddenAt, $rushId]);
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
     * @return list<array{claimant_id:int,user_id:int,ridden_on:string,ridden_at:string,rush_id:?int}>
     */
    public function passesForEdge(int $edgeId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT claimant_id, user_id, ridden_on, ridden_at, rush_id FROM game_edge_pass
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
                'rush_id'     => $r['rush_id'] !== null ? (int)$r['rush_id'] : null,
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------------
    // Rush / Group-Ride-Übernahme (GAME_RUSH_BACKEND.md) — Ingest + Recompute
    // ----------------------------------------------------------------

    /**
     * Nicht-abgeschlossene Rushes (planned/active) der Crew des Users — für den
     * Auto-Tag beim Ingest (§3.1). Die eigentliche Fenster-/Announcement-Prüfung
     * passiert in PHP, damit pro Route nur EINE Query nötig ist.
     *
     * @return list<array{id:int,start_at:string,end_at:string,created_at:string}>
     */
    public function openRushesForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.start_at, r.end_at, r.created_at
               FROM game_crew_member m
               JOIN game_rush r ON r.crew_id = m.crew_id
              WHERE m.user_id = ? AND r.status IN ("planned","active")'
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'         => (int)$r['id'],
                'start_at'   => (string)$r['start_at'],
                'end_at'     => (string)$r['end_at'],
                'created_at' => (string)$r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * Recompute-Infos je Rush (§3.2/§3.3): Multiplikator-Snapshot, Status,
     * Crew-Claimant und die Zahl distinct getaggter Fahrer (Qualifikations-Gate).
     *
     * @param list<int> $rushIds
     * @return array<int,array{multiplier:float,status:string,crew_claimant_id:int,distinct_riders:int}>
     */
    public function rushInfoMany(array $rushIds): array
    {
        $rushIds = array_values(array_unique(array_map('intval', $rushIds)));
        if ($rushIds === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($rushIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.multiplier, r.status, cr.claimant_id AS crew_claimant_id,
                    (SELECT COUNT(DISTINCT p.user_id) FROM game_edge_pass p
                      WHERE p.rush_id = r.id AND p.invalidated_at IS NULL) AS distinct_riders
               FROM game_rush r
               JOIN game_crew cr ON cr.id = r.crew_id
              WHERE r.id IN ($ph)"
        );
        $stmt->execute($rushIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = [
                'multiplier'       => (float)$r['multiplier'],
                'status'           => (string)$r['status'],
                'crew_claimant_id' => (int)$r['crew_claimant_id'],
                'distinct_riders'  => (int)$r['distinct_riders'],
            ];
        }
        return $out;
    }

    /**
     * Distinct getaggte Kanten eines Rushes (sortiert nach edge_id) — Basis für
     * den deterministischen Edge-Cap (§3.3) und den gezielten Recompute (§7).
     *
     * @return list<int>
     */
    public function rushTaggedEdgeIds(int $rushId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT edge_id FROM game_edge_pass
              WHERE rush_id = ? AND invalidated_at IS NULL
              ORDER BY edge_id'
        );
        $stmt->execute([$rushId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
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
        ?int $discovererClaimantId = null,
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
                traffic_observations = ?,
                discoverer_claimant_id = ?
             WHERE id = ?'
        )->execute([
            $ownerClaimantId, $ownerSince, $value, $freshness, $lastPassAt,
            $trafficFactor, $trafficPassCount, $trafficObservations,
            $discovererClaimantId, $edgeId,
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
        $stmt = $this->pdo->prepare('SELECT user_id, public_id, source FROM routes WHERE id = ?');
        $stmt->execute([$routeId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return [
            'user_id'   => (int)$r['user_id'],
            'public_id' => (string)$r['public_id'],
            'source'    => (string)($r['source'] ?? 'app'),
        ];
    }

    /**
     * Löst eine Eingabe (interne Route-ID als Zahl ODER Public-ID/UUID) zu
     * Route auf. Für die manuelle Admin-Ingestion beliebiger Routen.
     *
     * @return array{route_id:int,user_id:int,public_id:string,source:string}|null
     */
    public function resolveRouteForIngest(string $idOrPublicId): ?array
    {
        $idOrPublicId = trim($idOrPublicId);
        if ($idOrPublicId === '') {
            return null;
        }
        if (ctype_digit($idOrPublicId)) {
            $stmt = $this->pdo->prepare('SELECT id, user_id, public_id, source FROM routes WHERE id = ?');
            $stmt->bindValue(1, (int)$idOrPublicId, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare('SELECT id, user_id, public_id, source FROM routes WHERE public_id = ?');
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
            'source'    => (string)($r['source'] ?? 'app'),
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
        // Erst-Fahrer (Entdecker-Mensch) je Kante set-basiert per Window-Funktion
        // statt zweier korrelierter Subqueries pro Zeile — portabel (MySQL 8 /
        // MariaDB 10.2+) und ohne Per-Kante-Filesort über alle Kanten.
        $sql = 'SELECT e.id, e.geom_geojson, e.length_m, e.surface_character,
                       e.owner_claimant_id, c.type AS owner_type,
                       u.public_handle AS owner_handle,
                       cr.id AS crew_id, cr.name AS crew_name,
                       f.id AS faction_id, f.key_slug AS faction_key, f.color_hex AS faction_color,
                       e.value_cached, e.freshness_cached, e.distinct_riders_total,
                       e.min_lat, e.min_lon, e.max_lat, e.max_lon,
                       fr.user_id AS rider_user_id, ru.public_handle AS rider_handle
                  FROM game_edge e
                  LEFT JOIN game_claimant c ON c.id = e.owner_claimant_id
                  LEFT JOIN users u ON u.id = c.user_id
                  LEFT JOIN game_crew cr ON cr.claimant_id = e.owner_claimant_id
                  LEFT JOIN game_faction f ON f.id = cr.faction_id
                  LEFT JOIN (
                      SELECT edge_id, user_id FROM (
                          SELECT edge_id, user_id,
                                 ROW_NUMBER() OVER (PARTITION BY edge_id ORDER BY ridden_at ASC, id ASC) AS rn
                            FROM game_edge_pass
                           WHERE invalidated_at IS NULL
                      ) ranked WHERE ranked.rn = 1
                  ) fr ON fr.edge_id = e.id
                  LEFT JOIN users ru ON ru.id = fr.user_id';
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

    /** @return array{claimant_id:int,type:string,handle:?string,name:?string,faction?:array{key:string,color:string}}|null */
    public function claimantInfo(int $claimantId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.type,
                    u.public_handle AS rider_handle, u.display_name AS rider_name,
                    cr.slug AS crew_slug, cr.name AS crew_name,
                    f.key_slug AS faction_key, f.color_hex AS faction_color
               FROM game_claimant c
               LEFT JOIN users u      ON u.id = c.user_id
               LEFT JOIN game_crew cr ON cr.claimant_id = c.id
               LEFT JOIN game_faction f ON f.id = cr.faction_id
              WHERE c.id = ?'
        );
        $stmt->execute([$claimantId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        $type = (string)$r['type'];
        if ($type === 'group') {
            $info = [
                'claimant_id' => (int)$r['id'],
                'type'        => 'group',
                'handle'      => $r['crew_slug'] !== null ? (string)$r['crew_slug'] : null,
                'name'        => $r['crew_name'] !== null ? (string)$r['crew_name'] : null,
            ];
            // Stufe 3: Fraktions-Tönung der Kante (additiv), wenn die
            // Besitzer-Crew einer Fraktion angehört.
            if ($r['faction_key'] !== null) {
                $info['faction'] = [
                    'key'   => (string)$r['faction_key'],
                    'color' => (string)$r['faction_color'],
                ];
            }
            return $info;
        }
        return [
            'claimant_id' => (int)$r['id'],
            'type'        => $type,
            'handle'      => $r['rider_handle'] !== null ? (string)$r['rider_handle'] : null,
            'name'        => $r['rider_name'] !== null ? (string)$r['rider_name'] : null,
        ];
    }

    /**
     * Aktuelle Besitzer-Claimants einer Kantenmenge (für die
     * territory_taken-Erkennung: Vorher/Nachher-Vergleich).
     *
     * @param list<int> $edgeIds
     * @return array<int,?int> [edgeId => owner_claimant_id|null]
     */
    public function ownersForEdges(array $edgeIds): array
    {
        if ($edgeIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($edgeIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, owner_claimant_id FROM game_edge WHERE id IN ($in)"
        );
        $stmt->execute(array_values($edgeIds));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = $r['owner_claimant_id'] !== null ? (int)$r['owner_claimant_id'] : null;
        }
        return $out;
    }

    /**
     * Reale User hinter einem Claimant: bei Rider der eine User, bei einer
     * Gruppe (Crew) alle Mitglieder. Für territory_taken-Empfänger.
     *
     * @return list<int>
     */
    public function usersForClaimant(int $claimantId): array
    {
        $stmt = $this->pdo->prepare('SELECT user_id, type FROM game_claimant WHERE id = ?');
        $stmt->execute([$claimantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [];
        }
        if ($row['user_id'] !== null) {
            return [(int)$row['user_id']];
        }
        $stmt = $this->pdo->prepare(
            'SELECT m.user_id
               FROM game_crew cr
               JOIN game_crew_member m ON m.crew_id = cr.id
              WHERE cr.claimant_id = ?'
        );
        $stmt->execute([$claimantId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
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

    // ----------------------------------------------------------------
    // Spieler-Rangliste (S7) — reine Lese-Aggregationen
    // ----------------------------------------------------------------

    /**
     * Gültige Pässe seit $sinceDate samt Kantenlänge/-wert — Basis für die
     * Pro-Fahrer-Präsenz (area/value). Invalidierte ausgeschlossen.
     *
     * @return list<array{edge_id:int,user_id:int,ridden_at:string,length_m:float,value:float}>
     */
    public function passesWithEdgeSince(string $sinceDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.edge_id, p.user_id, p.ridden_at, e.length_m, e.value_cached
               FROM game_edge_pass p
               JOIN game_edge e ON e.id = p.edge_id
              WHERE p.invalidated_at IS NULL AND p.ridden_on >= ?'
        );
        $stmt->execute([$sinceDate]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'edge_id'   => (int)$r['edge_id'],
                'user_id'   => (int)$r['user_id'],
                'ridden_at' => (string)$r['ridden_at'],
                'length_m'  => (float)$r['length_m'],
                'value'     => (float)$r['value_cached'],
            ];
        }
        return $out;
    }

    /**
     * Gefahrene Distanz je Fahrer (Σ Kantenlänge über gültige Pässe),
     * optional auf ein Fenster begrenzt. Besitzunabhängig.
     *
     * @return array<int,float> user_id => distance_m
     */
    public function distanceByUserSince(?string $sinceDate): array
    {
        $sql = 'SELECT p.user_id, COALESCE(SUM(e.length_m),0) AS dist
                  FROM game_edge_pass p
                  JOIN game_edge e ON e.id = p.edge_id
                 WHERE p.invalidated_at IS NULL';
        $params = [];
        if ($sinceDate !== null) {
            $sql .= ' AND p.ridden_on >= ?';
            $params[] = $sinceDate;
        }
        $sql .= ' GROUP BY p.user_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['user_id']] = (float)$r['dist'];
        }
        return $out;
    }

    /**
     * Pionier-Kohorten-Zähler je Fahrer: Anzahl Kanten, in deren erster
     * Kohorte (erste ≤ $cohort Erstbefahrer nach first_ridden_at) der Fahrer
     * steht. Optional auf Kanten begrenzt, deren Erstbefahrung DES FAHRERS
     * im Fenster (seit $sinceDate) liegt. Invalidierte ausgeschlossen.
     *
     * @return array<int,int> user_id => count
     */
    public function pioneerCountByUserSince(?string $sinceDate, int $cohort = 10): array
    {
        $cohort = max(1, $cohort);
        $sql =
            "SELECT user_id, COUNT(*) AS cnt FROM (
                SELECT edge_id, user_id, first_at,
                       ROW_NUMBER() OVER (PARTITION BY edge_id ORDER BY first_at ASC, user_id ASC) AS rn
                  FROM (
                        SELECT edge_id, user_id, MIN(ridden_at) AS first_at
                          FROM game_edge_pass
                         WHERE invalidated_at IS NULL
                         GROUP BY edge_id, user_id
                       ) firsts
             ) ranked
             WHERE rn <= {$cohort}"
            . ($sinceDate !== null ? ' AND first_at >= ?' : '')
            . ' GROUP BY user_id';
        $params = $sinceDate !== null ? [$sinceDate] : [];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['user_id']] = (int)$r['cnt'];
        }
        return $out;
    }

    /**
     * IDs der Fahrer, denen $userId folgt (Follow-Graph) — für scope=friends.
     *
     * @return list<int>
     */
    public function followeeIds(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT followee_id FROM follows WHERE follower_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Handles (public_handle) zu einer User-Menge.
     *
     * @param list<int> $userIds
     * @return array<int,?string>
     */
    public function handlesFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->pdo->prepare("SELECT id, public_handle FROM users WHERE id IN ($in)");
        $stmt->execute(array_values($userIds));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = $r['public_handle'] !== null ? (string)$r['public_handle'] : null;
        }
        return $out;
    }

    // ----------------------------------------------------------------
    // Segment-Speed / Tempo-Wertung (GAME_SEGMENT_SPEED_BACKEND.md)
    // ----------------------------------------------------------------

    /**
     * Schreibt eine Effort-Zeile (Tempo-Wertung). NICHT tagesgedeckelt —
     * jede authentische, getimte Befahrung zählt; Best-of entsteht beim Lesen.
     */
    public function insertSegmentEffort(
        int $edgeId,
        int $claimantId,
        int $userId,
        int $routeId,
        string $riddenAt,
        float $durationS,
        float $avgSpeedKmh,
        float $lengthM,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO game_segment_effort
                (edge_id, claimant_id, user_id, route_id, ridden_at, duration_s, avg_speed_kmh, length_m)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$edgeId, $claimantId, $userId, $routeId, $riddenAt, $durationS, $avgSpeedKmh, $lengthM]);
    }

    /**
     * Bestzeit je Fahrer (MIN duration_s) auf einer Kante, optional im Fenster.
     * Tie-Break-stabil (frühere ridden_at, kleinere id) — eine Zeile pro Fahrer.
     *
     * @return list<array{user_id:int,duration_s:float,avg_speed_kmh:float,achieved_at:string}>
     */
    public function bestEffortsForEdge(int $edgeId, ?string $sinceDate): array
    {
        $sql =
            'SELECT user_id, duration_s, avg_speed_kmh, achieved_at FROM (
                SELECT user_id, duration_s, avg_speed_kmh, ridden_at AS achieved_at,
                       ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY duration_s ASC, ridden_at ASC, id ASC) AS rn
                  FROM game_segment_effort
                 WHERE edge_id = ?'
            . ($sinceDate !== null ? ' AND ridden_at >= ?' : '')
            . ') t WHERE rn = 1';
        $params = $sinceDate !== null ? [$edgeId, $sinceDate] : [$edgeId];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'user_id'       => (int)$r['user_id'],
                'duration_s'    => (float)$r['duration_s'],
                'avg_speed_kmh' => (float)$r['avg_speed_kmh'],
                'achieved_at'   => (string)$r['achieved_at'],
            ];
        }
        return $out;
    }

    /**
     * Bestzeit je Fahrer für MEHRERE Kanten gebündelt (für Rang/Teilnehmer in
     * /game/me/segments). Gruppiert nach edge_id.
     *
     * @param list<int> $edgeIds
     * @return array<int,list<array{user_id:int,duration_s:float}>>
     */
    public function bestEffortsForEdges(array $edgeIds, ?string $sinceDate): array
    {
        $edgeIds = array_values(array_unique(array_map('intval', $edgeIds)));
        if ($edgeIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($edgeIds), '?'));
        $sql =
            "SELECT edge_id, user_id, duration_s FROM (
                SELECT edge_id, user_id, duration_s,
                       ROW_NUMBER() OVER (PARTITION BY edge_id, user_id ORDER BY duration_s ASC, ridden_at ASC, id ASC) AS rn
                  FROM game_segment_effort
                 WHERE edge_id IN ($in)"
            . ($sinceDate !== null ? ' AND ridden_at >= ?' : '')
            . ') t WHERE rn = 1';
        $params = $edgeIds;
        if ($sinceDate !== null) {
            $params[] = $sinceDate;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['edge_id']][] = [
                'user_id'    => (int)$r['user_id'],
                'duration_s' => (float)$r['duration_s'],
            ];
        }
        return $out;
    }

    /**
     * Persönliche Bestzeiten eines Fahrers über alle Kanten (eine Zeile pro
     * Kante), zuletzt erzielt zuerst, paginiert, mit Kanten-Meta.
     *
     * @return list<array{edge_id:int,length_m:float,surface:?string,best_duration_s:float,best_avg_speed_kmh:float,achieved_at:string}>
     */
    public function userSegmentBests(int $userId, ?string $sinceDate, int $limit, int $offset): array
    {
        $sql =
            'SELECT t.edge_id, t.duration_s, t.avg_speed_kmh, t.achieved_at,
                    e.length_m, e.surface_character
               FROM (
                SELECT edge_id, duration_s, avg_speed_kmh, ridden_at AS achieved_at,
                       ROW_NUMBER() OVER (PARTITION BY edge_id ORDER BY duration_s ASC, ridden_at ASC, id ASC) AS rn
                  FROM game_segment_effort
                 WHERE user_id = ?'
            . ($sinceDate !== null ? ' AND ridden_at >= ?' : '')
            . ') t JOIN game_edge e ON e.id = t.edge_id
               WHERE t.rn = 1
               ORDER BY t.achieved_at DESC, t.edge_id DESC
               LIMIT ? OFFSET ?';
        $stmt = $this->pdo->prepare($sql);
        $i = 1;
        $stmt->bindValue($i++, $userId, PDO::PARAM_INT);
        if ($sinceDate !== null) {
            $stmt->bindValue($i++, $sinceDate, PDO::PARAM_STR);
        }
        $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'edge_id'            => (int)$r['edge_id'],
                'length_m'           => (float)$r['length_m'],
                'surface'            => $r['surface_character'] !== null ? (string)$r['surface_character'] : null,
                'best_duration_s'    => (float)$r['duration_s'],
                'best_avg_speed_kmh' => (float)$r['avg_speed_kmh'],
                'achieved_at'        => (string)$r['achieved_at'],
            ];
        }
        return $out;
    }

    /** Anzahl Kanten, auf denen der Fahrer (im Fenster) Efforts hat — für Pagination. */
    public function countUserSegments(int $userId, ?string $sinceDate): int
    {
        $sql = 'SELECT COUNT(DISTINCT edge_id) FROM game_segment_effort WHERE user_id = ?'
            . ($sinceDate !== null ? ' AND ridden_at >= ?' : '');
        $params = $sinceDate !== null ? [$userId, $sinceDate] : [$userId];
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Spiel-Zusammenfassung einer Route (STRAVA_SHARE_BACKEND.md §2).
     *
     * @return array{
     *   edges_total:int, edges_new:int, edges_taken_over:int,
     *   pioneer_names:list<string>
     * }
     */
    public function rideSummaryStats(int $routeId, int $userId, int $claimantId): array
    {
        $base = 'FROM game_edge_pass p
                 JOIN game_edge e ON e.id = p.edge_id
                 WHERE p.route_id = ? AND p.user_id = ? AND p.invalidated_at IS NULL';

        $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT p.edge_id) {$base}");
        $stmt->execute([$routeId, $userId]);
        $edgesTotal = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT p.edge_id) {$base}
               AND p.ridden_at = (
                   SELECT MIN(p2.ridden_at) FROM game_edge_pass p2
                    WHERE p2.edge_id = p.edge_id AND p2.invalidated_at IS NULL
               )"
        );
        $stmt->execute([$routeId, $userId]);
        $edgesNew = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT p.edge_id) {$base}
               AND e.owner_claimant_id = ?
               AND e.discoverer_claimant_id IS NOT NULL
               AND e.discoverer_claimant_id != ?
               AND EXISTS (
                   SELECT 1 FROM game_edge_pass p2
                    WHERE p2.edge_id = p.edge_id
                      AND p2.invalidated_at IS NULL
                      AND p2.claimant_id != ?
                      AND p2.ridden_at < p.ridden_at
               )"
        );
        $stmt->execute([$routeId, $userId, $claimantId, $claimantId, $claimantId]);
        $edgesTakenOver = (int)$stmt->fetchColumn();

        return [
            'edges_total'      => $edgesTotal,
            'edges_new'        => $edgesNew,
            'edges_taken_over' => $edgesTakenOver,
            'pioneer_names'    => [],
        ];
    }

    /**
     * Kanten dieser Route inkl. Kategorie für die Share-Karte (STRAVA_SHARE_BACKEND.md §2.1).
     *
     * @return list<array{edge_id:int,category:string,geom_geojson:string}>
     */
    public function rideSummaryEdges(int $routeId, int $userId, int $claimantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.edge_id, e.geom_geojson,
                    CASE
                      WHEN p.ridden_at = (
                          SELECT MIN(p2.ridden_at) FROM game_edge_pass p2
                           WHERE p2.edge_id = p.edge_id AND p2.invalidated_at IS NULL
                      ) THEN "pioneer"
                      WHEN e.owner_claimant_id = ?
                       AND e.discoverer_claimant_id IS NOT NULL
                       AND e.discoverer_claimant_id != ?
                       AND EXISTS (
                          SELECT 1 FROM game_edge_pass p2
                           WHERE p2.edge_id = p.edge_id
                             AND p2.invalidated_at IS NULL
                             AND p2.claimant_id != ?
                             AND p2.ridden_at < p.ridden_at
                       ) THEN "captured"
                      ELSE "held"
                    END AS category
               FROM game_edge_pass p
               JOIN game_edge e ON e.id = p.edge_id
              WHERE p.route_id = ? AND p.user_id = ? AND p.invalidated_at IS NULL
              GROUP BY p.edge_id, e.geom_geojson, category
              ORDER BY p.edge_id'
        );
        $stmt->execute([$claimantId, $claimantId, $claimantId, $routeId, $userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'edge_id'       => (int)$r['edge_id'],
                'category'      => (string)$r['category'],
                'geom_geojson'  => (string)$r['geom_geojson'],
            ];
        }
        return $out;
    }

    /** @return array{rush_id:int,edges_rushed:int}|null */
    public function rideRushAggregate(int $routeId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rush_id, COUNT(DISTINCT edge_id) AS edges_rushed
               FROM game_edge_pass
              WHERE route_id = ? AND user_id = ? AND invalidated_at IS NULL
                AND rush_id IS NOT NULL
              GROUP BY rush_id
              ORDER BY edges_rushed DESC
              LIMIT 1'
        );
        $stmt->execute([$routeId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return ['rush_id' => (int)$row['rush_id'], 'edges_rushed' => (int)$row['edges_rushed']];
    }

    public function crewNameById(int $crewId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM game_crew WHERE id = ? LIMIT 1');
        $stmt->execute([$crewId]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string)$name;
    }
}
