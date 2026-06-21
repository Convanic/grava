<?php
declare(strict_types=1);

namespace Tests\Integration\Game\Crew;

use App\Game\Admin\GameAuditService;
use App\Game\Crew\CrewException;
use App\Game\Crew\CrewRepository;
use App\Game\Crew\CrewService;
use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class CrewServiceTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private CrewRepository $crews;
    private EdgeRecalculator $recalc;
    private CrewService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $cfg = new GameConfig($this->pdo);
        $this->repo = new GameRepository($this->pdo);
        $this->crews = new CrewRepository($this->pdo);
        $this->recalc = new EdgeRecalculator($this->repo, $cfg);
        $this->svc = new CrewService(
            $this->pdo, $this->crews, $this->repo, $this->recalc, $cfg, new GameAuditService($this->pdo),
        );
    }

    private function now(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    private function makeEdge(): int
    {
        $a = $this->repo->upsertNode(30, 47.12, 9.65);
        $b = $this->repo->upsertNode(31, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        return $this->repo->upsertEdge(3001, $a, $b, 120.0, $geom, null, 47.12, 9.65, 47.13, 9.66);
    }

    private function memberRows(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM game_crew_member WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    public function testExactlyOneCrewSwitchesCleanly(): void
    {
        $u1 = $this->createUser('switcher');
        $u2 = $this->createUser('bravo-cap');
        $bravo = $this->svc->create($u2, 'Bravo');

        $this->svc->create($u1, 'Alpha'); // u1 Captain einer Solo-Crew
        $this->svc->join($u1, $bravo['join_code']); // wechselt sauber zu Bravo

        $membership = $this->crews->membershipOf($u1);
        $this->assertNotNull($membership);
        $this->assertSame($bravo['id'], $membership['crew_id']);
        $this->assertSame(1, $this->memberRows($u1), 'Nie mehr als eine Mitgliedschaft pro User.');
        $this->assertNull($this->crews->crewBySlug('alpha'), 'Alte Solo-Crew wurde aufgelöst.');
    }

    public function testPresenceMovesOnCreateAndReturnsOnLeave(): void
    {
        $edge = $this->makeEdge();
        $u1 = $this->createUser('rider');
        $rider = $this->repo->riderClaimantId($u1);
        $now = $this->now('2026-06-20T12:00:00Z');
        $this->repo->insertPassIfAbsent($edge, $rider, $u1, 1, '2026-06-20', '2026-06-20 08:00:00.000');
        $this->repo->refreshEdgeDiscovery($edge);
        $this->recalc->recalculate($edge, $now);
        $this->assertSame($rider, (int)$this->repo->edgeById($edge)['owner_claimant_id']);

        $crew = $this->svc->create($u1, 'Owls', $now);
        $this->assertSame($crew['claimant_id'], (int)$this->repo->edgeById($edge)['owner_claimant_id'],
            'Nach create gehört die Kante der Crew.');

        $this->svc->leave($u1, $now);
        $this->assertSame($rider, (int)$this->repo->edgeById($edge)['owner_claimant_id'],
            'Nach leave fällt die Kante an den Rider zurück.');
        $this->assertNull($this->crews->crewBySlug('owls'), 'Letztes Mitglied -> Crew aufgelöst.');
    }

    public function testPioneerCreditMovesToCrewAndBack(): void
    {
        $edge = $this->makeEdge();
        $u1 = $this->createUser('rider');
        $rider = $this->repo->riderClaimantId($u1);
        $now = $this->now('2026-06-20T12:00:00Z');
        $this->repo->insertPassIfAbsent($edge, $rider, $u1, 1, '2026-06-20', '2026-06-20 08:00:00.000');
        $this->repo->refreshEdgeDiscovery($edge);
        $this->recalc->recalculate($edge, $now);
        $this->assertSame(1, $this->repo->meStats($rider)['pioneered'], 'Solo: Rider pioniert.');

        $crew  = $this->svc->create($u1, 'Owls', $now);
        $group = (int)$crew['claimant_id'];
        // Pionier-Kredit folgt dem Besitz zur Crew (nicht 0!).
        $this->assertSame(1, $this->repo->meStats($group)['pioneered'], 'Crew pioniert nach Beitritt.');
        $this->assertSame(1, $this->repo->meStats($group)['held']);
        $this->assertSame(0, $this->repo->meStats($rider)['pioneered']);

        $this->svc->leave($u1, $now);
        $this->assertSame(1, $this->repo->meStats($rider)['pioneered'], 'Nach leave zurück zum Rider.');
    }

    public function testCaptainMustTransferBeforeLeaving(): void
    {
        $cap = $this->createUser('captain');
        $mate = $this->createUser('mate');
        $crew = $this->svc->create($cap, 'Pack');
        $this->svc->join($mate, $crew['join_code']);

        try {
            $this->svc->leave($cap);
            $this->fail('Captain mit Mitgliedern darf nicht ohne Übertragung gehen.');
        } catch (CrewException $e) {
            $this->assertSame('captain_must_transfer', $e->errorCode);
            $this->assertSame(409, $e->status);
        }

        $this->svc->transfer($cap, $mate);
        $this->assertSame('member', $this->crews->membershipOf($cap)['role']);
        $this->assertSame('captain', $this->crews->membershipOf($mate)['role']);

        // Jetzt darf der Ex-Captain gehen, und der neue Captain löst als Letzter auf.
        $left = $this->svc->leave($cap);
        $this->assertTrue($left['left']);
        $this->assertFalse($left['dissolved']);

        $dissolve = $this->svc->leave($mate);
        $this->assertTrue($dissolve['dissolved']);
        $this->assertNull($this->crews->crewBySlug('pack'));
    }

    public function testJoinWithInvalidCodeReturns404(): void
    {
        $u = $this->createUser('joiner');
        try {
            $this->svc->join($u, 'NOPECODE');
            $this->fail('Ungültiger Code muss 404 werfen.');
        } catch (CrewException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function testMeReturnsJoinCodeOnlyForCaptain(): void
    {
        $cap = $this->createUser('cap2');
        $mate = $this->createUser('mate2');
        $crew = $this->svc->create($cap, 'Foxes');
        $this->svc->join($mate, $crew['join_code']);

        $capMe = $this->svc->me($cap);
        $mateMe = $this->svc->me($mate);
        $this->assertArrayHasKey('join_code', $capMe);
        $this->assertArrayNotHasKey('join_code', $mateMe);
        $this->assertSame(2, $capMe['member_count']);
    }
}
