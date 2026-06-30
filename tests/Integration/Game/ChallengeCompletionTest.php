<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\Challenges\ChallengeService;
use App\Game\GameRepository;
use App\Support\Clock;
use Tests\IntegrationTestCase;

/**
 * Integration: GET /game/challenges hält Abschlüsse fest (Basis für die
 * Challenger-Abzeichen-Familie, RankBadges_Concept.md §5.2). Idempotent.
 */
final class ChallengeCompletionTest extends IntegrationTestCase
{
    private ChallengeService $svc;
    private GameRepository $repo;
    private int $u1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc  = new ChallengeService($this->pdo);
        $this->repo = new GameRepository($this->pdo);
        $this->u1   = $this->createUser('armin');
    }

    /** edge_new-Ereignis dieser Woche anlegen (zählt für „5 neue Kanten"). */
    private function seedNewEdge(int $edgeId): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_event (type, user_id, actor_user_id, edge_id, ridden_on)
             VALUES (\'edge_new\', ?, ?, ?, ?)'
        )->execute([$this->u1, $this->u1, $edgeId, Clock::nowUtc()->format('Y-m-d')]);
    }

    public function testReachingTargetRecordsCompletion(): void
    {
        $this->assertSame(0, $this->repo->completedChallengeCount($this->u1));

        // Noch nicht erfüllt (4 < 5) → kein Abschluss.
        for ($i = 1; $i <= 4; $i++) {
            $this->seedNewEdge($i);
        }
        $this->svc->forUser($this->u1, 'de');
        $this->assertSame(0, $this->repo->completedChallengeCount($this->u1));

        // Ziel erreicht (5) → genau ein Abschluss, auch bei mehrfachem Lesen.
        $this->seedNewEdge(5);
        $this->svc->forUser($this->u1, 'de');
        $this->svc->forUser($this->u1, 'en');
        $this->assertSame(1, $this->repo->completedChallengeCount($this->u1));
    }
}
