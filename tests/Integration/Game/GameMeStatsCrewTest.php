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
 * Regression: GET /game/me muss denselben (effektiven) Claimant verwenden wie
 * die Edge-Serialisierung (GET /game/edges). Sonst meldet ein Crew-Mitglied
 * held_edges=0, obwohl seine Kanten auf der Karte als owner_is_me=true
 * erscheinen (Kante gehört nach Crew-Beitritt dem Group-Claimant).
 */
final class GameMeStatsCrewTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameReadService $read;
    private EdgeRecalculator $recalc;
    private int $edgeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $config = new GameConfig($this->pdo);
        $this->read = new GameReadService($this->repo, $config);
        $this->recalc = new EdgeRecalculator($this->repo, $config);

        $a = $this->repo->upsertNode(30, 47.12, 9.65);
        $b = $this->repo->upsertNode(31, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $this->edgeId = $this->repo->upsertEdge(3001, $a, $b, 120.0, $geom, null, 47.12, 9.65, 47.13, 9.66);
    }

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

    /** @return int Anzahl Kanten mit owner_is_me=true im bbox um die Testkante. */
    private function ownerIsMeCount(int $viewerClaimantId): int
    {
        $edges = $this->read->edgesInBbox('9.6,47.1,9.7,47.2', $viewerClaimantId, null, 1000);
        return count(array_filter($edges, static fn($e) => $e['owner_is_me'] === true));
    }

    public function testHeldEdgesMatchesOwnerIsMeForCrewMember(): void
    {
        $now = new DateTimeImmutable('2026-06-20T12:00:00Z', new DateTimeZone('UTC'));

        $u = $this->createUser('rider');
        $rider = $this->repo->riderClaimantId($u);
        $this->repo->insertPassIfAbsent($this->edgeId, $rider, $u, 1, '2026-06-20', '2026-06-20 08:00:00.000');
        $this->repo->refreshEdgeDiscovery($this->edgeId);
        $this->recalc->recalculate($this->edgeId, $now);

        // Solo: Rider-Claimant hält die Kante.
        $this->assertSame($rider, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id']);
        $this->assertSame(1, $this->read->me($rider)['held_edges'], 'Solo-Fall unveraendert.');

        // u tritt einer Crew bei -> Praesenz wandert zum Group-Claimant.
        $crew = $this->makeCrew([$u]);
        $this->recalc->recalculate($this->edgeId, $now);
        $this->assertSame($crew, (int)$this->repo->edgeById($this->edgeId)['owner_claimant_id']);

        $effective = $this->repo->effectiveClaimantId($u);
        $this->assertSame($crew, $effective, 'effectiveClaimantId = Crew-Claimant fuer Mitglieder.');

        // Kern der Regression: ueber den EFFEKTIVEN Claimant stimmen
        // held_edges und die owner_is_me-Kanten ueberein (Erwartung: >=).
        $ownerIsMe = $this->ownerIsMeCount($effective);
        $this->assertSame(1, $ownerIsMe, 'Kante zeigt owner_is_me=true (effektiver Claimant).');
        $this->assertGreaterThanOrEqual(
            $ownerIsMe,
            $this->read->me($effective)['held_edges'],
            'held_edges muss >= owner_is_me-Kanten sein (effektiver Claimant).',
        );

        // Beleg fuer den Bug: ueber den Rider-Claimant (alter /game/me-Pfad)
        // waeren es 0 -> genau das darf der Controller NICHT mehr tun.
        $this->assertSame(0, $this->read->me($rider)['held_edges'],
            'Rider-Claimant zaehlt nach Crew-Beitritt 0 -> war der Fehler.');
    }
}
