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
