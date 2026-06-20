<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class EdgeRecalculatorTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private EdgeRecalculator $recalc;
    private int $edgeId;
    private int $c1;
    private int $c2;
    private int $u1;
    private int $u2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->recalc = new EdgeRecalculator($this->repo, new GameConfig($this->pdo));
        $this->u1 = $this->createUser('rider1');
        $this->u2 = $this->createUser('rider2');
        $this->c1 = $this->repo->riderClaimantId($this->u1);
        $this->c2 = $this->repo->riderClaimantId($this->u2);
        $a = $this->repo->upsertNode(10, 47.12, 9.65);
        $b = $this->repo->upsertNode(11, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $this->edgeId = $this->repo->upsertEdge(1001, $a, $b, 120.0, $geom, null, 47.12, 9.65, 47.13, 9.66);
    }

    private function pass(int $claimant, int $user, string $riddenAt): void
    {
        $on = substr($riddenAt, 0, 10);
        $this->repo->insertPassIfAbsent($this->edgeId, $claimant, $user, 1, $on, $riddenAt);
    }

    private function now(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public function testFirstClaimantBecomesOwner(): void
    {
        $this->pass($this->c1, $this->u1, '2026-06-20 08:00:00.000');
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $this->now('2026-06-20T08:00:00Z'));

        $edge = $this->repo->edgeById($this->edgeId);
        $this->assertSame($this->c1, (int)$edge['owner_claimant_id']);
        $this->assertSame(1, (int)$edge['distinct_riders_total']);
        $this->assertEqualsWithDelta(100.0, (float)$edge['value_cached'], 0.1);
        $this->assertEqualsWithDelta(1.0, (float)$edge['freshness_cached'], 0.01);
    }

    public function testHysteresisKeepsOwnerUntilExceeded(): void
    {
        for ($d = 0; $d < 10; $d++) {
            $day = (new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC')))->modify("-{$d} days");
            $this->pass($this->c1, $this->u1, $day->format('Y-m-d') . ' 08:00:00.000');
        }
        $now = $this->now('2026-06-20T12:00:00Z');
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $now);
        $this->assertSame($this->c1, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id']);

        for ($d = 0; $d < 10; $d++) {
            $day = (new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC')))->modify("-{$d} days");
            $this->pass($this->c2, $this->u2, $day->format('Y-m-d') . ' 09:00:00.000');
        }
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $now);
        $this->assertSame($this->c1, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id'],
            'Gleichstand → Hysterese schützt Amtsinhaber');

        for ($d = 10; $d < 35; $d++) {
            $day = (new DateTimeImmutable('2026-06-20', new DateTimeZone('UTC')))->modify("-{$d} days");
            $this->pass($this->c2, $this->u2, $day->format('Y-m-d') . ' 09:00:00.000');
        }
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $now);
        $this->assertSame($this->c2, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id'],
            'Präsenz über Hysterese-Schwelle → Besitzwechsel');
        $this->assertNotNull($this->repo->edgeById($this->edgeId)['owner_since']);
    }
}
