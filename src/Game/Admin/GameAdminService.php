<?php
declare(strict_types=1);
namespace App\Game\Admin;

use App\Game\GameConfig;
use App\Game\GameMath;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Lese-Aggregate für das Admin-Dashboard: Health, Ingest-Monitor, Leaderboard, Kanten-Inspector. */
final class GameAdminService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly GameRepository $repo,
        private readonly GameConfig $config,
    ) {}

    /** @return array{nodes:int,edges:int,passes_total:int,passes_24h:int,active_riders_90d:int} */
    public function healthMetrics(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $since24h = $now->modify('-24 hours')->format('Y-m-d H:i:s.v');
        $since90d = $now->modify('-90 days')->format('Y-m-d');
        $count = fn(string $sql, array $p = []): int => (function () use ($sql, $p): int {
            $s = $this->pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn();
        })();
        return [
            'nodes'             => $count('SELECT COUNT(*) FROM game_node'),
            'edges'             => $count('SELECT COUNT(*) FROM game_edge'),
            'passes_total'      => $count('SELECT COUNT(*) FROM game_edge_pass WHERE invalidated_at IS NULL'),
            'passes_24h'        => $count('SELECT COUNT(*) FROM game_edge_pass WHERE invalidated_at IS NULL AND ridden_at >= ?', [$since24h]),
            'active_riders_90d' => $count('SELECT COUNT(DISTINCT user_id) FROM game_edge_pass WHERE invalidated_at IS NULL AND ridden_on >= ?', [$since90d]),
        ];
    }

    /** @return array{ok:int,pending:int,failed:int,match_rate:float} */
    public function ingestHealth(): array
    {
        $rows = $this->pdo->query(
            "SELECT status, COUNT(*) AS c FROM game_ingest_log GROUP BY status"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $ok = (int)($rows['ok'] ?? 0);
        $pending = (int)($rows['pending'] ?? 0);
        $failed = (int)($rows['failed'] ?? 0);
        $total = $ok + $pending + $failed;
        return [
            'ok' => $ok, 'pending' => $pending, 'failed' => $failed,
            'match_rate' => $total > 0 ? round($ok / $total, 4) : 0.0,
        ];
    }

    /** @return list<array<string,mixed>> letzte Ingest-Log-Zeilen, optional nach Status gefiltert. */
    public function recentIngests(?string $status, int $limit = 50): array
    {
        if ($status !== null && in_array($status, ['ok', 'pending', 'failed'], true)) {
            $stmt = $this->pdo->prepare('SELECT * FROM game_ingest_log WHERE status = ? ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $status, PDO::PARAM_STR);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM game_ingest_log ORDER BY id DESC LIMIT ?');
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array{claimant_id:int,handle:?string,held_edges:int,held_length_m:float,pioneered:int}> */
    public function leaderboard(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id AS claimant_id, u.public_handle AS handle,
                    (SELECT COUNT(*) FROM game_edge e WHERE e.owner_claimant_id = c.id) AS held_edges,
                    (SELECT COALESCE(SUM(length_m),0) FROM game_edge e WHERE e.owner_claimant_id = c.id) AS held_length_m,
                    (SELECT COUNT(*) FROM game_edge e WHERE e.discoverer_claimant_id = c.id) AS pioneered
               FROM game_claimant c
               LEFT JOIN users u ON u.id = c.user_id
              WHERE c.type = "rider"
             HAVING held_edges > 0 OR pioneered > 0
              ORDER BY held_edges DESC, held_length_m DESC, pioneered DESC, c.id ASC
              LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'claimant_id'   => (int)$r['claimant_id'],
                'handle'        => $r['handle'] !== null ? (string)$r['handle'] : null,
                'held_edges'    => (int)$r['held_edges'],
                'held_length_m' => (float)$r['held_length_m'],
                'pioneered'     => (int)$r['pioneered'],
            ];
        }
        return $out;
    }

    /**
     * Crew-Rangliste: gehaltene Kanten/Länge + Pionierleistung je Crew-
     * (Group-)Claimant, plus Mitgliederzahl und Captain. Zeigt alle Crews
     * (auch ohne Territorium), sortiert nach Besitz.
     *
     * @return list<array{crew_id:int,name:string,slug:string,members:int,held_edges:int,held_length_m:float,pioneered:int,captain_handle:?string}>
     */
    public function crewLeaderboard(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cr.id AS crew_id, cr.name, cr.slug,
                    (SELECT COUNT(*) FROM game_crew_member m WHERE m.crew_id = cr.id) AS members,
                    (SELECT COUNT(*) FROM game_edge e WHERE e.owner_claimant_id = cr.claimant_id) AS held_edges,
                    (SELECT COALESCE(SUM(length_m),0) FROM game_edge e WHERE e.owner_claimant_id = cr.claimant_id) AS held_length_m,
                    (SELECT COUNT(*) FROM game_edge e WHERE e.discoverer_claimant_id = cr.claimant_id) AS pioneered,
                    cap.public_handle AS captain_handle
               FROM game_crew cr
               LEFT JOIN game_crew_member cm ON cm.crew_id = cr.id AND cm.role = "captain"
               LEFT JOIN users cap ON cap.id = cm.user_id
              ORDER BY held_edges DESC, held_length_m DESC, pioneered DESC, members DESC, cr.id ASC
              LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'crew_id'        => (int)$r['crew_id'],
                'name'           => (string)$r['name'],
                'slug'           => (string)$r['slug'],
                'members'        => (int)$r['members'],
                'held_edges'     => (int)$r['held_edges'],
                'held_length_m'  => (float)$r['held_length_m'],
                'pioneered'      => (int)$r['pioneered'],
                'captain_handle' => $r['captain_handle'] !== null ? (string)$r['captain_handle'] : null,
            ];
        }
        return $out;
    }

    /**
     * Spieler-Detail: Welche Strecken eines Users fliessen wie in die Wertung
     * ein — solo (Rider-Claimant) oder Crew (Group-Claimant)? Auflösung des
     * Users per E-Mail ODER public_handle.
     *
     * Pro Strecke: Anzahl der vom User befahrenen Kanten, davon im
     * Präsenz-Fenster, und wie viele davon aktuell vom Solo-, Crew- oder einem
     * fremden Claimant gehalten werden. So ist sofort sichtbar, was für die
     * Crew zählt und was (noch) solo bzw. abgelaufen ist.
     *
     * @return array<string,mixed>|null null, wenn kein User gefunden wurde.
     */
    public function playerDetail(string $query, ?DateTimeImmutable $now = null): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $u = $this->pdo->prepare(
            'SELECT id, email, public_handle, display_name, status
               FROM users WHERE email = ? OR public_handle = ? LIMIT 1'
        );
        $u->execute([$query, $query]);
        $user = $u->fetch(PDO::FETCH_ASSOC);
        if ($user === false) {
            return null;
        }
        $uid   = (int)$user['id'];
        $rider = $this->repo->findRiderClaimantId($uid) ?? 0;

        $cs = $this->pdo->prepare(
            'SELECT gc.id AS crew_id, gc.name, gc.slug, gc.claimant_id, m.role
               FROM game_crew_member m JOIN game_crew gc ON gc.id = m.crew_id
              WHERE m.user_id = ? LIMIT 1'
        );
        $cs->execute([$uid]);
        $crewRow = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
        $crewClaimant = $crewRow !== null ? (int)$crewRow['claimant_id'] : 0;
        $effective    = $crewClaimant !== 0 ? $crewClaimant : $rider;

        $windowDays = $this->config->int('presence_window_days');
        $since = $now->modify("-{$windowDays} days")->format('Y-m-d');

        // Gewertete Strecken (über die Passes des Users). Die Owner-Zuordnung je
        // Kante entscheidet, ob sie aktuell solo/crew/fremd gehalten wird.
        $scoredSql =
            'SELECT pe.route_id, r.public_id, r.title,
                    ROUND(r.distance_m/1000, 1) AS km,
                    pe.pass_edges, pe.last_ride, pe.in_window_edges,
                    pe.held_solo, pe.held_crew, pe.held_other
               FROM (
                 SELECT x.route_id,
                        COUNT(*)            AS pass_edges,
                        MAX(x.last_ride)    AS last_ride,
                        SUM(x.in_window)    AS in_window_edges,
                        SUM(CASE WHEN e.owner_claimant_id = ' . $rider . ' THEN 1 ELSE 0 END) AS held_solo,
                        SUM(CASE WHEN ' . $crewClaimant . ' <> 0 AND e.owner_claimant_id = ' . $crewClaimant . ' THEN 1 ELSE 0 END) AS held_crew,
                        SUM(CASE WHEN e.owner_claimant_id IS NOT NULL
                                  AND e.owner_claimant_id <> ' . $rider . '
                                  AND (' . $crewClaimant . ' = 0 OR e.owner_claimant_id <> ' . $crewClaimant . ') THEN 1 ELSE 0 END) AS held_other
                   FROM (
                     SELECT p.route_id, p.edge_id,
                            MAX(p.ridden_on) AS last_ride,
                            MAX(CASE WHEN p.ridden_on >= ? THEN 1 ELSE 0 END) AS in_window
                       FROM game_edge_pass p
                      WHERE p.user_id = ' . $uid . ' AND p.invalidated_at IS NULL
                      GROUP BY p.route_id, p.edge_id
                   ) x
                   JOIN game_edge e ON e.id = x.edge_id
                  GROUP BY x.route_id
               ) pe
               LEFT JOIN routes r ON r.id = pe.route_id
              ORDER BY pe.last_ride DESC, pe.route_id ASC';
        $st = $this->pdo->prepare($scoredSql);
        $st->execute([$since]);

        $routes = [];
        $totals = ['pass_edges' => 0, 'in_window_edges' => 0, 'held_solo' => 0, 'held_crew' => 0, 'held_other' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = [
                'route_id'        => (int)$r['route_id'],
                'public_id'       => $r['public_id'] !== null ? (string)$r['public_id'] : null,
                'title'           => $r['title'] !== null ? (string)$r['title'] : null,
                'km'              => $r['km'] !== null ? (float)$r['km'] : null,
                'pass_edges'      => (int)$r['pass_edges'],
                'in_window_edges' => (int)$r['in_window_edges'],
                'held_solo'       => (int)$r['held_solo'],
                'held_crew'       => (int)$r['held_crew'],
                'held_other'      => (int)$r['held_other'],
                'last_ride'       => $r['last_ride'] !== null ? (string)$r['last_ride'] : null,
            ];
            foreach ($totals as $k => $_) {
                $totals[$k] += $row[$k];
            }
            $routes[] = $row;
        }

        // Hochgeladene Routen ohne (gültige) Passes des Users → nicht in Wertung.
        $un = $this->pdo->prepare(
            'SELECT r.id, r.public_id, r.title, ROUND(r.distance_m/1000,1) AS km,
                    r.visibility, r.source, r.created_at
               FROM routes r
              WHERE r.user_id = ? AND r.deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM game_edge_pass p
                     WHERE p.route_id = r.id AND p.user_id = r.user_id AND p.invalidated_at IS NULL
                )
              ORDER BY r.created_at DESC'
        );
        $un->execute([$uid]);
        $unscored = [];
        foreach ($un->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $unscored[] = [
                'public_id'  => (string)$r['public_id'],
                'title'      => (string)$r['title'],
                'km'         => $r['km'] !== null ? (float)$r['km'] : null,
                'visibility' => (string)$r['visibility'],
                'source'     => (string)$r['source'],
                'created_at' => (string)$r['created_at'],
            ];
        }

        return [
            'user' => [
                'id'           => $uid,
                'email'        => (string)$user['email'],
                'handle'       => $user['public_handle'] !== null ? (string)$user['public_handle'] : null,
                'display_name' => $user['display_name'] !== null ? (string)$user['display_name'] : null,
                'status'       => (string)$user['status'],
            ],
            'rider_claimant_id'     => $rider,
            'crew'                  => $crewRow !== null ? [
                'crew_id'     => (int)$crewRow['crew_id'],
                'name'        => (string)$crewRow['name'],
                'slug'        => (string)$crewRow['slug'],
                'claimant_id' => $crewClaimant,
                'role'        => (string)$crewRow['role'],
            ] : null,
            'effective_claimant_id' => $effective,
            'is_crew_member'        => $crewClaimant !== 0,
            'presence_window_days'  => $windowDays,
            'totals'                => $totals,
            'routes'                => $routes,
            'unscored_routes'       => $unscored,
        ];
    }

    /** @return array<string,mixed>|null Inspector-Aggregat einer Kante. */
    public function edgeInspector(int $edgeId, ?DateTimeImmutable $now = null): ?array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $edge = $this->repo->edgeById($edgeId);
        if ($edge === null) {
            return null;
        }
        $windowDays = $this->config->int('presence_window_days');
        $n = $this->repo->distinctRidersTotal($edgeId);
        $sinceDate = $now->modify("-{$windowDays} days")->format('Y-m-d');
        $n90 = $this->repo->distinctRidersSince($edgeId, $sinceDate);
        $pioneer = GameMath::pioneer($n, $this->config->float('pioneer_p0'), $this->config->float('pioneer_k'), $this->config->float('pioneer_s'));
        $popularity = GameMath::popularity($n90, $this->config->float('popularity_c'));
        $curation = 0.0;
        $total = GameMath::combineValue($pioneer, $popularity, $curation);

        $owner = $edge['owner_claimant_id'] !== null ? $this->repo->claimantInfo((int)$edge['owner_claimant_id']) : null;
        $discoverer = $edge['discoverer_claimant_id'] !== null ? $this->repo->claimantInfo((int)$edge['discoverer_claimant_id']) : null;

        return [
            'edge'       => $edge,
            'owner'      => $owner,
            'discoverer' => $discoverer,
            'n'          => $n,
            'n90'        => $n90,
            'value'      => [
                'pioneer'    => $pioneer,
                'popularity' => $popularity,
                'curation'   => $curation,
                'total'      => $total,
            ],
            'cohort'     => $this->repo->firstPassPerUser($edgeId, 10),
            'passes'     => $this->repo->allPassesForEdge($edgeId),
        ];
    }
}
