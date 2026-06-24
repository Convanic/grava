<?php
declare(strict_types=1);

namespace App\Game;

use App\Support\Clock;
use DateTimeImmutable;

/**
 * Segment-Speed / Tempo-Wertung (siehe backend/GAME_SEGMENT_SPEED_BACKEND.md):
 * KOM/QOM-artige Bestzeit-Rangliste pro Kante (game_edge) plus die persönlichen
 * Bestzeiten eines Fahrers. Reine Lese-Aggregation aus game_segment_effort
 * (+ users/follows für Handle/Scope) — kein Recompute, später cachebar.
 */
final class SegmentSpeedService
{
    /** @var list<string> */
    public const SCOPES  = ['world', 'friends'];
    /** @var list<string> */
    public const WINDOWS = ['week', 'season', 'all'];

    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameConfig $config,
    ) {}

    /**
     * Tempo-Rangliste einer Kante: Bestzeit pro Fahrer, aufsteigend.
     *
     * @return array{
     *   segment: array{edge_id:int,length_m:float,surface:?string},
     *   entries: list<array{rank:int,handle:?string,duration_s:float,avg_speed_kmh:float,achieved_at:string,is_me:bool}>,
     *   me: array{rank:int,duration_s:float}|null
     * }|null  null ⇒ Kante existiert nicht (404)
     */
    public function leaderboard(int $edgeId, string $scope, string $window, ?int $userId, ?DateTimeImmutable $now = null): ?array
    {
        $edge = $this->repo->edgeById($edgeId);
        if ($edge === null) {
            return null;
        }
        $scope  = in_array($scope, self::SCOPES, true) ? $scope : 'world';
        $window = in_array($window, self::WINDOWS, true) ? $window : 'season';
        $now ??= Clock::nowUtc();

        $since = $this->windowSince($window, $now);
        $rows  = $this->repo->bestEffortsForEdge($edgeId, $since);

        // scope=friends: auf gefolgte Fahrer + sich selbst eingrenzen.
        if ($scope === 'friends') {
            if ($userId === null) {
                $rows = [];
            } else {
                $allowed = array_fill_keys($this->repo->followeeIds($userId), true);
                $allowed[$userId] = true;
                $rows = array_values(array_filter(
                    $rows,
                    static fn (array $r): bool => isset($allowed[$r['user_id']]),
                ));
            }
        }

        usort($rows, static fn (array $a, array $b): int =>
            ($a['duration_s'] <=> $b['duration_s'])
            ?: (strcmp($a['achieved_at'], $b['achieved_at']))
            ?: ($a['user_id'] <=> $b['user_id']));

        // Eigener Rang/Bestzeit — auch außerhalb der Top-N.
        $me = null;
        if ($userId !== null) {
            foreach ($rows as $i => $r) {
                if ($r['user_id'] === $userId) {
                    $me = ['rank' => $i + 1, 'duration_s' => round($r['duration_s'], 2)];
                    break;
                }
            }
        }

        $topN     = max(1, $this->config->int('segment_leaderboard_top_n'));
        $topRows  = array_slice($rows, 0, $topN);
        $handles  = $this->repo->handlesFor(array_map(static fn (array $r): int => $r['user_id'], $topRows));

        $entries = [];
        foreach ($topRows as $i => $r) {
            $entries[] = [
                'rank'          => $i + 1,
                'handle'        => $handles[$r['user_id']] ?? null,
                'duration_s'    => round($r['duration_s'], 2),
                'avg_speed_kmh' => round($r['avg_speed_kmh'], 2),
                'achieved_at'   => $this->iso($r['achieved_at']),
                'is_me'         => $userId !== null && $r['user_id'] === $userId,
            ];
        }

        return [
            'segment' => [
                'edge_id'  => (int)$edge['id'],
                'length_m' => (float)$edge['length_m'],
                'surface'  => $edge['surface_character'] !== null ? (string)$edge['surface_character'] : null,
            ],
            'entries' => $entries,
            'me'      => $me,
        ];
    }

    /**
     * Persönliche Bestzeiten des Fahrers über alle Segmente, mit Rang/Teilnehmer.
     *
     * @return array{
     *   segments: list<array{edge_id:int,length_m:float,surface:?string,best_duration_s:float,best_avg_speed_kmh:float,achieved_at:string,rank:int,total_riders:int}>,
     *   pagination: array{limit:int,offset:int,total:int,has_more:bool}
     * }
     */
    public function mySegments(int $userId, string $window, int $limit, int $offset, ?DateTimeImmutable $now = null): array
    {
        $window = in_array($window, self::WINDOWS, true) ? $window : 'season';
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $now ??= Clock::nowUtc();
        $since  = $this->windowSince($window, $now);

        $bests = $this->repo->userSegmentBests($userId, $since, $limit, $offset);
        $total = $this->repo->countUserSegments($userId, $since);

        $edgeIds = array_map(static fn (array $b): int => $b['edge_id'], $bests);
        $byEdge  = $this->repo->bestEffortsForEdges($edgeIds, $since);

        $segments = [];
        foreach ($bests as $b) {
            $field = $byEdge[$b['edge_id']] ?? [];
            $mine  = $b['best_duration_s'];
            $faster = 0;
            foreach ($field as $row) {
                if ($row['duration_s'] < $mine) {
                    $faster++;
                }
            }
            $segments[] = [
                'edge_id'            => $b['edge_id'],
                'length_m'           => $b['length_m'],
                'surface'            => $b['surface'],
                'best_duration_s'    => round($b['best_duration_s'], 2),
                'best_avg_speed_kmh' => round($b['best_avg_speed_kmh'], 2),
                'achieved_at'        => $this->iso($b['achieved_at']),
                'rank'               => $faster + 1,
                'total_riders'       => count($field),
            ];
        }

        return [
            'segments'   => $segments,
            'pagination' => [
                'limit'    => $limit,
                'offset'   => $offset,
                'total'    => $total,
                'has_more' => ($offset + $limit) < $total,
            ],
        ];
    }

    /** Startdatum (Y-m-d) des Fensters: week = 7 T, season = presence_window_days, all = null. */
    private function windowSince(string $window, DateTimeImmutable $now): ?string
    {
        if ($window === 'week') {
            return $now->modify('-7 days')->format('Y-m-d');
        }
        if ($window === 'season') {
            $days = max(1, $this->config->int('presence_window_days'));
            return $now->modify("-{$days} days")->format('Y-m-d');
        }
        return null; // all
    }

    private function iso(string $mysqlDatetime): string
    {
        return str_replace(' ', 'T', $mysqlDatetime) . 'Z';
    }
}
