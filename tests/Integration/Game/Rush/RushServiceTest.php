<?php
declare(strict_types=1);

namespace Tests\Integration\Game\Rush;

use App\Game\Admin\GameAuditService;
use App\Game\Crew\CrewRepository;
use App\Game\Crew\CrewService;
use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Game\Rush\RushException;
use App\Game\Rush\RushRepository;
use App\Game\Rush\RushService;
use App\Routes\GeometryParser;
use App\Routes\ParsedRoute;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/**
 * Akzeptanztests Rush (GAME_RUSH_BACKEND.md §9): Lifecycle + Guards, der
 * server-kanonische Multiplikator auf dem Recompute-Pfad und die
 * Orthogonalität (rush_enabled=0 ⇒ bit-identisch zum Zustand ohne Rush).
 */
final class RushServiceTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private CrewRepository $crews;
    private RushRepository $rushes;
    private EdgeRecalculator $recalc;
    private CrewService $crewSvc;
    private RushService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $cfg = new GameConfig($this->pdo);
        $this->repo   = new GameRepository($this->pdo);
        $this->crews  = new CrewRepository($this->pdo);
        $this->rushes = new RushRepository($this->pdo);
        $this->recalc = new EdgeRecalculator($this->repo, $cfg);
        $this->crewSvc = new CrewService(
            $this->pdo, $this->crews, $this->repo, $this->recalc, $cfg, new GameAuditService($this->pdo),
        );
        $this->svc = new RushService(
            $this->pdo, $this->rushes, $this->crews, $this->repo, $this->recalc, $cfg,
            null, new GameAuditService($this->pdo),
        );
    }

    private function now(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    private function setConfig(string $key, string $value): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_config (config_key, config_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        )->execute([$key, $value]);
    }

    /** Frische Services nach einer Config-Änderung (GameConfig cached lazy). */
    private function rebuild(): void
    {
        $cfg = new GameConfig($this->pdo);
        $this->recalc = new EdgeRecalculator($this->repo, $cfg);
        $this->svc = new RushService(
            $this->pdo, $this->rushes, $this->crews, $this->repo, $this->recalc, $cfg,
            null, new GameAuditService($this->pdo),
        );
    }

    private function makeEdge(int $extId = 4001): int
    {
        $a = $this->repo->upsertNode($extId * 10, 47.12, 9.65);
        $b = $this->repo->upsertNode($extId * 10 + 1, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        return $this->repo->upsertEdge($extId, $a, $b, 120.0, $geom, null, 47.12, 9.65, 47.13, 9.66);
    }

    /** Crew mit Captain + ($extra) Mitgliedern. @return array{0:int,1:list<int>,2:int} [crewClaimant, userIds, crewId] */
    private function makeCrew(int $extra, DateTimeImmutable $now): array
    {
        $cap = $this->createUser('cap-' . bin2hex(random_bytes(2)));
        $crew = $this->crewSvc->create($cap, 'Crew ' . bin2hex(random_bytes(2)), $now);
        $users = [$cap];
        for ($i = 0; $i < $extra; $i++) {
            $m = $this->createUser('m-' . bin2hex(random_bytes(2)));
            $this->crewSvc->join($m, $crew['join_code'], $now);
            $users[] = $m;
        }
        return [(int)$crew['claimant_id'], $users, (int)$crew['id']];
    }

    // --- §5/§9: Anlegen-Guards ----------------------------------------

    public function testCreateRequiresCaptain(): void
    {
        $now = $this->now('2026-06-20T08:00:00Z');
        [, $users] = $this->makeCrew(1, $now);
        $member = $users[1]; // Nicht-Captain

        try {
            $this->svc->create($member, '2026-06-20T18:00:00Z', null, null, null, $now);
            $this->fail('Nicht-Captain darf keinen Rush anlegen.');
        } catch (RushException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    public function testCreateRejectsPastStart(): void
    {
        $now = $this->now('2026-06-20T12:00:00Z');
        [, $users] = $this->makeCrew(0, $now);
        try {
            $this->svc->create($users[0], '2026-06-20T10:00:00Z', null, null, null, $now);
            $this->fail('start_at in der Vergangenheit muss 422 werfen.');
        } catch (RushException $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function testCreateRejectsOverlap(): void
    {
        $now = $this->now('2026-06-20T08:00:00Z');
        [, $users] = $this->makeCrew(0, $now);
        $cap = $users[0];
        $this->svc->create($cap, '2026-06-20T18:00:00Z', 4, null, null, $now);
        try {
            $this->svc->create($cap, '2026-06-20T20:00:00Z', 4, null, null, $now);
            $this->fail('Überlappender Rush muss 409 werfen.');
        } catch (RushException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function testCreateRespectsCooldown(): void
    {
        $now = $this->now('2026-06-20T08:00:00Z');
        [, $users] = $this->makeCrew(0, $now);
        $cap = $users[0];
        // cooldown_days=7 (Default). Erster Rush morgen, zweiter 2 Tage später -> 409.
        $this->svc->create($cap, '2026-06-21T18:00:00Z', 4, null, null, $now);
        try {
            $this->svc->create($cap, '2026-06-23T18:00:00Z', 4, null, null, $now);
            $this->fail('Innerhalb des Cooldowns muss 409 kommen.');
        } catch (RushException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    // --- §5.3/§5.4: RSVP + Cancel -------------------------------------

    public function testRsvpUpsertAndMembershipGuard(): void
    {
        $now = $this->now('2026-06-20T08:00:00Z');
        [, $users] = $this->makeCrew(1, $now);
        $rush = $this->svc->create($users[0], '2026-06-20T18:00:00Z', 4, null, null, $now);
        $rid = (int)$rush['id'];

        $res = $this->svc->rsvp($users[1], $rid, 'yes', $now);
        $this->assertSame(1, $res['rush']['participants_confirmed']);

        // Erneutes RSVP überschreibt (kein Duplikat).
        $res = $this->svc->rsvp($users[1], $rid, 'no', $now);
        $this->assertSame(0, $res['rush']['participants_confirmed']);

        $outsider = $this->createUser('outsider');
        try {
            $this->svc->rsvp($outsider, $rid, 'yes', $now);
            $this->fail('Fremde dürfen nicht zu-/absagen.');
        } catch (RushException $e) {
            $this->assertSame(403, $e->status);
        }
    }

    public function testCancelOnlyPlannedByCaptain(): void
    {
        $now = $this->now('2026-06-20T08:00:00Z');
        [, $users] = $this->makeCrew(1, $now);
        $rush = $this->svc->create($users[0], '2026-06-20T18:00:00Z', 4, null, null, $now);
        $rid = (int)$rush['id'];

        try {
            $this->svc->cancel($users[1], $rid);
            $this->fail('Nur der Captain darf abbrechen.');
        } catch (RushException $e) {
            $this->assertSame(403, $e->status);
        }

        $this->svc->cancel($users[0], $rid);
        $this->assertSame('cancelled', $this->rushes->byId($rid)['status']);
    }

    // --- §4: Lifecycle-Tick -------------------------------------------

    public function testTickActivatesThenCompletesWhenQualified(): void
    {
        $created = $this->now('2026-06-20T08:00:00Z');
        [$crewClaimant, $users] = $this->makeCrew(2, $created); // 3 Mitglieder
        $rush = $this->svc->create($users[0], '2026-06-20T10:00:00Z', 4, null, null, $created);
        $rid = (int)$rush['id'];

        // 3 getaggte Pässe (qualifiziert: >= rush_min_crew_size=3).
        $edge = $this->makeEdge();
        foreach ($users as $u) {
            $this->repo->insertPassIfAbsent(
                $edge, $this->repo->riderClaimantId($u), $u, 1, '2026-06-20', '2026-06-20 11:00:00.000', $rid,
            );
        }

        // Während des Fensters: planned -> active.
        $stats = $this->svc->tick($this->now('2026-06-20T10:30:00Z'));
        $this->assertSame(1, $stats['activated']);
        $this->assertSame('active', $this->rushes->byId($rid)['status']);

        // Nach Fensterende: active -> completed (qualifiziert).
        $stats = $this->svc->tick($this->now('2026-06-20T14:30:00Z'));
        $this->assertSame(1, $stats['completed']);
        $this->assertSame('completed', $this->rushes->byId($rid)['status']);
    }

    public function testTickExpiresWhenUnderMinCrew(): void
    {
        $created = $this->now('2026-06-20T08:00:00Z');
        [, $users] = $this->makeCrew(2, $created);
        $rush = $this->svc->create($users[0], '2026-06-20T10:00:00Z', 4, null, null, $created);
        $rid = (int)$rush['id'];

        // Nur EIN getaggter Fahrer -> unter min_crew_size -> expired.
        $edge = $this->makeEdge(4101);
        $this->repo->insertPassIfAbsent(
            $edge, $this->repo->riderClaimantId($users[0]), $users[0], 1, '2026-06-20', '2026-06-20 11:00:00.000', $rid,
        );

        $stats = $this->svc->tick($this->now('2026-06-20T14:30:00Z'));
        $this->assertSame(1, $stats['expired']);
        $this->assertSame('expired', $this->rushes->byId($rid)['status']);
    }

    // --- §3/§9.7: Multiplikator + Orthogonalität ----------------------

    public function testRushMultiplierFlipsOwnershipAndIsOrthogonalWhenDisabled(): void
    {
        // Gruppenfahrt-Bonus deaktivieren, damit der EINZIGE Hebel der Rush
        // ist: die Crew gewinnt nur über den Multiplikator, nicht über den Bonus.
        $this->setConfig('group_ride_min_members', '99');
        $this->rebuild();

        $now = $this->now('2026-06-20T12:00:00Z');
        $edge = $this->makeEdge(4201);

        // Amtsinhaber solo: 3 Pässe an 3 Tagen -> Präsenz ~2.96 (hält ohne Rush).
        $solo = $this->createUser('incumbent');
        $rs = $this->repo->riderClaimantId($solo);
        foreach (['2026-06-20', '2026-06-19', '2026-06-18'] as $d) {
            $this->repo->insertPassIfAbsent($edge, $rs, $solo, 1, $d, $d . ' 08:00:00.000');
        }
        $this->repo->refreshEdgeDiscovery($edge);
        $this->recalc->recalculate($edge, $now);
        $this->assertSame($rs, (int)$this->repo->edgeById($edge)['owner_claimant_id']);

        // Crew (3) mit getaggten Pässen an EINEM Tag: roh ~2.99 < Hysterese ~3.41.
        [$crewClaimant, $users] = $this->makeCrew(2, $now);
        $rush = $this->svc->create($users[0], '2026-06-20T18:00:00Z', 4, null, null, $now);
        $rid = (int)$rush['id'];
        foreach ($users as $u) {
            $this->repo->insertPassIfAbsent(
                $edge, $this->repo->riderClaimantId($u), $u, 1, '2026-06-20', '2026-06-20 09:00:00.000', $rid,
            );
        }
        $this->repo->refreshEdgeDiscovery($edge);

        // (a) Rush AUS -> roh 2.99 < 3.41 -> Amtsinhaber bleibt (Orthogonalität).
        $this->setConfig('rush_enabled', '0');
        $this->rebuild();
        $this->recalc->recalculate($edge, $now);
        $this->assertSame($rs, (int)$this->repo->edgeById($edge)['owner_claimant_id'],
            'Ohne Rush bleibt der Amtsinhaber — Rush ist orthogonal.');

        // (b) Rush AN -> 2.99 * 2.0 = 5.98 > 3.41 -> Crew übernimmt.
        $this->setConfig('rush_enabled', '1');
        $this->rebuild();
        $this->recalc->recalculate($edge, $now);
        $this->assertSame($crewClaimant, (int)$this->repo->edgeById($edge)['owner_claimant_id'],
            'Der Rush-Multiplikator hebt den Crew-Tagesbeitrag über die Hysterese-Schwelle.');
    }

    public function testMyRushDtoExposesLiveMetrics(): void
    {
        $now = $this->now('2026-06-20T12:00:00Z');
        [$crewClaimant, $users] = $this->makeCrew(2, $now);
        $rush = $this->svc->create($users[0], '2026-06-20T18:00:00Z', 4, 47.5, 9.5, $now);
        $rid = (int)$rush['id'];

        $edge = $this->makeEdge(4301);
        foreach ($users as $u) {
            $this->repo->insertPassIfAbsent(
                $edge, $this->repo->riderClaimantId($u), $u, 1, '2026-06-20', '2026-06-20 13:00:00.000', $rid,
            );
        }

        $detail = $this->svc->myRush($users[0], $now);
        $this->assertNotNull($detail);
        $this->assertSame($rid, $detail['rush']['id']);
        $this->assertSame(3, $detail['rush']['participants_ridden']);
        $this->assertTrue($detail['rush']['qualified']);
        $this->assertSame(47.5, $detail['rush']['meetup_lat']);
        $this->assertStringEndsWith('Z', $detail['rush']['start_at']);
    }

    // --- §9.8: window_hours-Cap ---------------------------------------

    public function testWindowHoursIsCappedAtMax(): void
    {
        $now = $this->now('2026-06-20T08:00:00Z');
        [, $users] = $this->makeCrew(0, $now);
        // window_hours=99 wird auf rush_window_hours_max=12 gedeckelt.
        $rush = $this->svc->create($users[0], '2026-06-20T18:00:00Z', 99, null, null, $now);
        $start = new DateTimeImmutable($rush['start_at']);
        $end   = new DateTimeImmutable($rush['end_at']);
        $this->assertSame(12 * 3600, $end->getTimestamp() - $start->getTimestamp());
    }

    // --- §9.1: Auto-Tag im Fenster ------------------------------------

    public function testAutoTagTagsPassesInsideWindowOnly(): void
    {
        // Rückwirkungs-Schutz aus, damit das Fenster der einzige Hebel ist.
        $this->setConfig('rush_requires_announcement', '0');
        $this->rebuild();
        $cfg = new GameConfig($this->pdo);

        $rider = $this->createUser('tagger');
        $crew  = $this->crewSvc->create($rider, 'Taggers', $this->now('2026-06-20T07:00:00Z'));
        // Rush-Fenster [10:00,14:00] direkt anlegen (status active, deterministisch).
        $rid = $this->rushes->create(
            (int)$crew['id'], $rider, '2026-06-20 10:00:00.000', '2026-06-20 14:00:00.000', 2.0, null, null,
        );
        $this->rushes->setStatus($rid, 'active');

        // Zwei Segmente: 11:00 (im Fenster) und 09:00 (davor).
        $segs = [
            new MatchedSegment(5001, 50, 51, 120.0, [[9.65, 47.12], [9.66, 47.13]], 'gravel',
                18.0, 8.0, true, new DateTimeImmutable('2026-06-20 11:00:00', new DateTimeZone('UTC'))),
            new MatchedSegment(5002, 51, 52, 120.0, [[9.66, 47.13], [9.67, 47.14]], 'gravel',
                18.0, 8.0, true, new DateTimeImmutable('2026-06-20 09:00:00', new DateTimeZone('UTC'))),
        ];
        $route = (new GeometryParser())->parse(
            '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13],[9.67,47.14]]}'
        );
        $svc = new GameIngestionService(
            new FakeEdgeMatcher($segs), $this->repo, new EdgeRecalculator($this->repo, $cfg), $cfg, $this->pdo,
        );
        $svc->ingest(1, $rider, $route, true, $this->now('2026-06-20T15:00:00Z'));

        $rows = $this->pdo->query(
            'SELECT ridden_at, rush_id FROM game_edge_pass ORDER BY ridden_at'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]['rush_id'], 'Pass um 09:00 (vor dem Fenster) bleibt ungetaggt.');
        $this->assertSame($rid, (int)$rows[1]['rush_id'], 'Pass um 11:00 (im Fenster) wird getaggt.');
    }

    // --- §9.6: RSVP ohne Scoring-Wirkung ------------------------------

    public function testRsvpDoesNotAffectOwnership(): void
    {
        $this->setConfig('group_ride_min_members', '99'); // Bonus aus → nur Rush zählt
        $this->rebuild();
        $now = $this->now('2026-06-20T12:00:00Z');
        $edge = $this->makeEdge(4401);

        $solo = $this->createUser('inc');
        $rs = $this->repo->riderClaimantId($solo);
        foreach (['2026-06-20', '2026-06-19', '2026-06-18'] as $d) {
            $this->repo->insertPassIfAbsent($edge, $rs, $solo, 1, $d, $d . ' 08:00:00.000');
        }
        [$crewClaimant, $users] = $this->makeCrew(2, $now);
        $rush = $this->svc->create($users[0], '2026-06-20T18:00:00Z', 4, null, null, $now);
        $rid = (int)$rush['id'];
        foreach ($users as $u) {
            $this->repo->insertPassIfAbsent(
                $edge, $this->repo->riderClaimantId($u), $u, 1, '2026-06-20', '2026-06-20 09:00:00.000', $rid,
            );
        }
        $this->repo->refreshEdgeDiscovery($edge);

        // ALLE sagen ab — Besitz hängt allein an den getaggten Pässen, nicht am RSVP.
        foreach ($users as $u) {
            $this->svc->rsvp($u, $rid, 'no', $now);
        }
        $this->recalc->recalculate($edge, $now);
        $this->assertSame($crewClaimant, (int)$this->repo->edgeById($edge)['owner_claimant_id'],
            'RSVP=no ändert nichts: gefahrene getaggte Pässe zählen voll.');
    }

    // --- §9.3: Multiplikator stapelt mit Gruppenfahrt-Bonus -----------

    public function testStacksWithGroupBonus(): void
    {
        $now = $this->now('2026-06-20T12:00:00Z');
        $edge = $this->makeEdge(4501);

        // Starker Amtsinhaber: 6 Tagespässe → Präsenz ~5.82, Schwelle ~6.69.
        $solo = $this->createUser('strong');
        $rs = $this->repo->riderClaimantId($solo);
        foreach (['2026-06-20','2026-06-19','2026-06-18','2026-06-17','2026-06-16','2026-06-15'] as $d) {
            $this->repo->insertPassIfAbsent($edge, $rs, $solo, 1, $d, $d . ' 08:00:00.000');
        }
        $this->repo->refreshEdgeDiscovery($edge);
        $this->recalc->recalculate($edge, $now); // Amtsinhaber etablieren (Hysterese greift)
        $this->assertSame($rs, (int)$this->repo->edgeById($edge)['owner_claimant_id']);

        [$crewClaimant, $users] = $this->makeCrew(2, $now); // group_ride_min_members=3 → Bonus 1.5 greift
        $rush = $this->svc->create($users[0], '2026-06-20T18:00:00Z', 4, null, null, $now);
        $rid = (int)$rush['id'];
        foreach ($users as $u) {
            $this->repo->insertPassIfAbsent(
                $edge, $this->repo->riderClaimantId($u), $u, 1, '2026-06-20', '2026-06-20 09:00:00.000', $rid,
            );
        }
        $this->repo->refreshEdgeDiscovery($edge);

        // (a) replace (Default): 2.0 × 2.996 = 5.99 < 6.69 → Amtsinhaber bleibt.
        $this->setConfig('rush_stacks_with_group_bonus', '0');
        $this->rebuild();
        $this->recalc->recalculate($edge, $now);
        $this->assertSame($rs, (int)$this->repo->edgeById($edge)['owner_claimant_id']);

        // (b) stack: 2.0 × 1.5 × 2.996 = 8.99 > 6.69 → Crew übernimmt.
        $this->setConfig('rush_stacks_with_group_bonus', '1');
        $this->rebuild();
        $this->recalc->recalculate($edge, $now);
        $this->assertSame($crewClaimant, (int)$this->repo->edgeById($edge)['owner_claimant_id'],
            'Mit Stacking multiplizieren sich Rush-Multiplikator und Gruppenfahrt-Bonus.');
    }

    // --- §9.9: Edge-Cap (deterministisch nach edge_id) ----------------

    public function testEdgeCapAppliesMultiplierToFirstNEdgesOnly(): void
    {
        $this->setConfig('group_ride_min_members', '99'); // Bonus aus
        $this->setConfig('rush_max_edges_per_rush', '1');  // nur 1 Kante bekommt den Multiplikator
        $this->rebuild();
        $now = $this->now('2026-06-20T12:00:00Z');

        $edgeLow  = $this->makeEdge(4601); // kleinere edge_id → bevorzugt
        $edgeHigh = $this->makeEdge(4602);

        [$crewClaimant, $users] = $this->makeCrew(2, $now);
        $rush = $this->svc->create($users[0], '2026-06-20T18:00:00Z', 4, null, null, $now);
        $rid = (int)$rush['id'];

        foreach ([$edgeLow, $edgeHigh] as $edge) {
            // Amtsinhaber solo (3 Tage) zuerst als Besitzer etablieren …
            $solo = $this->createUser('inc-' . $edge);
            $rsx = $this->repo->riderClaimantId($solo);
            foreach (['2026-06-20', '2026-06-19', '2026-06-18'] as $d) {
                $this->repo->insertPassIfAbsent($edge, $rsx, $solo, 1, $d, $d . ' 08:00:00.000');
            }
            $this->repo->refreshEdgeDiscovery($edge);
            $this->recalc->recalculate($edge, $now);
            // … dann die Crew (3 getaggte Pässe, 1 Tag) herausfordern.
            foreach ($users as $u) {
                $this->repo->insertPassIfAbsent(
                    $edge, $this->repo->riderClaimantId($u), $u, 1, '2026-06-20', '2026-06-20 09:00:00.000', $rid,
                );
            }
            $this->repo->refreshEdgeDiscovery($edge);
            $this->recalc->recalculate($edge, $now);
        }

        $this->assertSame($crewClaimant, (int)$this->repo->edgeById($edgeLow)['owner_claimant_id'],
            'Die erste (kleinste edge_id) Kante bekommt den Multiplikator.');
        $this->assertNotSame($crewClaimant, (int)$this->repo->edgeById($edgeHigh)['owner_claimant_id'],
            'Über dem Cap liegende Kante bleibt regulär (kein Multiplikator).');
    }

    // --- §9.10: Abbruch/Löschen neutralisiert den Multiplikator -------

    public function testCancelAndDeleteNeutralizeMultiplier(): void
    {
        $this->setConfig('group_ride_min_members', '99');
        $this->rebuild();
        $now = $this->now('2026-06-20T12:00:00Z');
        $edge = $this->makeEdge(4701);

        // 4 Tagespässe → Roh-Präsenz ~3.94 > Crew-Roh ~2.99: nach dem Abbruch
        // (Multiplikator weg) gewinnt der Amtsinhaber die Hysterese zurück.
        $solo = $this->createUser('inc2');
        $rs = $this->repo->riderClaimantId($solo);
        foreach (['2026-06-20', '2026-06-19', '2026-06-18', '2026-06-17'] as $d) {
            $this->repo->insertPassIfAbsent($edge, $rs, $solo, 1, $d, $d . ' 08:00:00.000');
        }
        $this->repo->refreshEdgeDiscovery($edge);
        $this->recalc->recalculate($edge, $now); // Amtsinhaber etablieren
        $this->assertSame($rs, (int)$this->repo->edgeById($edge)['owner_claimant_id']);

        [$crewClaimant, $users] = $this->makeCrew(2, $now);
        $rush = $this->svc->create($users[0], '2026-06-20T18:00:00Z', 4, null, null, $now);
        $rid = (int)$rush['id'];
        foreach ($users as $u) {
            $this->repo->insertPassIfAbsent(
                $edge, $this->repo->riderClaimantId($u), $u, 1, '2026-06-20', '2026-06-20 09:00:00.000', $rid,
            );
        }
        $this->repo->refreshEdgeDiscovery($edge);
        $this->recalc->recalculate($edge, $now);
        $this->assertSame($crewClaimant, (int)$this->repo->edgeById($edge)['owner_claimant_id'], 'Vorbedingung: Rush hat übernommen.');

        // Abbruch (planned→cancelled): cancelled zählt im Gate nicht mehr.
        $this->svc->cancel($users[0], $rid);
        $this->recalc->recalculate($edge, $now);
        $this->assertSame($rs, (int)$this->repo->edgeById($edge)['owner_claimant_id'],
            'Nach Abbruch fällt der Multiplikator weg, Besitz rechnet sauber.');

        // Hartes Löschen → ON DELETE SET NULL neutralisiert rush_id der Pässe.
        $this->pdo->prepare('DELETE FROM game_rush WHERE id = ?')->execute([$rid]);
        $remaining = $this->pdo->prepare(
            'SELECT COUNT(*) FROM game_edge_pass WHERE rush_id = ?'
        );
        $remaining->execute([$rid]);
        $this->assertSame(0, (int)$remaining->fetchColumn(), 'ON DELETE SET NULL: keine Pässe verweisen mehr auf den gelöschten Rush.');
    }

    // --- §13: Rush-Hinweis (Freitext note) ----------------------------

    /**
     * §13.a — note wird getrimmt/auf 280 Zeichen gekappt (nicht abgelehnt),
     * leer → null, und erscheint im DTO (create + GET …/rush).
     */
    public function testNoteTrimsCapsAndExposesInDto(): void
    {
        $now = $this->now('2026-06-20T08:00:00Z');
        [, $users] = $this->makeCrew(0, $now);
        $cap = $users[0];

        // (1) Normaler Hinweis: getrimmt im DTO.
        $rush = $this->svc->create($cap, '2026-06-20T18:00:00Z', 4, null, null, $now, '  Treffpunkt am Brunnen  ');
        $this->assertSame('Treffpunkt am Brunnen', $rush['note']);
        // Auch über GET …/rush sichtbar.
        $detail = $this->svc->myRush($cap, $now);
        $this->assertSame('Treffpunkt am Brunnen', $detail['rush']['note']);

        // (2) Kein Hinweis → null.
        [, $users2] = $this->makeCrew(0, $now);
        $rush2 = $this->svc->create($users2[0], '2026-06-20T18:00:00Z', 4, null, null, $now);
        $this->assertNull($rush2['note']);

        // (3) Leer/Whitespace → null.
        [, $users3] = $this->makeCrew(0, $now);
        $rush3 = $this->svc->create($users3[0], '2026-06-20T18:00:00Z', 4, null, null, $now, '   ');
        $this->assertNull($rush3['note']);

        // (4) 281 Zeichen → auf 280 gekappt, nicht abgelehnt.
        [, $users4] = $this->makeCrew(0, $now);
        $long = str_repeat('x', 281);
        $rush4 = $this->svc->create($users4[0], '2026-06-20T18:00:00Z', 4, null, null, $now, $long);
        $this->assertSame(280, mb_strlen((string)$rush4['note']));
    }
}
