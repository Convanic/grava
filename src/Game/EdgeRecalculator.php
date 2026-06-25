<?php
declare(strict_types=1);

namespace App\Game;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Rechnet die zwischengespeicherten Live-Werte EINER Kante aus den Pässen
 * neu (Spec §5). Genutzt vom Live-Pfad (Ingestion) UND vom vollen
 * Recompute → garantiert identische Ergebnisse (§10.5).
 *
 * Liest ausschliesslich game_edge_pass + game_edge; schreibt nur die
 * *_cached-Felder + owner/owner_since/discovery via GameRepository.
 */
final class EdgeRecalculator
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameConfig $config,
    ) {}

    public function recalculate(int $edgeId, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $edge = $this->repo->edgeById($edgeId);
        if ($edge === null) {
            return;
        }

        $windowDays = $this->config->int('presence_window_days');
        $passes = $this->repo->passesForEdge($edgeId);

        // Stufe 2: effektiver Claimant je user_id (Crew-Group-Claimant, sonst Rider).
        // Besitz wird nach dem effektiven Claimant gruppiert — nicht nach dem
        // (historisch gestempelten) game_edge_pass.claimant_id.
        $userIds = [];
        foreach ($passes as $p) {
            $userIds[] = $p['user_id'];
        }
        $effMap = $this->repo->effectiveClaimantMap($userIds);

        $bonus      = $this->config->float('group_ride_bonus');
        $minMembers = $this->config->int('group_ride_min_members');

        // Rush (§3): qualifizierte, getaggte Pässe bekommen statt des
        // Gruppenfahrt-Bonus einen Multiplikator. Welche rush_id auf DIESER
        // Kante "zieht", wird einmal vorab bestimmt (Qualifikation + Edge-Cap).
        [$rushApplies, $rushMult, $rushStacks] = $this->resolveRush($edgeId, $passes);

        // Tages-Aggregation je (effektiver Claimant, ridden_on): regulärer und
        // gerushter Gewicht-Anteil getrennt, plus distinct Mitglieder.
        $dayNonRush = [];   // [cid][ridden_on] => float (regulärer Anteil)
        $dayRush    = [];   // [cid][ridden_on][rush_id] => float (gerushter Anteil)
        $dayMembers = [];   // [cid][ridden_on] => array<int,true>
        $isGroup    = [];   // [cid] => bool
        $lastPassByClaimant = [];
        $lastPassOverall = null;
        // Entdecker (Pionier) = effektiver Claimant des Users mit dem
        // FRÜHESTEN Pass. Wichtig: über den effektiven Claimant (Crew), nicht
        // den historisch gestempelten game_edge_pass.claimant_id — sonst zählt
        // meStats() für eine Crew 0 pionierte Kanten, obwohl ihre Mitglieder
        // die Kanten entdeckt haben (gleiche Logik wie beim Besitz).
        $discovererClaimant = null;
        $firstPassAt = null;
        $firstUser = null;
        foreach ($passes as $p) {
            $uid = $p['user_id'];
            $eff = $effMap[$uid] ?? ['claimant_id' => $p['claimant_id'], 'is_group' => false];
            $cid = $eff['claimant_id'];
            $isGroup[$cid] = $eff['is_group'];
            $on = $p['ridden_on'];
            $w = GameMath::presenceWeight($this->ageDays($p['ridden_at'], $now), $windowDays);
            $dayMembers[$cid][$on][$uid] = true;
            $rid = $p['rush_id'];
            if ($rid !== null && ($rushApplies[$rid] ?? false)) {
                $dayRush[$cid][$on][$rid] = ($dayRush[$cid][$on][$rid] ?? 0.0) + $w;
            } else {
                $dayNonRush[$cid][$on] = ($dayNonRush[$cid][$on] ?? 0.0) + $w;
            }
            if (!isset($lastPassByClaimant[$cid]) || $p['ridden_at'] > $lastPassByClaimant[$cid]) {
                $lastPassByClaimant[$cid] = $p['ridden_at'];
            }
            if ($lastPassOverall === null || $p['ridden_at'] > $lastPassOverall) {
                $lastPassOverall = $p['ridden_at'];
            }
            // frühester Pass (Tie-Break: kleinste user_id) → konsistent mit
            // discovered_at = MIN(ridden_at) aus refreshEdgeDiscovery.
            if ($firstPassAt === null
                || $p['ridden_at'] < $firstPassAt
                || ($p['ridden_at'] === $firstPassAt && $uid < $firstUser)
            ) {
                $firstPassAt = $p['ridden_at'];
                $firstUser = $uid;
                $discovererClaimant = $cid;
            }
        }

        // Präsenz je Claimant. Ohne gerushte Pässe reduziert sich der Term exakt
        // auf die Stufe-1/2-Formel (Gruppenfahrt-Bonus als Tagesfaktor) →
        // bit-identisch (§9.7). Gerushte Pässe ersetzen den Bonus durch den
        // Multiplikator (rush_stacks_with_group_bonus=false) bzw. stapeln ihn.
        $presence = [];
        $cids = array_keys($dayNonRush + $dayRush);
        foreach ($cids as $cid) {
            $sum  = 0.0;
            $days = array_keys(($dayNonRush[$cid] ?? []) + ($dayRush[$cid] ?? []));
            foreach ($days as $on) {
                $members = count($dayMembers[$cid][$on] ?? []);
                $g = (($isGroup[$cid] ?? false) && $bonus !== 1.0 && $members >= $minMembers) ? $bonus : 1.0;
                $sum += $g * ($dayNonRush[$cid][$on] ?? 0.0);
                foreach (($dayRush[$cid][$on] ?? []) as $rid => $rw) {
                    $m = $rushMult[$rid] ?? 1.0;
                    $sum += ($rushStacks ? $m * $g : $m) * $rw;
                }
            }
            $presence[(int)$cid] = $sum;
        }

        $challenger = null;
        $challengerPresence = 0.0;
        ksort($presence);
        foreach ($presence as $cid => $pres) {
            if ($challenger === null || $pres > $challengerPresence) {
                $challenger = (int)$cid;
                $challengerPresence = $pres;
            }
        }

        $currentOwner = $edge['owner_claimant_id'] !== null ? (int)$edge['owner_claimant_id'] : null;

        $newOwner = null;
        $ownerSince = null;
        $vulnerability = 0.0;
        if ($challenger !== null) {
            $currentPresence = $currentOwner !== null ? ($presence[$currentOwner] ?? 0.0) : 0.0;
            // Übernahme weiter ausschließlich über Hysterese (§9.4, kein
            // Sofort-Flip). rush_hysteresis_factor (falls gesetzt) erlaubt einen
            // eigenen Faktor für rush-getriebene Übernahmen; sonst erbt der Rush
            // die STAGE1-Hysterese.
            $hysteresis = $this->config->floatOrNull('rush_hysteresis_factor')
                ?? $this->config->float('hysteresis_factor');
            $newOwner = GameMath::decideOwner(
                $currentOwner,
                $currentPresence,
                $challenger,
                $challengerPresence,
                $hysteresis,
            );
            if ($newOwner !== $currentOwner) {
                $ownerSince = $now->format('Y-m-d H:i:s.v');
            }
            $vulnerability = $this->vulnerability($presence, $newOwner, $hysteresis);
        }

        $n = $this->repo->distinctRidersTotal($edgeId);
        $sinceDate = $now->modify("-{$windowDays} days")->format('Y-m-d');
        $n90 = $this->repo->distinctRidersSince($edgeId, $sinceDate);
        $pioneer = GameMath::pioneer(
            $n,
            $this->config->float('pioneer_p0'),
            $this->config->float('pioneer_k'),
            $this->config->float('pioneer_s'),
        );
        $popularity = GameMath::popularity($n90, $this->config->float('popularity_c'));
        $value = GameMath::combineValue($pioneer, $popularity, 0.0);

        // Radar-Verkehr (RADAR_TRAFFIC_BACKEND.md §B3): Faktor f_eff aus den
        // map-gematchten Vorbeifahrten. Keine Daten → 1.0 (neutral). Der
        // Faktor multipliziert den Wert am Ende; die Stufe-1-Kombination
        // bleibt unverändert.
        $traffic = $this->repo->trafficAggregateForEdge($edgeId);
        $trafficFactor = GameMath::trafficFactor(
            $traffic['pass_count'],
            $traffic['observations'],
            (float)$edge['length_m'],
            $this->config->float('traffic_t0'),
            $this->config->float('traffic_k'),
            $this->config->float('traffic_f_min'),
            $this->config->float('traffic_f_max'),
            $this->config->int('traffic_n_prior'),
        );
        $value *= $trafficFactor;

        $freshness = 0.0;
        if ($newOwner !== null && isset($lastPassByClaimant[$newOwner])) {
            // min(1.0, …): bei in der Zukunft liegendem last_pass (Client-/
            // Server-Clock-Skew) wäre ageDays < 0 und presenceWeight > 1.0.
            // Freshness ist per Definition ∈ [0,1], daher kappen.
            $freshness = min(1.0, GameMath::presenceWeight(
                $this->ageDays($lastPassByClaimant[$newOwner], $now),
                $windowDays,
            ));
        }

        $this->repo->updateEdgeCached(
            $edgeId,
            $newOwner,
            $ownerSince,
            $value,
            $freshness,
            $lastPassOverall,
            $trafficFactor,
            $traffic['pass_count'],
            $traffic['observations'],
            $discovererClaimant,
            $vulnerability,
        );
    }

    /**
     * Übernehmbarkeit ∈ [0,1] der Kante: wie nah der stärkste *Verfolger*
     * (bester Nicht-Owner) an der Übernahme-Schwelle des Owners liegt.
     * Schwelle = ownerPräsenz · hysteresis (Spec §5.2: Flip erst wenn
     * Verfolger > Owner·hysteresis). 0 = niemand nah dran (grün),
     * 1 = Verfolger an/über der Schwelle (rot).
     *
     * @param array<int,float> $presence Präsenz je Claimant
     */
    private function vulnerability(array $presence, ?int $ownerId, float $hysteresis): float
    {
        if ($ownerId === null) {
            return 0.0;
        }
        $ownerPresence = $presence[$ownerId] ?? 0.0;
        $topChallenger = 0.0;
        foreach ($presence as $cid => $pres) {
            if ((int)$cid !== $ownerId && $pres > $topChallenger) {
                $topChallenger = $pres;
            }
        }
        if ($topChallenger <= 0.0) {
            return 0.0;
        }
        if ($ownerPresence <= 0.0) {
            return 1.0;
        }
        $denom = $ownerPresence * max($hysteresis, 1e-9);
        return min(1.0, $topChallenger / $denom);
    }

    /**
     * Bestimmt je auf der Kante vorkommendem Rush, ob sein Multiplikator gilt:
     * qualifiziert (≥ rush_min_crew_size distinct getaggte Fahrer, §3.2) UND
     * diese Kante innerhalb rush_max_edges_per_rush (deterministisch nach
     * edge_id, §3.3). Nicht-qualifizierte/gekappte rushte Pässe verhalten sich
     * wie normale Pässe.
     *
     * @param list<array{claimant_id:int,user_id:int,ridden_on:string,ridden_at:string,rush_id:?int}> $passes
     * @return array{0:array<int,bool>,1:array<int,float>,2:bool} [applies, multiplier, stacks]
     */
    private function resolveRush(int $edgeId, array $passes): array
    {
        if (!$this->config->bool('rush_enabled')) {
            return [[], [], false];
        }
        $rushIds = [];
        foreach ($passes as $p) {
            if ($p['rush_id'] !== null) {
                $rushIds[$p['rush_id']] = true;
            }
        }
        if ($rushIds === []) {
            return [[], [], false];
        }

        $minCrew = $this->config->int('rush_min_crew_size');
        $cap     = $this->config->intOrNull('rush_max_edges_per_rush');
        $stacks  = $this->config->bool('rush_stacks_with_group_bonus');
        $info    = $this->repo->rushInfoMany(array_keys($rushIds));

        $applies = [];
        $mult    = [];
        foreach ($info as $rid => $i) {
            // cancelled/expired zählen nie; expired impliziert ohnehin < minCrew.
            $qualified = $i['distinct_riders'] >= $minCrew
                && $i['status'] !== 'cancelled'
                && $i['status'] !== 'expired';
            if (!$qualified) {
                $applies[$rid] = false;
                continue;
            }
            $allowed = true;
            if ($cap !== null) {
                $capped  = array_slice($this->repo->rushTaggedEdgeIds($rid), 0, max(0, $cap));
                $allowed = in_array($edgeId, $capped, true);
                if (!$allowed) {
                    // Kein Silent-Cap (§3.3/§9.9): die Kappung wird protokolliert.
                    error_log("rush_edge_cap: Kante {$edgeId} über rush_max_edges_per_rush={$cap} (Rush {$rid}) — regulärer Tagesbonus.");
                }
            }
            $applies[$rid] = $allowed;
            $mult[$rid]    = $i['multiplier'];
        }
        return [$applies, $mult, $stacks];
    }

    private function ageDays(string $mysqlDatetime, DateTimeImmutable $now): float
    {
        $dt = new DateTimeImmutable($mysqlDatetime, new DateTimeZone('UTC'));
        $seconds = $now->getTimestamp() - $dt->getTimestamp();
        return max(0.0, $seconds / 86400.0);
    }
}
