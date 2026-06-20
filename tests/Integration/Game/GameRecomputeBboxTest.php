<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameRecomputeService;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class GameRecomputeBboxTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private EdgeRecalculator $recalc;
    private int $edgeA;
    private int $edgeB;
    private int $cA;
    private int $cB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->recalc = new EdgeRecalculator($this->repo, new GameConfig($this->pdo));

        $uA = $this->createUser('riderA');
        $uB = $this->createUser('riderB');
        $this->cA = $this->repo->riderClaimantId($uA);
        $this->cB = $this->repo->riderClaimantId($uB);

        // Edge A — Region um lon 9.6 / lat 47.1.
        $a1 = $this->repo->upsertNode(10, 47.12, 9.65);
        $a2 = $this->repo->upsertNode(11, 47.13, 9.66);
        $geomA = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $this->edgeA = $this->repo->upsertEdge(1001, $a1, $a2, 120.0, $geomA, null, 47.12, 9.65, 47.13, 9.66);

        // Edge B — weit entfernt: Region um lon 12.0 / lat 48.0.
        $b1 = $this->repo->upsertNode(20, 48.00, 12.00);
        $b2 = $this->repo->upsertNode(21, 48.01, 12.01);
        $geomB = json_encode(['type' => 'LineString', 'coordinates' => [[12.00, 48.00], [12.01, 48.01]]]);
        $this->edgeB = $this->repo->upsertEdge(2001, $b1, $b2, 120.0, $geomB, null, 48.00, 12.00, 48.01, 12.01);

        // Je ein Pass pro Kante.
        $this->repo->insertPassIfAbsent($this->edgeA, $this->cA, $uA, 1, '2026-06-20', '2026-06-20 08:00:00.000');
        $this->repo->insertPassIfAbsent($this->edgeB, $this->cB, $uB, 1, '2026-06-20', '2026-06-20 08:00:00.000');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));
    }

    public function testRecomputeBboxOnlyTouchesEdgesInRegion(): void
    {
        $service = new GameRecomputeService($this->repo, $this->recalc);
        $n = $service->recomputeBbox(9.5, 47.0, 9.8, 47.3, $this->now());

        $this->assertSame(1, $n, 'Nur Edge A liegt im BBox.');

        $edgeA = $this->repo->edgeById($this->edgeA);
        $this->assertSame($this->cA, (int)$edgeA['owner_claimant_id'], 'Edge A bekommt Besitzer.');
        $this->assertGreaterThan(0.0, (float)$edgeA['value_cached'], 'Edge A bekommt einen Wert.');

        $edgeB = $this->repo->edgeById($this->edgeB);
        $this->assertNull($edgeB['owner_claimant_id'], 'Edge B bleibt unberührt (zurückgesetzt).');
        $this->assertSame(0.0, (float)$edgeB['value_cached'], 'Edge B bleibt auf 0.');
    }

    public function testRecomputeBboxMatchesFullRecomputeForEdgeInRegion(): void
    {
        $service = new GameRecomputeService($this->repo, $this->recalc);

        $service->recomputeAll($this->now());
        $full = $this->repo->edgeById($this->edgeA);

        $service->recomputeBbox(9.5, 47.0, 9.8, 47.3, $this->now());
        $scoped = $this->repo->edgeById($this->edgeA);

        $this->assertSame(
            $full['owner_claimant_id'],
            $scoped['owner_claimant_id'],
            'Region-Recompute muss denselben Besitzer wie der volle Recompute liefern.'
        );
        $this->assertSame(
            (string)$full['value_cached'],
            (string)$scoped['value_cached'],
            'Region-Recompute muss denselben Wert wie der volle Recompute liefern.'
        );
    }
}
