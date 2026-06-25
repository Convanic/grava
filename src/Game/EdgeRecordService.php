<?php
declare(strict_types=1);

namespace App\Game;

use App\Support\Clock;
use DateTimeImmutable;

/**
 * Segment-Bestzeiten auf game_edge_pass (GAME_SEGMENT_SPEED_BACKEND.md 2026-06-24):
 * Pro-Kante-Rekorde, Crowns (records_held) und fastest-Block — reine Lese-Aggregation.
 *
 * Crowns sind all-time je (edge_id, bike_class); window filtert nur die
 * qualifizierenden Pässe beim Lesen (Spec §4.2).
 */
final class EdgeRecordService
{
    /** @var list<string> */
    public const WINDOWS = ['all', 'season', '90d'];

    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameConfig $config,
    ) {}

    /**
     * @return array{
     *   edge_id:int,bike:string,window:string,
     *   records:list<array{rank:int,handle:?string,avg_speed_kmh:float,duration_ms:int,ridden_at:string,is_me?:bool}>,
     *   me?:array{rank:int,avg_speed_kmh:float,duration_ms:int}
     * }|null null ⇒ Kante existiert nicht
     */
    public function records(
        int $edgeId,
        string $bike,
        string $window,
        ?int $userId,
        ?DateTimeImmutable $now = null,
        ?int $limit = null,
    ): ?array {
        if ($this->repo->edgeById($edgeId) === null) {
            return null;
        }
        $bike = BikeClass::parseQuery($bike);
        $window = in_array($window, self::WINDOWS, true) ? $window : 'all';
        $now ??= Clock::nowUtc();
        $since = $this->windowSince($window, $now);
        $limit ??= max(1, $this->config->int('edge_records_list_limit'));

        $rows = $this->repo->bestRecordPassesForEdge($edgeId, $bike, $since);
        usort($rows, static fn (array $a, array $b): int =>
            ($b['avg_speed_kmh'] <=> $a['avg_speed_kmh'])
            ?: ($a['duration_ms'] <=> $b['duration_ms'])
            ?: ($a['user_id'] <=> $b['user_id']));

        $meRank = null;
        $meRow = null;
        if ($userId !== null) {
            foreach ($rows as $i => $r) {
                if ($r['user_id'] === $userId) {
                    $meRank = $i + 1;
                    $meRow = $r;
                    break;
                }
            }
        }

        $topRows = array_slice($rows, 0, $limit);
        $handles = $this->repo->handlesFor(array_map(static fn (array $r): int => $r['user_id'], $topRows));

        $records = [];
        foreach ($topRows as $i => $r) {
            $entry = [
                'rank'          => $i + 1,
                'handle'        => $handles[$r['user_id']] ?? null,
                'avg_speed_kmh' => round($r['avg_speed_kmh'], 2),
                'duration_ms'   => (int)$r['duration_ms'],
                'ridden_at'     => $this->iso($r['ridden_at']),
            ];
            if ($userId !== null) {
                $entry['is_me'] = $r['user_id'] === $userId;
            }
            $records[] = $entry;
        }

        $out = [
            'edge_id' => $edgeId,
            'bike'    => $bike,
            'window'  => $window,
            'records' => $records,
        ];
        if ($userId !== null && $meRow !== null) {
            $out['me'] = [
                'rank'          => $meRank,
                'avg_speed_kmh' => round($meRow['avg_speed_kmh'], 2),
                'duration_ms'   => (int)$meRow['duration_ms'],
            ];
        }
        return $out;
    }

    /** Anzahl gehaltener Crowns (Rang 1 je edge_id + bike_class). */
    public function recordsHeld(int $userId, ?string $sinceDate = null): int
    {
        return $this->repo->countCrownsForUser($userId, $sinceDate);
    }

    /** @return array<int,int> user_id => crown count */
    public function crownsByUser(?string $sinceDate): array
    {
        return $this->repo->crownsByUser($sinceDate);
    }

    /**
     * Kurzanzeige je Fahrrad-Klasse für GET /game/edges/{id}.
     *
     * @return array<string,array{handle:?string,avg_speed_kmh:float,bike:string}>
     */
    public function fastestByClass(int $edgeId): array
    {
        $out = [];
        foreach (BikeClass::ALLOWED as $bike) {
            $holder = $this->repo->fastestRecordHolder($edgeId, $bike, null);
            if ($holder === null) {
                continue;
            }
            $out[$bike] = [
                'handle'        => $holder['handle'],
                'avg_speed_kmh' => round($holder['avg_speed_kmh'], 2),
                'bike'          => $bike,
            ];
        }
        return $out;
    }

    private function windowSince(string $window, DateTimeImmutable $now): ?string
    {
        if ($window === '90d') {
            return $now->modify('-90 days')->format('Y-m-d');
        }
        if ($window === 'season') {
            $days = max(1, $this->config->int('presence_window_days'));
            return $now->modify("-{$days} days")->format('Y-m-d');
        }
        return null;
    }

    private function iso(string $mysqlDatetime): string
    {
        return str_replace(' ', 'T', substr($mysqlDatetime, 0, 19)) . 'Z';
    }
}
