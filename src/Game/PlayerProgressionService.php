<?php
declare(strict_types=1);

namespace App\Game;

/**
 * Ränge & Abzeichen (RankBadges_Concept.md) — DB-/Persistenz-Seite zur reinen
 * Logik in {@see BadgeMath}.
 *
 * Berechnet AP (nur monotone Größen, §13.4), den Rang (AP + Abzeichen-Gate ab
 * R6, §13.2) und materialisiert neu erreichte Abzeichen-Stufen. Die Persistenz
 * (eine Zeile je erreichter Stufe, nie gelöscht) bildet die „Höchststand/
 * unverlierbar"-Regel automatisch ab: fällt ein Live-Wert (z. B. Revierlänge)
 * wieder, bleibt die einmal verdiente Stufe bestehen.
 *
 * Wird aus dem Lesepfad GET /game/me aufgerufen → lazy-Materialisierung.
 * Schwellen/Gewichte sind Server-Config (GameConfig, RankBadges §8).
 */
final class PlayerProgressionService
{
    private const TIER_NAMES = ['bronze', 'silber', 'gold', 'platin', 'onyx'];

    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameConfig $config,
    ) {}

    /**
     * @return array{ap_total:int,rank:int,next_rank:?array<string,int>,badges:list<array<string,mixed>>}
     */
    public function forMe(
        int $userId,
        int $pioneeredEdges,
        float $heldLengthM,
        int $longestStreakWeeks,
        int $recordsHeld,
    ): array {
        $weights = $this->json('progression_ap_weights');
        $rankAp  = array_values($this->json('progression_rank_ap'));
        $catalog = $this->json('progression_catalog');
        $gate    = $this->json('progression_rank_gate');

        $distanceKm = $this->repo->totalDistanceM($userId) / 1000.0;
        $takeovers  = $this->repo->takeoverCount($userId);

        // AP — bewusst nur monotone Größen (pioniert/übernommen/Distanz/längste
        // Serie). Gehaltene Länge & Records können fallen → nur Abzeichen.
        $ap = (int)round(
            $pioneeredEdges       * (float)($weights['pioneer'] ?? 0)
            + $takeovers          * (float)($weights['takeover'] ?? 0)
            + floor($distanceKm)  * (float)($weights['km'] ?? 0)
            + $longestStreakWeeks * (float)($weights['streak_week'] ?? 0)
        );

        // Aktuelle Messwerte je Familie (revierhalter/kondition in km).
        $values = [
            'erschliesser' => (float)$pioneeredEdges,
            'revierhalter' => $heldLengthM / 1000.0,
            'kondition'    => $distanceKm,
            'stammfahrer'  => (float)$longestStreakWeeks,
            'schnellster'  => (float)$recordsHeld,
        ];

        // Neu erreichte Stufen materialisieren (idempotent; Peak bleibt erhalten).
        foreach ($catalog as $family => $def) {
            $tiers = $def['tiers'] ?? [];
            $value = $values[(string)$family] ?? 0.0;
            $tier  = BadgeMath::tierForValue($value, $tiers);
            for ($t = 0; $t <= $tier; $t++) {
                $this->repo->insertBadgeTier($userId, (string)$family, $t, $value);
            }
        }

        // Verdiente Stufen (Peak) laden → höchste Stufe je Familie.
        $maxTier = [];
        foreach ($this->repo->earnedBadges($userId) as $b) {
            $f = $b['family'];
            $maxTier[$f] = max($maxTier[$f] ?? -1, $b['tier']);
        }

        // Gold-/Onyx-/Kern-Zählung fürs Rang-Gate (§13.2).
        $gold = $onyx = $coreGold = $coreCount = 0;
        foreach ($catalog as $family => $def) {
            $isCore = !empty($def['core']);
            if ($isCore) {
                $coreCount++;
            }
            $mt = $maxTier[(string)$family] ?? -1;
            if ($mt >= BadgeMath::TIER_GOLD) {
                $gold++;
                if ($isCore) {
                    $coreGold++;
                }
            }
            if ($mt >= BadgeMath::TIER_ONYX) {
                $onyx++;
            }
        }

        $apRank = BadgeMath::rankForAp($ap, $rankAp);
        $rank   = BadgeMath::finalRank($apRank, $gate, $gold, $onyx, $coreGold, $coreCount);

        // Nächster Rang (AP-bezogen; ein evtl. Gate macht die App sichtbar).
        $nextRank = null;
        if ($rank < count($rankAp)) {
            $needAp = (int)$rankAp[$rank]; // Index $rank = Schwelle des nächsten Rangs
            $nextRank = [
                'rank'         => $rank + 1,
                'ap'           => $needAp,
                'ap_remaining' => max(0, $needAp - $ap),
            ];
        }

        // Abzeichen-Liste: nur Familien mit ≥ Bronze, inkl. Fortschritt.
        $badges = [];
        foreach ($catalog as $family => $def) {
            $mt = $maxTier[(string)$family] ?? -1;
            if ($mt < 0) {
                continue;
            }
            $tiers = $def['tiers'] ?? [];
            $next = null;
            if (($mt + 1) < count($tiers)) {
                $next = ['tier' => $mt + 1, 'threshold' => (float)$tiers[$mt + 1]];
            }
            $badges[] = [
                'family'    => (string)$family,
                'tier'      => $mt,
                'tier_name' => self::TIER_NAMES[$mt] ?? (string)$mt,
                'value'     => round($values[(string)$family] ?? 0.0, 1),
                'next_tier' => $next,
            ];
        }

        return [
            'ap_total'  => $ap,
            'rank'      => $rank,
            'next_rank' => $nextRank,
            'badges'    => $badges,
        ];
    }

    /** @return array<mixed> Dekodierter JSON-Config-Wert (leeres Array bei Fehler). */
    private function json(string $key): array
    {
        $decoded = json_decode($this->config->string($key), true);
        return is_array($decoded) ? $decoded : [];
    }
}
