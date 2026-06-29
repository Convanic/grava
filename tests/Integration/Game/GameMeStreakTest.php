<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameConfig;
use App\Game\GameReadService;
use App\Game\GameRepository;
use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/**
 * GET /game/me liefert die Wochen-Serie additiv aus game_edge_pass
 * (GAME_EVENTS_BACKEND.md Teil 2). me() verwendet die reale Uhr, daher werden
 * die Pässe relativ zur aktuellen ISO-Woche eingetragen (robust über die Zeit).
 */
final class GameMeStreakTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameReadService $read;
    private int $edgeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->read = new GameReadService($this->repo, new GameConfig($this->pdo));

        $a = $this->repo->upsertNode(50, 47.12, 9.65);
        $b = $this->repo->upsertNode(51, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $this->edgeId = $this->repo->upsertEdge(5001, $a, $b, 120.0, $geom, null, 47.12, 9.65, 47.13, 9.66);
    }

    /** Montag (UTC) der ISO-Woche von „heute" minus $weeksAgo Wochen. */
    private function mondayWeeksAgo(int $weeksAgo): DateTimeImmutable
    {
        $now = Clock::nowUtc();
        $offset = (int)$now->format('N') - 1;
        return $now->setTime(0, 0, 0)->modify("-{$offset} days")->modify('-' . (7 * $weeksAgo) . ' days');
    }

    private function ridePass(int $userId, int $claimantId, DateTimeImmutable $monday): void
    {
        // Mittwoch der Woche als Fahrtdatum (irgendein Tag der Woche genügt).
        $day = $monday->modify('+2 days');
        $this->repo->insertPassIfAbsent(
            $this->edgeId,
            $claimantId,
            $userId,
            1,
            $day->format('Y-m-d'),
            $day->format('Y-m-d H:i:s.v'),
        );
    }

    public function testNoRidesYieldsZeroStreak(): void
    {
        $u = $this->createUser('norides');
        $claimant = $this->repo->riderClaimantId($u);
        $me = $this->read->me($claimant, $u);

        $this->assertSame(0, $me['streak_weeks']);
        $this->assertFalse($me['streak_active_this_week']);
    }

    public function testFourConsecutiveWeeksCountAndActive(): void
    {
        $u = $this->createUser('streaky');
        $claimant = $this->repo->riderClaimantId($u);

        foreach ([0, 1, 2, 3] as $w) {
            $this->ridePass($u, $claimant, $this->mondayWeeksAgo($w));
        }

        $me = $this->read->me($claimant, $u);
        $this->assertSame(4, $me['streak_weeks']);
        $this->assertTrue($me['streak_active_this_week']);
        $this->assertArrayHasKey('longest_streak_weeks', $me);
        $this->assertArrayHasKey('streak_grace_remaining', $me);
    }

    public function testCurrentWeekOpenIsNotActiveButPreviousWeeksCount(): void
    {
        $u = $this->createUser('openweek');
        $claimant = $this->repo->riderClaimantId($u);

        // Nur Vorwoche + davor — laufende Woche bleibt offen.
        foreach ([1, 2] as $w) {
            $this->ridePass($u, $claimant, $this->mondayWeeksAgo($w));
        }

        $me = $this->read->me($claimant, $u);
        $this->assertFalse($me['streak_active_this_week']);
        $this->assertSame(2, $me['streak_weeks']);
    }
}
