<?php
declare(strict_types=1);

namespace Tests\Integration\Game\Faction;

use App\Game\Admin\GameAuditService;
use App\Game\Crew\CrewRepository;
use App\Game\Crew\CrewService;
use App\Game\EdgeRecalculator;
use App\Game\Faction\FactionException;
use App\Game\Faction\FactionRepository;
use App\Game\Faction\FactionService;
use App\Game\GameConfig;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/**
 * Akzeptanzkriterien GAME_STAGE3_BACKEND.md §7.
 */
final class FactionServiceTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private CrewRepository $crews;
    private FactionRepository $factions;
    private EdgeRecalculator $recalc;
    private CrewService $crewSvc;
    private FactionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $cfg = new GameConfig($this->pdo);
        $this->repo = new GameRepository($this->pdo);
        $this->crews = new CrewRepository($this->pdo);
        $this->factions = new FactionRepository($this->pdo);
        $this->recalc = new EdgeRecalculator($this->repo, $cfg);
        $audit = new GameAuditService($this->pdo);
        $this->crewSvc = new CrewService(
            $this->pdo, $this->crews, $this->repo, $this->recalc, $cfg, $audit, $this->factions,
        );
        $this->svc = new FactionService(
            $this->pdo, $this->crews, $this->factions, $this->crewSvc, $cfg, $audit,
        );
    }

    private function now(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    /** Gibt einem User (über seine Crew) eine Kante; gibt edgeId zurück. */
    private function ownEdge(int $userId, int $osmA, int $osmB, int $wayId, float $lengthM, DateTimeImmutable $now): int
    {
        // Mittelpunkt ~ (47.125, 9.655) → alle Kanten landen in derselben Zelle.
        $a = $this->repo->upsertNode($osmA, 47.12, 9.65);
        $b = $this->repo->upsertNode($osmB, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $edge = $this->repo->upsertEdge($wayId, $a, $b, $lengthM, $geom, null, 47.12, 9.65, 47.13, 9.66);
        $cid = $this->repo->effectiveClaimantId($userId);
        $this->repo->insertPassIfAbsent($edge, $cid, $userId, 1, $now->format('Y-m-d'), $now->format('Y-m-d H:i:s.v'));
        $this->repo->refreshEdgeDiscovery($edge);
        $this->recalc->recalculate($edge, $now);
        return $edge;
    }

    public function testSeedHasExactlyTwoFactions(): void
    {
        $all = $this->factions->all();
        $this->assertCount(2, $all);
        $this->assertSame(['green', 'blue'], array_map(fn($f) => $f['key'], $all));
    }

    public function testCaptainSetsFactionMemberCannot(): void
    {
        $cap = $this->createUser('cap');
        $crew = $this->crewSvc->create($cap, 'Waldrudel');
        $mate = $this->createUser('mate');
        $this->crewSvc->join($mate, $crew['join_code']);

        $res = $this->svc->setFaction($cap, 'waldrudel', 'green');
        $this->assertSame('green', $res['faction']['key']);
        $row = $this->crews->crewBySlug('waldrudel');
        $this->assertNotNull($row['faction_id']);

        $this->expectException(FactionException::class);
        $this->svc->setFaction($mate, 'waldrudel', 'blue'); // Nicht-Captain → 403
    }

    public function testSwitchBlockedByCooldownThenAllowed(): void
    {
        $cap = $this->createUser('cap');
        $crew = $this->crewSvc->create($cap, 'Owls');
        $t0 = $this->now('2026-06-01T10:00:00Z');
        $this->svc->setFaction($cap, 'owls', 'green', $t0);

        // 1 Tag später (Cooldown 30d) → gesperrt.
        try {
            $this->svc->setFaction($cap, 'owls', 'blue', $this->now('2026-06-02T10:00:00Z'));
            $this->fail('Wechsel vor Ablauf des Cooldowns muss 409 werfen.');
        } catch (FactionException $e) {
            $this->assertSame('faction_cooldown', $e->errorCode);
            $this->assertSame(409, $e->status);
            $this->assertArrayHasKey('retry_at', $e->fields ?? []);
        }

        // 31 Tage später → erlaubt.
        $res = $this->svc->setFaction($cap, 'owls', 'blue', $this->now('2026-07-02T10:00:00Z'));
        $this->assertSame('blue', $res['faction']['key']);
    }

    public function testOwnerFactionAppearsOnlyForFactionBoundCrew(): void
    {
        $cap = $this->createUser('cap');
        $now = $this->now('2026-06-20T08:00:00Z');
        $crew = $this->crewSvc->create($cap, 'Waldrudel', $now);
        $edge = $this->ownEdge($cap, 40, 41, 4001, 200.0, $now);

        // Neutral → kein faction im Owner.
        $ownerId = (int)$this->repo->edgeById($edge)['owner_claimant_id'];
        $this->assertArrayNotHasKey('faction', $this->repo->claimantInfo($ownerId));

        // Nach Beitritt → faction gesetzt.
        $this->svc->setFaction($cap, 'waldrudel', 'green', $now);
        $info = $this->repo->claimantInfo($ownerId);
        $this->assertSame('green', $info['faction']['key']);
    }

    public function testFactionChangeDoesNotRecomputeEdges(): void
    {
        $cap = $this->createUser('cap');
        $now = $this->now('2026-06-20T08:00:00Z');
        $this->crewSvc->create($cap, 'Owls', $now);
        $edge = $this->ownEdge($cap, 50, 51, 5001, 200.0, $now);
        $before = $this->repo->edgeById($edge);

        $this->svc->setFaction($cap, 'owls', 'green', $now);

        $after = $this->repo->edgeById($edge);
        $this->assertSame($before['owner_claimant_id'], $after['owner_claimant_id']);
        $this->assertSame((float)$before['value_cached'], (float)$after['value_cached']);
    }

    public function testMetaMapStrongerFactionWinsCell(): void
    {
        $now = $this->now('2026-06-20T08:00:00Z');
        $g = $this->createUser('green-cap');
        $this->crewSvc->create($g, 'Greens', $now);
        $this->ownEdge($g, 60, 61, 6001, 200.0, $now); // grün: 200 m
        $this->svc->setFaction($g, 'greens', 'green', $now);

        $b = $this->createUser('blue-cap');
        $this->crewSvc->create($b, 'Blues', $now);
        $this->ownEdge($b, 62, 63, 6002, 100.0, $now); // blau: 100 m (gleiche Zelle)
        $this->svc->setFaction($b, 'blues', 'blue', $now);

        $map = $this->svc->map(9.0, 47.0, 10.0, 48.0);
        $this->assertCount(1, $map['cells']);
        $this->assertSame('green', $map['cells'][0]['faction']);
        $this->assertEqualsWithDelta(200.0, $map['cells'][0]['strength']['green'], 0.1);
        $this->assertEqualsWithDelta(100.0, $map['cells'][0]['strength']['blue'], 0.1);
    }

    public function testStandingsSummarize(): void
    {
        $now = $this->now('2026-06-20T08:00:00Z');
        $g = $this->createUser('green-cap');
        $crew = $this->crewSvc->create($g, 'Greens', $now);
        $mate = $this->createUser('green-mate');
        $this->crewSvc->join($mate, $crew['join_code']);
        $this->ownEdge($g, 70, 71, 7001, 200.0, $now);
        $this->svc->setFaction($g, 'greens', 'green', $now);

        $standings = $this->svc->standings();
        $green = array_values(array_filter($standings['factions'], fn($f) => $f['key'] === 'green'))[0];
        $blue  = array_values(array_filter($standings['factions'], fn($f) => $f['key'] === 'blue'))[0];

        $this->assertSame(1, $green['crews']);
        $this->assertSame(2, $green['members']);
        $this->assertEqualsWithDelta(200.0, $green['held_length_m'], 0.1);
        $this->assertSame(1, $green['cells']);
        $this->assertSame(0, $blue['crews']);
    }
}
