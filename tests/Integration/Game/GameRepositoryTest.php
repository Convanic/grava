<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameRepository;
use Tests\IntegrationTestCase;

final class GameRepositoryTest extends IntegrationTestCase
{
    public function testRiderClaimantIsLazyAndUnique(): void
    {
        $uid = $this->createUser('armin');
        $repo = new GameRepository($this->pdo);
        $c1 = $repo->riderClaimantId($uid);
        $c2 = $repo->riderClaimantId($uid);
        $this->assertSame($c1, $c2, 'pro User genau ein rider-Claimant');
    }

    public function testUpsertNodeAndEdgeAreIdempotent(): void
    {
        $repo = new GameRepository($this->pdo);
        $a = $repo->upsertNode(10, 47.12, 9.65);
        $b = $repo->upsertNode(11, 47.13, 9.66);
        $this->assertSame($a, $repo->upsertNode(10, 47.12, 9.65));

        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $e1 = $repo->upsertEdge(1001, $a, $b, 120.0, $geom, 'gravel', 47.12, 9.65, 47.13, 9.66);
        $e2 = $repo->upsertEdge(1001, $a, $b, 120.0, $geom, 'gravel', 47.12, 9.65, 47.13, 9.66);
        $this->assertSame($e1, $e2, 'gleiche Kante → kein Duplikat');
    }

    public function testInsertPassRespectsDayCap(): void
    {
        $uid = $this->createUser('armin');
        $repo = new GameRepository($this->pdo);
        $cid = $repo->riderClaimantId($uid);
        $a = $repo->upsertNode(10, 47.12, 9.65);
        $b = $repo->upsertNode(11, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $eid = $repo->upsertEdge(1001, $a, $b, 120.0, $geom, null, 47.12, 9.65, 47.13, 9.66);

        $first = $repo->insertPassIfAbsent($eid, $cid, $uid, 1, '2026-06-20', '2026-06-20 08:00:00.000');
        $second = $repo->insertPassIfAbsent($eid, $cid, $uid, 1, '2026-06-20', '2026-06-20 09:00:00.000');
        $this->assertTrue($first, 'erster Pass am Tag → angelegt');
        $this->assertFalse($second, 'zweiter Pass am selben Tag → kein neuer Pass');
        $this->assertSame(1, $repo->distinctRidersTotal($eid));
    }
}
