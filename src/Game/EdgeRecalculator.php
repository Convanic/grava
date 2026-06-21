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

        // Tages-Aggregation je (effektiver Claimant, ridden_on): Summe Gewicht + distinct Mitglieder.
        $dayWeight  = [];   // [cid][ridden_on] => float
        $dayMembers = [];   // [cid][ridden_on] => array<int,true>
        $isGroup    = [];   // [cid] => bool
        $lastPassByClaimant = [];
        $lastPassOverall = null;
        foreach ($passes as $p) {
            $uid = $p['user_id'];
            $eff = $effMap[$uid] ?? ['claimant_id' => $p['claimant_id'], 'is_group' => false];
            $cid = $eff['claimant_id'];
            $isGroup[$cid] = $eff['is_group'];
            $on = $p['ridden_on'];
            $w = GameMath::presenceWeight($this->ageDays($p['ridden_at'], $now), $windowDays);
            $dayWeight[$cid][$on] = ($dayWeight[$cid][$on] ?? 0.0) + $w;
            $dayMembers[$cid][$on][$uid] = true;
            if (!isset($lastPassByClaimant[$cid]) || $p['ridden_at'] > $lastPassByClaimant[$cid]) {
                $lastPassByClaimant[$cid] = $p['ridden_at'];
            }
            if ($lastPassOverall === null || $p['ridden_at'] > $lastPassOverall) {
                $lastPassOverall = $p['ridden_at'];
            }
        }

        // Präsenz je Claimant; Gruppenfahrt-Bonus als Tagesfaktor (nur Group-Claimants,
        // ab group_ride_min_members verschiedenen Mitgliedern am selben Tag).
        $presence = [];
        foreach ($dayWeight as $cid => $byDay) {
            $sum = 0.0;
            foreach ($byDay as $on => $w) {
                $members = count($dayMembers[$cid][$on]);
                if (($isGroup[$cid] ?? false) && $bonus !== 1.0 && $members >= $minMembers) {
                    $w *= $bonus;
                }
                $sum += $w;
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
        if ($challenger !== null) {
            $currentPresence = $currentOwner !== null ? ($presence[$currentOwner] ?? 0.0) : 0.0;
            $newOwner = GameMath::decideOwner(
                $currentOwner,
                $currentPresence,
                $challenger,
                $challengerPresence,
                $this->config->float('hysteresis_factor'),
            );
            if ($newOwner !== $currentOwner) {
                $ownerSince = $now->format('Y-m-d H:i:s.v');
            }
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

        $freshness = 0.0;
        if ($newOwner !== null && isset($lastPassByClaimant[$newOwner])) {
            $freshness = GameMath::presenceWeight(
                $this->ageDays($lastPassByClaimant[$newOwner], $now),
                $windowDays,
            );
        }

        $this->repo->updateEdgeCached(
            $edgeId,
            $newOwner,
            $ownerSince,
            $value,
            $freshness,
            $lastPassOverall,
        );
    }

    private function ageDays(string $mysqlDatetime, DateTimeImmutable $now): float
    {
        $dt = new DateTimeImmutable($mysqlDatetime, new DateTimeZone('UTC'));
        $seconds = $now->getTimestamp() - $dt->getTimestamp();
        return max(0.0, $seconds / 86400.0);
    }
}
