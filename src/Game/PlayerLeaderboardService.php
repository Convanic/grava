<?php
declare(strict_types=1);

namespace App\Game;

use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Solo-/Spieler-Rangliste (S7, siehe backend/PLAYER_LEADERBOARD_BACKEND.md):
 * rein lesende Aggregation pro EINZELNEM Fahrer über world/friends, mit
 * umschaltbarem Zeitfenster und Kennzahl. Kein Recompute, später cachebar.
 *
 * area/value sind präsenzbasiert ⇒ modellbedingt 90-Tage-rollierend; window=all
 * wird dafür wie season behandelt (dokumentiert in der Spec). week begrenzt die
 * zugrunde liegenden Pässe/Erstbefahrungen/Distanzen auf 7 Tage.
 */
final class PlayerLeaderboardService
{
    private const TOP_N = 100;
    private const PIONEER_COHORT = 10;

    /** @var list<string> */
    public const SCOPES  = ['world', 'friends'];
    /** @var list<string> */
    public const WINDOWS = ['week', 'season', 'all'];
    /** @var list<string> */
    public const METRICS = ['area', 'pioneer', 'value', 'distance', 'records'];

    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameConfig $config,
    ) {}

    /**
     * @return array{
     *   entries: list<array{rank:int,handle:?string,value:float|int,is_me:bool}>,
     *   me: array{rank:?int,value:float|int|null}|null
     * }
     */
    public function leaderboard(
        string $scope,
        string $window,
        string $metric,
        ?int $userId,
        ?DateTimeImmutable $now = null,
    ): array {
        $scope  = in_array($scope, self::SCOPES, true) ? $scope : 'world';
        $window = in_array($window, self::WINDOWS, true) ? $window : 'season';
        $metric = in_array($metric, self::METRICS, true) ? $metric : 'area';
        $now ??= Clock::nowUtc();

        $presenceWindow = $this->config->int('presence_window_days');
        $isPioneerCount = $metric === 'pioneer';
        $isRecordsCount = $metric === 'records';

        // Pro-Fahrer-Kennzahl bestimmen.
        $valueByUser = match ($metric) {
            'area'     => $this->heldByUser($this->windowSince($window, $presenceWindow, $now, true), $now, 'length'),
            'value'    => $this->heldByUser($this->windowSince($window, $presenceWindow, $now, true), $now, 'value'),
            'distance' => $this->repo->distanceByUserSince($this->windowSince($window, $presenceWindow, $now, false)),
            'pioneer'  => $this->intToFloatMap(
                $this->repo->pioneerCountByUserSince($this->windowSince($window, $presenceWindow, $now, false), self::PIONEER_COHORT),
            ),
            'records'  => $this->intToFloatMap(
                $this->repo->crownsByUser($this->recordsWindowSince($window, $presenceWindow, $now)),
            ),
            default    => [],
        };

        // scope=friends: auf gefolgte Fahrer + sich selbst eingrenzen.
        if ($scope === 'friends') {
            if ($userId === null) {
                $valueByUser = [];
            } else {
                $allowed = array_fill_keys($this->repo->followeeIds($userId), true);
                $allowed[$userId] = true;
                $valueByUser = array_intersect_key($valueByUser, $allowed);
            }
        }

        // Nur positive Werte ranken; deterministische Sortierung.
        $rows = [];
        foreach ($valueByUser as $uid => $v) {
            if ($v > 0) {
                $rows[] = ['user_id' => (int)$uid, 'value' => (float)$v];
            }
        }
        usort($rows, static fn (array $a, array $b): int =>
            ($b['value'] <=> $a['value']) ?: ($a['user_id'] <=> $b['user_id']));

        // Eigener Rang/Wert — auch außerhalb der Top-N.
        $meRank = null;
        $meValue = null;
        if ($userId !== null) {
            foreach ($rows as $i => $r) {
                if ($r['user_id'] === $userId) {
                    $meRank  = $i + 1;
                    $meValue = $r['value'];
                    break;
                }
            }
        }

        $topRows  = array_slice($rows, 0, self::TOP_N);
        $handles  = $this->repo->handlesFor(array_map(static fn (array $r): int => $r['user_id'], $topRows));

        $entries = [];
        foreach ($topRows as $i => $r) {
            $entries[] = [
                'rank'   => $i + 1,
                'handle' => $handles[$r['user_id']] ?? null,
                'value'  => $this->formatValue($r['value'], $isPioneerCount || $isRecordsCount),
                'is_me'  => $userId !== null && $r['user_id'] === $userId,
            ];
        }

        $me = null;
        if ($userId !== null && $meRank !== null) {
            $me = ['rank' => $meRank, 'value' => $this->formatValue((float)$meValue, $isPioneerCount || $isRecordsCount)];
        }

        return ['entries' => $entries, 'me' => $me];
    }

    /**
     * Pro-Fahrer gehaltenes Gebiet/Wert: je Kante der größte 90-Tage-Präsenz-
     * Beitragende (Tie → kleinste user_id) „hält" die Kante; summiere Länge bzw.
     * Wert beim haltenden Fahrer.
     *
     * @param 'length'|'value' $field
     * @return array<int,float> user_id => Summe
     */
    private function heldByUser(string $since, DateTimeImmutable $now, string $field): array
    {
        $window   = $this->config->int('presence_window_days');
        $passes   = $this->repo->passesWithEdgeSince($since);
        $perEdge  = [];  // [edge_id][user_id] => gewichtete Präsenz
        $edgeMeta = [];  // [edge_id] => ['length'=>..,'value'=>..]
        foreach ($passes as $p) {
            $w = GameMath::presenceWeight($this->ageDays($p['ridden_at'], $now), $window);
            $perEdge[$p['edge_id']][$p['user_id']] = ($perEdge[$p['edge_id']][$p['user_id']] ?? 0.0) + $w;
            $edgeMeta[$p['edge_id']] = ['length' => $p['length_m'], 'value' => $p['value']];
        }

        $out = [];
        foreach ($perEdge as $eid => $byUser) {
            ksort($byUser); // kleinste user_id zuerst => deterministischer Tie-Break
            $topUser = null;
            $topW = -1.0;
            foreach ($byUser as $uid => $w) {
                if ($w > $topW) {
                    $topW = $w;
                    $topUser = (int)$uid;
                }
            }
            if ($topUser !== null) {
                $out[$topUser] = ($out[$topUser] ?? 0.0) + $edgeMeta[$eid][$field];
            }
        }
        return $out;
    }

    /** Fenster für metric=records: week/season/all wie Spec §4.2 (Pässe filtern). */
    private function recordsWindowSince(string $window, int $presenceWindow, DateTimeImmutable $now): ?string
    {
        if ($window === 'week') {
            return $now->modify('-7 days')->format('Y-m-d');
        }
        if ($window === 'season') {
            return $now->modify("-{$presenceWindow} days")->format('Y-m-d');
        }
        return null;
    }

    /**
     * (area/value, $presenceBound=true) ist season/all = 90 Tage; week = 7.
     * Für distance/pioneer ($presenceBound=false) ist all = null (kein Limit).
     */
    private function windowSince(string $window, int $presenceWindow, DateTimeImmutable $now, bool $presenceBound): ?string
    {
        if ($window === 'week') {
            return $now->modify('-7 days')->format('Y-m-d');
        }
        if ($window === 'season') {
            return $now->modify("-{$presenceWindow} days")->format('Y-m-d');
        }
        // all
        return $presenceBound
            ? $now->modify("-{$presenceWindow} days")->format('Y-m-d')
            : null;
    }

    /** @param array<int,int> $m @return array<int,float> */
    private function intToFloatMap(array $m): array
    {
        $out = [];
        foreach ($m as $k => $v) {
            $out[$k] = (float)$v;
        }
        return $out;
    }

    private function formatValue(float $value, bool $isCount): float|int
    {
        return $isCount ? (int)round($value) : round($value, 2);
    }

    private function ageDays(string $mysqlDatetime, DateTimeImmutable $now): float
    {
        $dt = new DateTimeImmutable($mysqlDatetime, new DateTimeZone('UTC'));
        return max(0.0, ($now->getTimestamp() - $dt->getTimestamp()) / 86400.0);
    }
}
