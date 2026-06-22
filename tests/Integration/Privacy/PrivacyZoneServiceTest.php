<?php
declare(strict_types=1);

namespace Tests\Integration\Privacy;

use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameRepository;
use App\Privacy\PrivacyZone;
use App\Privacy\PrivacyZoneRepository;
use App\Privacy\PrivacyZoneService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class PrivacyZoneServiceTest extends IntegrationTestCase
{
    private GameRepository $game;
    private PrivacyZoneService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = new GameRepository($this->pdo);
        $recalc = new EdgeRecalculator($this->game, new GameConfig($this->pdo));
        $this->svc = new PrivacyZoneService(new PrivacyZoneRepository($this->pdo), $this->game, $recalc, $this->pdo);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-20T12:00:00Z', new DateTimeZone('UTC'));
    }

    /** @param list<array{0:float,1:float}> $geom [lon,lat] */
    private function edge(int $wayId, int $nodeBase, array $geom): int
    {
        $a = $this->game->upsertNode($nodeBase, $geom[0][1], $geom[0][0]);
        $b = $this->game->upsertNode($nodeBase + 1, $geom[1][1], $geom[1][0]);
        $lons = array_column($geom, 0);
        $lats = array_column($geom, 1);
        $json = json_encode(['type' => 'LineString', 'coordinates' => $geom]);
        return $this->game->upsertEdge($wayId, $a, $b, 120.0, $json, 'gravel', min($lats), min($lons), max($lats), max($lons));
    }

    public function testGetNullThenPutStoresWithClampHigh(): void
    {
        $uid = $this->createUser('a');
        $this->assertNull($this->svc->get($uid));

        $saved = $this->svc->put($uid, 48.20, 11.60, 9999, true, $this->now());
        $this->assertSame(PrivacyZone::RADIUS_MAX_M, $saved['radius_m']);

        $got = $this->svc->get($uid);
        $this->assertNotNull($got);
        $this->assertTrue($got['enabled']);
        $this->assertSame(2000, $got['radius_m']);
        $this->assertEqualsWithDelta(48.20, $got['lat'], 1e-6);
    }

    public function testRadiusClampLow(): void
    {
        $uid = $this->createUser('b');
        $saved = $this->svc->put($uid, 48.20, 11.60, 50, true, $this->now());
        $this->assertSame(PrivacyZone::RADIUS_MIN_M, $saved['radius_m']);
    }

    public function testDeleteRemovesZone(): void
    {
        $uid = $this->createUser('c');
        $this->svc->put($uid, 48.20, 11.60, 500, true, $this->now());
        $this->svc->delete($uid);
        $this->assertNull($this->svc->get($uid));
    }

    public function testRetroactiveCleanupInvalidatesPassesInZoneOnly(): void
    {
        $uid = $this->createUser('rider');
        $claimant = $this->game->riderClaimantId($uid);

        // Kante IN der späteren Zone (um 9.655/47.125) und eine weit entfernt.
        $inZone  = $this->edge(2001, 20, [[9.65, 47.12], [9.66, 47.13]]);
        $outside = $this->edge(2002, 22, [[9.80, 47.30], [9.81, 47.31]]);

        foreach ([$inZone, $outside] as $e) {
            $this->game->insertPassIfAbsent($e, $claimant, $uid, 100 + $e, '2026-06-10', '2026-06-10 08:00:00.000');
            $this->game->refreshEdgeDiscovery($e);
            (new EdgeRecalculator($this->game, new GameConfig($this->pdo)))->recalculate($e, $this->now());
        }
        // Vorbedingung: beide Kanten gehören dem Fahrer.
        $this->assertSame($claimant, (int)$this->game->edgeById($inZone)['owner_claimant_id']);
        $this->assertSame($claimant, (int)$this->game->edgeById($outside)['owner_claimant_id']);

        $affected = $this->svc->cleanup($uid, new PrivacyZone(47.125, 9.655, 500), $this->now());
        $this->assertSame(1, $affected, 'Nur die Kante in der Zone wird bereinigt.');

        // Pass in der Zone ist invalidiert; Kante hat keinen Besitzer mehr.
        $rows = $this->game->allPassesForEdge($inZone);
        $this->assertNotNull($rows[0]['invalidated_at']);
        $this->assertSame('privacy_zone', $rows[0]['invalid_reason']);
        $this->assertNull($this->game->edgeById($inZone)['owner_claimant_id']);

        // Kante außerhalb der Zone unberührt.
        $rowsOut = $this->game->allPassesForEdge($outside);
        $this->assertNull($rowsOut[0]['invalidated_at']);
        $this->assertSame($claimant, (int)$this->game->edgeById($outside)['owner_claimant_id']);
    }

    public function testPutWithEnabledTriggersCleanup(): void
    {
        $uid = $this->createUser('rider2');
        $claimant = $this->game->riderClaimantId($uid);
        $inZone = $this->edge(3001, 30, [[9.65, 47.12], [9.66, 47.13]]);
        $this->game->insertPassIfAbsent($inZone, $claimant, $uid, 500, '2026-06-10', '2026-06-10 08:00:00.000');
        $this->game->refreshEdgeDiscovery($inZone);
        (new EdgeRecalculator($this->game, new GameConfig($this->pdo)))->recalculate($inZone, $this->now());

        $this->svc->put($uid, 47.125, 9.655, 500, true, $this->now());

        $rows = $this->game->allPassesForEdge($inZone);
        $this->assertNotNull($rows[0]['invalidated_at']);
        $this->assertNull($this->game->edgeById($inZone)['owner_claimant_id']);
    }
}
