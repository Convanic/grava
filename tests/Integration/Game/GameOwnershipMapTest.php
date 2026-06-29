<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameReadService;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/**
 * Akzeptanzkriterien GameOwnershipOverview_Backend_Spec:
 *  - bbox + grid liefern aggregierte Zellen (Summen je Zustand stimmen).
 *  - dominant = größter der drei Längenwerte (mine > others > free bei Gleichstand).
 *  - angemeldet ≠ abgemeldet: mine_length_m nur mit Viewer > 0.
 *  - SW-Eckanker + grid je Zelle, leere Zellen weggelassen.
 */
final class GameOwnershipMapTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private EdgeRecalculator $recalc;
    private GameReadService $read;

    protected function setUp(): void
    {
        parent::setUp();
        $cfg = new GameConfig($this->pdo);
        $this->repo = new GameRepository($this->pdo);
        $this->recalc = new EdgeRecalculator($this->repo, $cfg);
        $this->read = new GameReadService($this->repo, $cfg);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));
    }

    /** Eroberte Kante (Besitzer = effektiver Claimant des Users) am Punkt (lat,lon). */
    private function ownEdge(int $userId, int $osm, int $wayId, float $lengthM, float $lat, float $lon): int
    {
        $edge = $this->makeEdge($osm, $wayId, $lengthM, $lat, $lon);
        $cid = $this->repo->effectiveClaimantId($userId);
        $now = $this->now();
        $this->repo->insertPassIfAbsent($edge, $cid, $userId, 1, $now->format('Y-m-d'), $now->format('Y-m-d H:i:s.v'));
        $this->repo->refreshEdgeDiscovery($edge);
        $this->recalc->recalculate($edge, $now);
        return $edge;
    }

    /** Herrenlose (freie) Kante: keine Pässe ⇒ owner_claimant_id bleibt NULL. */
    private function freeEdge(int $osm, int $wayId, float $lengthM, float $lat, float $lon): int
    {
        return $this->makeEdge($osm, $wayId, $lengthM, $lat, $lon);
    }

    private function makeEdge(int $osm, int $wayId, float $lengthM, float $lat, float $lon): int
    {
        $a = $this->repo->upsertNode($osm, $lat, $lon);
        $b = $this->repo->upsertNode($osm + 1, $lat + 0.001, $lon + 0.001);
        $geom = json_encode([
            'type' => 'LineString',
            'coordinates' => [[$lon, $lat], [$lon + 0.001, $lat + 0.001]],
        ]);
        return $this->repo->upsertEdge(
            $wayId, $a, $b, $lengthM, (string)$geom, null,
            $lat, $lon, $lat + 0.001, $lon + 0.001,
        );
    }

    /** @param array{cells:list<array<string,mixed>>} $map */
    private static function cellAt(array $map, float $lat, float $lon): ?array
    {
        foreach ($map['cells'] as $c) {
            if (abs($c['lat'] - $lat) < 1e-6 && abs($c['lon'] - $lon) < 1e-6) {
                return $c;
            }
        }
        return null;
    }

    public function testAggregatesMineOthersFreePerCell(): void
    {
        $me     = $this->createUser('me');
        $rival  = $this->createUser('rival');
        $viewer = $this->repo->effectiveClaimantId($me);

        // Zelle 1 (grid 0.05 → SW-Ecke 47.10/9.65): meine 200 m + fremde 100 m.
        $this->ownEdge($me,    1000, 1, 200.0, 47.120, 9.650);
        $this->ownEdge($rival, 1002, 2, 100.0, 47.121, 9.651);
        // Zelle 2 (SW-Ecke 47.30/9.85): freie 300 m.
        $this->freeEdge(1004, 3, 300.0, 47.300, 9.850);

        $map = $this->read->ownershipMap(9.0, 47.0, 10.0, 48.0, $viewer, 0.05);

        $this->assertCount(2, $map['cells']);

        $cell1 = self::cellAt($map, 47.10, 9.65);
        $this->assertNotNull($cell1, 'Zelle 1 (47.10/9.65) erwartet');
        $this->assertSame(0.05, $cell1['grid']);
        $this->assertEqualsWithDelta(200.0, $cell1['mine_length_m'], 0.1);
        $this->assertEqualsWithDelta(100.0, $cell1['others_length_m'], 0.1);
        $this->assertEqualsWithDelta(0.0, $cell1['free_length_m'], 0.1);
        $this->assertSame('mine', $cell1['dominant']);

        $cell2 = self::cellAt($map, 47.30, 9.85);
        $this->assertNotNull($cell2, 'Zelle 2 (47.30/9.85) erwartet');
        $this->assertEqualsWithDelta(300.0, $cell2['free_length_m'], 0.1);
        $this->assertEqualsWithDelta(0.0, $cell2['mine_length_m'], 0.1);
        $this->assertSame('free', $cell2['dominant']);
    }

    public function testAnonymousViewerHasNoMineLength(): void
    {
        $me    = $this->createUser('me');
        $rival = $this->createUser('rival');
        $this->ownEdge($me,    2000, 10, 200.0, 47.120, 9.650);
        $this->ownEdge($rival, 2002, 11, 100.0, 47.121, 9.651);

        // Ohne Bearer (viewer = null): alles Besessene zählt als fremd.
        $map = $this->read->ownershipMap(9.0, 47.0, 10.0, 48.0, null, 0.05);
        $cell = self::cellAt($map, 47.10, 9.65);
        $this->assertNotNull($cell);
        $this->assertEqualsWithDelta(0.0, $cell['mine_length_m'], 0.1);
        $this->assertEqualsWithDelta(300.0, $cell['others_length_m'], 0.1);
        $this->assertSame('others', $cell['dominant']);
    }

    public function testDominantTieBreakPrefersMineThenOthers(): void
    {
        $me    = $this->createUser('me');
        $rival = $this->createUser('rival');
        $viewer = $this->repo->effectiveClaimantId($me);

        // Gleichstand mine vs others (je 150 m) in einer Zelle → mine gewinnt.
        $this->ownEdge($me,    3000, 20, 150.0, 47.120, 9.650);
        $this->ownEdge($rival, 3002, 21, 150.0, 47.121, 9.651);

        $map = $this->read->ownershipMap(9.0, 47.0, 10.0, 48.0, $viewer, 0.05);
        $cell = self::cellAt($map, 47.10, 9.65);
        $this->assertNotNull($cell);
        $this->assertSame('mine', $cell['dominant']);
    }

    public function testDefaultGridDerivedFromSpanWhenOmitted(): void
    {
        $me = $this->createUser('me');
        $viewer = $this->repo->effectiveClaimantId($me);
        $this->ownEdge($me, 4000, 30, 200.0, 47.120, 9.650);

        // grid weggelassen → adaptiveGrid(span=1.0, minGrid=0.01) = 0.05.
        $map = $this->read->ownershipMap(9.0, 47.0, 10.0, 48.0, $viewer, null);
        $this->assertNotEmpty($map['cells']);
        $this->assertSame(0.05, $map['cells'][0]['grid']);
    }
}
