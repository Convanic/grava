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

        $presence = [];
        $lastPassByClaimant = [];
        $lastPassOverall = null;
        foreach ($passes as $p) {
            $cid = $p['claimant_id'];
            $ageDays = $this->ageDays($p['ridden_at'], $now);
            $presence[$cid] = ($presence[$cid] ?? 0.0) + GameMath::presenceWeight($ageDays, $windowDays);
            if (!isset($lastPassByClaimant[$cid]) || $p['ridden_at'] > $lastPassByClaimant[$cid]) {
                $lastPassByClaimant[$cid] = $p['ridden_at'];
            }
            if ($lastPassOverall === null || $p['ridden_at'] > $lastPassOverall) {
                $lastPassOverall = $p['ridden_at'];
            }
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
        return $seconds / 86400.0;
    }
}
