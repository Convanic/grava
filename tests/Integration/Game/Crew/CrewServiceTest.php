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

    public function testMapEdgeExposesRiderAndCrewFields(): void
    {
        $edge = $this->makeEdge();
        $u1 = $this->createUser('mapper');
        $rider = $this->repo->riderClaimantId($u1);
        $now = $this->now('2026-06-20T12:00:00Z');
        $this->repo->insertPassIfAbsent($edge, $rider, $u1, 1, '2026-06-20', '2026-06-20 08:00:00.000');
        $this->repo->refreshEdgeDiscovery($edge);
        $this->recalc->recalculate($edge, $now);

        $rows = $this->repo->edgesGeoForMap(9.0, 47.0, 10.0, 48.0, 100);
        $row = null;
        foreach ($rows as $r) {
            if ((int)$r['id'] === $edge) { $row = $r; break; }
        }
        $this->assertNotNull($row, 'Kante muss in der Map-Query auftauchen.');
        $this->assertSame('rider', $row['owner_type']);
        $this->assertSame($u1, (int)$row['rider_user_id'], 'Erstfahrer = erradelnder User.');
        $this->assertNull($row['crew_id'], 'Solo-Kante hat keine Crew.');
        $this->assertNull($row['faction_key']);

        $crew = $this->svc->create($u1, 'Owls', $now);
        $rows2 = $this->repo->edgesGeoForMap(9.0, 47.0, 10.0, 48.0, 100);
        $row2 = null;
        foreach ($rows2 as $r) {
            if ((int)$r['id'] === $edge) { $row2 = $r; break; }
        }
        $this->assertNotNull($row2);
        $this->assertSame('group', $row2['owner_type'], 'Nach Crew-Beitritt gehört die Kante der Gruppe.');
        $this->assertSame($crew['id'], (int)$row2['crew_id']);
        $this->assertSame('Owls', $row2['crew_name']);
        $this->assertSame($u1, (int)$row2['rider_user_id'], 'Erstfahrer bleibt der Mensch.');

        // Fraktions-Join-Pfad (grün/blau aus IntegrationTestCase-Seed).
        $fid = (int)$this->pdo->query("SELECT id FROM game_faction WHERE key_slug='green'")->fetchColumn();
        $this->pdo->prepare('UPDATE game_crew SET faction_id = ? WHERE id = ?')->execute([$fid, $crew['id']]);
        $rows3 = $this->repo->edgesGeoForMap(9.0, 47.0, 10.0, 48.0, 100);
        $row3 = null;
        foreach ($rows3 as $r) {
            if ((int)$r['id'] === $edge) { $row3 = $r; break; }
        }
        $this->assertNotNull($row3);
        $this->assertSame('green', $row3['faction_key']);
        $this->assertNotEmpty($row3['faction_color']);
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

    // ----------------------------------------------------------------
    // §5.2 — world_rank (Crew) + my_rank_in_crew (Mitglied)
    // ----------------------------------------------------------------

    private function makeEdgeLen(int $wayId, int $nodeA, int $nodeB, float $len): int
    {
        $a = $this->repo->upsertNode($nodeA, 47.12, 9.65);
        $b = $this->repo->upsertNode($nodeB, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        return $this->repo->upsertEdge($wayId, $a, $b, $len, $geom, null, 47.12, 9.65, 47.13, 9.66);
    }

    /** Lässt $userId die Kante an einem Tag befahren und rechnet den Besitz neu. */
    private function ownEdgeByUser(int $edge, int $userId, DateTimeImmutable $now, string $day): void
    {
        $rider = $this->repo->riderClaimantId($userId);
        $this->repo->insertPassIfAbsent($edge, $rider, $userId, 1, $day, $day . ' 08:00:00.000');
        $this->repo->refreshEdgeDiscovery($edge);
        $this->recalc->recalculate($edge, $now);
    }

    public function testWorldRankByHeldLength(): void
    {
        $now = $this->now('2026-06-20T12:00:00Z');
        $ca  = $this->createUser('rank-a');
        $cb  = $this->createUser('rank-b');
        $this->svc->create($ca, 'Alpha', $now);
        $this->svc->create($cb, 'Bravo', $now);

        // Crew Alpha hält eine lange Kante, Bravo eine kurze.
        $eA = $this->makeEdgeLen(4001, 40, 41, 500.0);
        $eB = $this->makeEdgeLen(4002, 42, 43, 100.0);
        $this->ownEdgeByUser($eA, $ca, $now, '2026-06-19');
        $this->ownEdgeByUser($eB, $cb, $now, '2026-06-19');

        $this->assertSame(1, $this->svc->me($ca)['world_rank'], 'Längeres Revier → Rang 1.');
        $this->assertSame(2, $this->svc->me($cb)['world_rank']);
    }

    public function testMyRankInCrewByPresenceContribution(): void
    {
        $now  = $this->now('2026-06-20T12:00:00Z');
        $cap  = $this->createUser('mr-cap');
        $m1   = $this->createUser('mr-one');
        $m2   = $this->createUser('mr-two');
        $crew = $this->svc->create($cap, 'Rankers', $now);
        $this->svc->join($m1, $crew['join_code'], $now);
        $this->svc->join($m2, $crew['join_code'], $now);

        $edge  = $this->makeEdgeLen(4100, 50, 51, 300.0);
        $rCap  = $this->repo->riderClaimantId($cap);
        $rM1   = $this->repo->riderClaimantId($m1);
        // cap: 1 Pass; m1: 2 Pässe (zwei Tage) → höherer Präsenzbeitrag; m2: keiner.
        $this->repo->insertPassIfAbsent($edge, $rCap, $cap, 1, '2026-06-19', '2026-06-19 08:00:00.000');
        $this->repo->insertPassIfAbsent($edge, $rM1, $m1, 1, '2026-06-18', '2026-06-18 08:00:00.000');
        $this->repo->insertPassIfAbsent($edge, $rM1, $m1, 1, '2026-06-19', '2026-06-19 09:00:00.000');
        $this->repo->refreshEdgeDiscovery($edge);
        $this->recalc->recalculate($edge, $now);

        $this->assertSame(1, $this->svc->myRankInCrew($m1, $now), 'Meiste Präsenz → Rang 1.');
        $this->assertSame(2, $this->svc->myRankInCrew($cap, $now));
        $this->assertSame(3, $this->svc->myRankInCrew($m2, $now), 'Kein Beitrag → letzter Rang.');
        $this->assertNull($this->svc->myRankInCrew($this->createUser('mr-solo'), $now), 'Solo → null.');
    }

    // ----------------------------------------------------------------
    // §12 — captain-lose Crew heilen (Self-Healing)
    // ----------------------------------------------------------------

    /** Macht den Captain einer Crew „ungültig" (Account gelöscht) → captain-los. */
    private function softDelete(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET status = "deleted" WHERE id = ?')->execute([$userId]);
    }

    public function testMePayloadExposesCaptainHandle(): void
    {
        $cap = $this->createUser('skipper');
        $this->svc->create($cap, 'Sailors');
        $this->assertSame('skipper', $this->svc->me($cap)['captain_handle']);
    }

    /** 12.a: captain-lose Crew + Mitglied bestimmt gültigen Member → wird Captain. */
    public function testClaimCaptainPromotesMemberWhenCaptainless(): void
    {
        $cap  = $this->createUser('gonecap');
        $mate = $this->createUser('newcap');
        $crew = $this->svc->create($cap, 'Orphans');
        $this->svc->join($mate, $crew['join_code']);
        $this->softDelete($cap); // Crew ist jetzt captain-los

        $this->assertNull($this->svc->me($mate)['captain_handle'], 'Vorbedingung: kein aktiver Captain.');

        $res = $this->svc->claimCaptain($mate, 'orphans', 'newcap');
        $this->assertSame('newcap', $res['captain_handle']);
        $this->assertSame('captain', $this->crews->membershipOf($mate)['role']);
        $this->assertSame('newcap', $this->svc->me($mate)['captain_handle']);
    }

    /** 12.b: Crew MIT Captain → 409, Captain unverändert. */
    public function testClaimCaptainConflictsWhenCaptainExists(): void
    {
        $cap  = $this->createUser('boss');
        $mate = $this->createUser('hijacker');
        $crew = $this->svc->create($cap, 'Solid');
        $this->svc->join($mate, $crew['join_code']);

        try {
            $this->svc->claimCaptain($mate, 'solid', 'hijacker');
            $this->fail('Mit existierendem Captain muss 409 kommen.');
        } catch (CrewException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame('captain', $this->crews->membershipOf($cap)['role']);
        $this->assertSame('member', $this->crews->membershipOf($mate)['role']);
    }

    /** 12.c: Handle ist kein Mitglied → 404, kein Wechsel. */
    public function testClaimCaptainUnknownHandleReturns404(): void
    {
        $cap  = $this->createUser('gone2');
        $mate = $this->createUser('member2');
        $crew = $this->svc->create($cap, 'Ghosts');
        $this->svc->join($mate, $crew['join_code']);
        $this->softDelete($cap);

        try {
            $this->svc->claimCaptain($mate, 'ghosts', 'doesnotexist');
            $this->fail('Unbekanntes Mitglied muss 404 werfen.');
        } catch (CrewException $e) {
            $this->assertSame(404, $e->status);
        }
        $this->assertSame('member', $this->crews->membershipOf($mate)['role'], 'Kein Wechsel.');
    }

    /** 12.d: Nicht-Mitglied (fremder Bearer) → 403. */
    public function testClaimCaptainNonMemberReturns403(): void
    {
        $cap       = $this->createUser('gone3');
        $mate      = $this->createUser('member3');
        $outsider  = $this->createUser('stranger');
        $crew = $this->svc->create($cap, 'Closed');
        $this->svc->join($mate, $crew['join_code']);
        $this->softDelete($cap);

        try {
            $this->svc->claimCaptain($outsider, 'closed', 'member3');
            $this->fail('Fremder darf keinen Captain bestimmen.');
        } catch (CrewException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    public function testClaimCaptainIsIdempotentOnDoubleClick(): void
    {
        $cap  = $this->createUser('gone4');
        $mate = $this->createUser('member4');
        $crew = $this->svc->create($cap, 'Doubles');
        $this->svc->join($mate, $crew['join_code']);
        $this->softDelete($cap);

        $this->svc->claimCaptain($mate, 'doubles', 'member4'); // erster Klick: OK
        try {
            $this->svc->claimCaptain($mate, 'doubles', 'member4'); // zweiter Klick: 409-Guard
            $this->fail('Zweiter Aufruf muss am 409-Guard scheitern.');
        } catch (CrewException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function testHealCaptainlessCrewsPromotesOldestMember(): void
    {
        $cap  = $this->createUser('healcap');
        $m1   = $this->createUser('heal1');
        $m2   = $this->createUser('heal2');
        $crew = $this->svc->create($cap, 'Healme');
        $this->svc->join($m1, $crew['join_code']);
        $this->svc->join($m2, $crew['join_code']);
        $this->softDelete($cap);

        $healed = $this->svc->healCaptainlessCrews();
        $this->assertCount(1, $healed);
        // Ältestes verbleibendes Mitglied (zuerst beigetreten) = m1.
        $this->assertSame($m1, $healed[0]['promoted_user_id']);
        $this->assertSame('captain', $this->crews->membershipOf($m1)['role']);
        $this->assertSame('heal1', $this->svc->me($m1)['captain_handle']);

        // Idempotent: zweiter Lauf findet nichts mehr.
        $this->assertSame([], $this->svc->healCaptainlessCrews());
    }

    public function testAccountDeletionPromotesOldestRemainingMember(): void
    {
        $cap = $this->createUser('delcap');
        $m1  = $this->createUser('del1');
        $m2  = $this->createUser('del2');
        $crew = $this->svc->create($cap, 'Leavers');
        $this->svc->join($m1, $crew['join_code']);
        $this->svc->join($m2, $crew['join_code']);

        $this->svc->handleAccountDeletion($cap);

        $this->assertNull($this->crews->membershipOf($cap), 'Gelöschter Captain ist kein Mitglied mehr.');
        $this->assertSame('captain', $this->crews->membershipOf($m1)['role'], 'Ältestes Mitglied wird Captain.');
        $this->assertSame(2, $this->crews->memberCount((int)$crew['id']));
        $this->assertTrue($this->crews->hasActiveCaptain((int)$crew['id']));
    }

    public function testAccountDeletionDissolvesSoloCrew(): void
    {
        $cap = $this->createUser('solocap');
        $this->svc->create($cap, 'Lonewolf');
        $this->svc->handleAccountDeletion($cap);
        $this->assertNull($this->crews->crewBySlug('lonewolf'), 'Solo-Crew wird aufgelöst.');
        $this->assertNull($this->crews->membershipOf($cap));
    }
}
