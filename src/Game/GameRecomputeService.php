<?php
declare(strict_types=1);

namespace App\Game;

use App\Support\Clock;
use DateTimeImmutable;

/**
 * Voller Recompute (Spec §7): liest ausschliesslich game_edge_pass und baut
 * alle *_cached-Felder neu. Bit-identisch zum Live-Pfad bei nicht
 * umkämpftem Besitz (§10.5).
 */
final class GameRecomputeService
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly EdgeRecalculator $recalc,
    ) {}

    /** @return int Anzahl neu berechneter Kanten. */
    public function recomputeAll(?DateTimeImmutable $now = null): int
    {
        $now ??= Clock::nowUtc();
        $this->repo->resetAllEdgeCaches();
        $ids = $this->repo->allEdgeIds();
        foreach ($ids as $edgeId) {
            $this->repo->refreshEdgeDiscovery($edgeId);
            $this->recalc->recalculate($edgeId, $now);
        }
        return count($ids);
    }

    /** @return int Anzahl neu berechneter Kanten im BBox. */
    public function recomputeBbox(float $minLon, float $minLat, float $maxLon, float $maxLat, ?DateTimeImmutable $now = null): int
    {
        $now ??= Clock::nowUtc();
        $ids = $this->repo->edgeIdsInBbox($minLon, $minLat, $maxLon, $maxLat);
        $this->repo->resetEdgeCaches($ids);
        foreach ($ids as $edgeId) {
            $this->repo->refreshEdgeDiscovery($edgeId);
            $this->recalc->recalculate($edgeId, $now);
        }
        return count($ids);
    }
}
