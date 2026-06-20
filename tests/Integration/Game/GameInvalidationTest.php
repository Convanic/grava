<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameRepository;
use Tests\IntegrationTestCase;

final class GameInvalidationTest extends IntegrationTestCase
{
    public function testInvalidatedPassesAreExcludedFromCalculations(): void
    {
        $u1 = $this->createUser('rider1');
        $u2 = $this->createUser('rider2');

        $repo = new GameRepository($this->pdo);
        $c1 = $repo->riderClaimantId($u1);
        $c2 = $repo->riderClaimantId($u2);

        $a = $repo->upsertNode(10, 47.12, 9.65);
        $b = $repo->upsertNode(11, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $eid = $repo->upsertEdge(1001, $a, $b, 120.0, $geom, 'gravel', 47.12, 9.65, 47.13, 9.66);

        $this->assertTrue($repo->insertPassIfAbsent($eid, $c1, $u1, 1, '2026-06-19', '2026-06-19 08:00:00.000'));
        $this->assertTrue($repo->insertPassIfAbsent($eid, $c2, $u2, 1, '2026-06-20', '2026-06-20 09:00:00.000'));

        // Vor Invalidierung: beide Pässe zählen.
        $this->assertSame(2, $repo->distinctRidersTotal($eid));
        $this->assertCount(2, $repo->passesForEdge($eid));

        // user2's Pass soft-invalidieren.
        $this->pdo->prepare(
            'UPDATE game_edge_pass SET invalidated_at = NOW(3), invalid_reason = ?
              WHERE edge_id = ? AND user_id = ?'
        )->execute(['test', $eid, $u2]);

        // Nach Invalidierung: nur user1 zählt in den Berechnungen.
        $this->assertSame(1, $repo->distinctRidersTotal($eid));
        $this->assertSame(1, $repo->distinctRidersSince($eid, '2000-01-01'));
        $this->assertCount(1, $repo->passesForEdge($eid));

        // Inspector sieht ALLE Pässe (inkl. invalidierte).
        $this->assertCount(2, $repo->allPassesForEdge($eid));

        // firstPassPerUser liefert nur den nicht-invalidierten User.
        $firsts = $repo->firstPassPerUser($eid);
        $this->assertCount(1, $firsts);
        $this->assertSame($u1, $firsts[0]['user_id']);
    }

    public function testEdgeIdsInBbox(): void
    {
        $repo = new GameRepository($this->pdo);
        $a = $repo->upsertNode(10, 47.12, 9.65);
        $b = $repo->upsertNode(11, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $eid = $repo->upsertEdge(1001, $a, $b, 120.0, $geom, 'gravel', 47.12, 9.65, 47.13, 9.66);

        // Bbox umschließt die Kante.
        $this->assertContains($eid, $repo->edgeIdsInBbox(9.0, 47.0, 10.0, 48.0));

        // Disjunkte Bbox → leer.
        $this->assertSame([], $repo->edgeIdsInBbox(0.0, 0.0, 1.0, 1.0));
    }
}
