<?php
declare(strict_types=1);

namespace App\Game;

use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Baut die JSON-Strukturen der /game-Lesepfade (Spec §6) und rechnet die
 * Frische beim Lesen mit "jetzt" nach (Spec §7), damit lange ungenutzte
 * Kanten nicht zu frisch erscheinen.
 */
final class GameReadService
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameConfig $config,
        private readonly ?EdgeRecordService $records = null,
    ) {}

    /**
     * @param string $bbox "minLon,minLat,maxLon,maxLat"
     * @return list<array<string,mixed>>
     */
    public function edgesInBbox(string $bbox, ?int $mineClaimantId, ?DateTimeImmutable $now, int $limit = 500): array
    {
        $now ??= Clock::nowUtc();
        [$minLon, $minLat, $maxLon, $maxLat] = $this->parseBbox($bbox);
        $rows = $this->repo->edgesInBbox($minLon, $minLat, $maxLon, $maxLat, null, $limit);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->formatEdge($row, $mineClaimantId, $now);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function edgeDetail(int $edgeId, ?int $viewerClaimantId, ?DateTimeImmutable $now): ?array
    {
        $now ??= Clock::nowUtc();
        $row = $this->repo->edgeById($edgeId);
        if ($row === null) {
            return null;
        }
        $base = $this->formatEdge($row, $viewerClaimantId, $now);
        $breakdown = $this->valueBreakdown($row, $now);

        $cohort = [];
        $rank = 1;
        foreach ($this->repo->firstPassPerUser($edgeId, 10) as $c) {
            $cohort[] = [
                'rank'            => $rank++,
                'handle'          => $c['handle'],
                'first_ridden_at' => Clock::toIso8601(substr($c['first_ridden_at'], 0, 19)),
            ];
        }

        return [
            'id'                    => (int)$row['id'],
            'owner'                 => $base['owner'],
            'owner_is_me'           => $base['owner_is_me'],
            'value'                 => $breakdown,
            'distinct_riders_total' => (int)$row['distinct_riders_total'],
            'pioneer_cohort'        => $cohort,
            'freshness'             => $base['freshness'],
            'traffic_factor'        => $base['traffic_factor'],
            'traffic_class'         => $base['traffic_class'],
            'geom'                  => $base['geom'],
            'fastest'               => (object)($this->records !== null ? $this->records->fastestByClass($edgeId) : []),
        ];
    }

    /** @return array<string,mixed> */
    public function me(int $claimantId, ?int $userId = null): array
    {
        $s = $this->repo->meStats($claimantId);
        $recordsHeld = ($userId !== null && $this->records !== null)
            ? $this->records->recordsHeld($userId)
            : 0;
        return [
            'held_edges'      => $s['held'],
            'pioneered_edges' => $s['pioneered'],
            'held_length_m'   => $s['held_length_m'],
            'records_held'    => $recordsHeld,
        ];
    }

    /** @return array<string,mixed> */
    private function formatEdge(array $row, ?int $viewerClaimantId, DateTimeImmutable $now): array
    {
        $ownerId = $row['owner_claimant_id'] !== null ? (int)$row['owner_claimant_id'] : null;
        $owner = null;
        if ($ownerId !== null) {
            $info = $this->repo->claimantInfo($ownerId);
            if ($info !== null) {
                $owner = $info;
            }
        }
        $observations  = (int)($row['traffic_observations'] ?? 0);
        $trafficFactor = isset($row['traffic_factor_cached']) ? (float)$row['traffic_factor_cached'] : 1.0;

        return [
            'id'                    => (int)$row['id'],
            'geom'                  => json_decode((string)$row['geom_geojson'], true),
            'owner'                 => $owner,
            'owner_is_me'           => $ownerId !== null && $ownerId === $viewerClaimantId,
            'value'                 => (float)$row['value_cached'],
            'freshness'             => $this->freshnessNow($row, $now),
            'distinct_riders_total' => (int)$row['distinct_riders_total'],
            'surface_character'     => $row['surface_character'] !== null ? (string)$row['surface_character'] : null,
            // Radar-Verkehr (additiv): Faktor + grobe Einstufung. Ohne Daten
            // → factor 1.0 / class "unknown" (bricht den iOS-Decoder nicht).
            'traffic_factor'        => round($trafficFactor, 3),
            'traffic_class'         => self::trafficClass($observations, $trafficFactor),
        ];
    }

    /**
     * Grobe Verkehrs-Einstufung für die App-Anzeige. Ohne Beobachtungen
     * "unknown"; sonst aus dem Faktor abgeleitet (>1 = leiser/bonus,
     * <1 = mehr Verkehr/malus).
     */
    private static function trafficClass(int $observations, float $factor): string
    {
        if ($observations <= 0) {
            return 'unknown';
        }
        if ($factor >= 1.05) {
            return 'quiet';
        }
        if ($factor <= 0.95) {
            return 'busy';
        }
        return 'moderate';
    }

    private function freshnessNow(array $row, DateTimeImmutable $now): float
    {
        if ($row['last_pass_at'] === null) {
            return 0.0;
        }
        $dt = new DateTimeImmutable((string)$row['last_pass_at'], new DateTimeZone('UTC'));
        $ageDays = ($now->getTimestamp() - $dt->getTimestamp()) / 86400.0;
        // min(1.0, …): future-dated last_pass (Clock-Skew) → ageDays < 0 →
        // presenceWeight > 1.0. Freshness ∈ [0,1] kappen (kosmetisch, iOS #3).
        return min(1.0, GameMath::presenceWeight($ageDays, $this->config->int('presence_window_days')));
    }

    /** @return array{total:float,pioneer:float,popularity:float,curation:float} */
    private function valueBreakdown(array $row, DateTimeImmutable $now): array
    {
        $edgeId = (int)$row['id'];
        $n = (int)$row['distinct_riders_total'];
        $windowDays = $this->config->int('presence_window_days');
        $sinceDate = $now->modify("-{$windowDays} days")->format('Y-m-d');
        $n90 = $this->repo->distinctRidersSince($edgeId, $sinceDate);

        $pioneer = GameMath::pioneer($n, $this->config->float('pioneer_p0'),
            $this->config->float('pioneer_k'), $this->config->float('pioneer_s'));
        $popularity = GameMath::popularity($n90, $this->config->float('popularity_c'));
        $curation = 0.0; // Stufe 1
        return [
            'total'      => GameMath::combineValue($pioneer, $popularity, $curation),
            'pioneer'    => $pioneer,
            'popularity' => $popularity,
            'curation'   => $curation,
        ];
    }

    /** @return array{0:float,1:float,2:float,3:float} [minLon,minLat,maxLon,maxLat] */
    private function parseBbox(string $bbox): array
    {
        $parts = array_map('floatval', explode(',', $bbox));
        if (count($parts) !== 4) {
            throw new \InvalidArgumentException('bbox erwartet minLon,minLat,maxLon,maxLat');
        }
        return [$parts[0], $parts[1], $parts[2], $parts[3]];
    }
}
