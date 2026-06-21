<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class EdgeRecalculatorCrewTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private EdgeRecalculator $recalc;
    private int $edgeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->recalc = new EdgeRecalculator($this->repo, new GameConfig($this->pdo));
        $a = $this->repo->upsertNode(20, 47.12, 9.65);
        $b = $this->repo->upsertNode(21, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $this->edgeId = $this->repo->upsertEdge(2001, $a, $b, 120.0, $geom, null, 47.12, 9.65, 47.13, 9.66);
    }

    private function pass(int $claimant, int $user, string $riddenAt): void
    {
        $on = substr($riddenAt, 0, 10);
        $this->repo->insertPassIfAbsent($this->edgeId, $claimant, $user, 1, $on, $riddenAt);
    }

    /** Legt eine Crew (Group-Claimant) an und macht $userIds zu Mitgliedern. Liefert die group-claimant-id. */
    private function makeCrew(array $userIds): int
    {
        $this->pdo->prepare('INSERT INTO game_claimant (type, user_id) VALUES ("group", NULL)')->execute();
        $claimantId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO game_crew (claimant_id, name, slug, owner_user_id, join_code) VALUES (?, ?, ?, ?, ?)'
        )->execute([$claimantId, 'Crew', 'crew-' . $claimantId, $userIds[0], substr('CD' . $claimantId . 'XXXXXX', 0, 8)]);
        $crewId = (int)$this->pdo->lastInsertId();
        foreach ($userIds as $i => $uid) {
            $this->pdo->prepare('INSERT INTO game_crew_member (user_id, crew_id, role) VALUES (?, ?, ?)')
                ->execute([$uid, $crewId, $i === 0 ? 'captain' : 'member']);
        }
        return $claimantId;
    }

    private function now(string $iso): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    public function testPresenceMovesToCrewOnJoin(): void
    {
        $u1 = $this->createUser('r1');
        $rider1 = $this->repo->riderClaimantId($u1);
        $this->pass($rider1, $u1, '2026-06-20 08:00:00.000');
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $this->now('2026-06-20T12:00:00Z'));
        $this->assertSame($rider1, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id']);

        // u1 tritt Crew bei -> gleiche Pässe, effektiver Claimant = Crew.
        $crew = $this->makeCrew([$u1]);
        $this->recalc->recalculate($this->edgeId, $this->now('2026-06-20T12:00:00Z'));
        $this->assertSame($crew, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id'],
            'Nach Beitritt gehört die Kante der Crew (Präsenz wandert mit).');
    }

    public function testGroupRideBonusFlipsOwnership(): void
    {
        // Amtsinhaber solo: 3 Pässe an 3 Tagen -> Präsenz ~2.96, mit Hysterese-Schwelle ~3.41.
        $us = $this->createUser('solo');
        $rs = $this->repo->riderClaimantId($us);
        $this->pass($rs, $us, '2026-06-20 08:00:00.000');
        $this->pass($rs, $us, '2026-06-19 08:00:00.000');
        $this->pass($rs, $us, '2026-06-18 08:00:00.000');
        $now = $this->now('2026-06-20T12:00:00Z');
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $now);
        $this->assertSame($rs, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id']);

        // Crew mit 3 Mitgliedern an EINEM Tag: roh ~2.99 (< 3.41, würde verlieren),
        // mit Gruppenfahrt-Bonus 1.5 -> ~4.49 (> 3.41) -> Crew übernimmt.
        $m1 = $this->createUser('m1'); $m2 = $this->createUser('m2'); $m3 = $this->createUser('m3');
        $this->pass($this->repo->riderClaimantId($m1), $m1, '2026-06-20 09:00:00.000');
        $this->pass($this->repo->riderClaimantId($m2), $m2, '2026-06-20 09:00:00.000');
        $this->pass($this->repo->riderClaimantId($m3), $m3, '2026-06-20 09:00:00.000');
        $crew = $this->makeCrew([$m1, $m2, $m3]);
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $now);

        $this->assertSame($crew, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id'],
            'Gruppenfahrt-Bonus hebt den Crew-Tagesbeitrag über die Hysterese-Schwelle.');
    }

    public function testTwoMembersNoBonusKeepsIncumbent(): void
    {
        $us = $this->createUser('solo2');
        $rs = $this->repo->riderClaimantId($us);
        $this->pass($rs, $us, '2026-06-20 08:00:00.000');
        $this->pass($rs, $us, '2026-06-19 08:00:00.000');
        $this->pass($rs, $us, '2026-06-18 08:00:00.000');
        $now = $this->now('2026-06-20T12:00:00Z');
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $now);
        $this->assertSame($rs, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id']);

        // Nur 2 Mitglieder -> unter min_members(3) -> kein Bonus -> ~2.0 < 3.41 -> Amtsinhaber bleibt.
        $m1 = $this->createUser('n1'); $m2 = $this->createUser('n2');
        $this->pass($this->repo->riderClaimantId($m1), $m1, '2026-06-20 09:00:00.000');
        $this->pass($this->repo->riderClaimantId($m2), $m2, '2026-06-20 09:00:00.000');
        $this->makeCrew([$m1, $m2]);
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $now);

        $this->assertSame($rs, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id'],
            'Unter der Mitglieder-Schwelle greift kein Bonus — Amtsinhaber bleibt.');
    }
}
