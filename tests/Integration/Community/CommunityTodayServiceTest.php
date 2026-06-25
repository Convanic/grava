<?php
declare(strict_types=1);

namespace Tests\Integration\Community;

use App\Community\CommunityTodayService;
use App\Presence\PresenceRepository;
use App\Presence\PresenceService;
use App\Game\GameConfig;
use App\Routes\RouteRepository;
use App\Support\Clock;
use Tests\IntegrationTestCase;

/** Akzeptanzkriterien aus backend/COMMUNITY_TODAY_BACKEND.md §3. */
final class CommunityTodayServiceTest extends IntegrationTestCase
{
    private CommunityTodayService $community;

    protected function setUp(): void
    {
        parent::setUp();
        $this->community = new CommunityTodayService(
            new RouteRepository(),
            new PresenceService(new PresenceRepository($this->pdo), new GameConfig($this->pdo)),
        );
    }

    /** AK1: keine Routen heute → 0 / 0. */
    public function testEmptyDayReturnsZeros(): void
    {
        $res = $this->community->today();
        $this->assertSame(0, $res['rides_today']);
        $this->assertSame(0, $res['distance_today_m']);
    }

    /** AK2: N Routen heute → rides_today = N, distance = Summe. */
    public function testCountsTodayRoutesAndDistance(): void
    {
        $user = $this->createUser('rider');
        $this->insertRoute($user, 1200, Clock::nowUtcString());
        $this->insertRoute($user, 3400, Clock::nowUtcString());

        $res = $this->community->today();
        $this->assertSame(2, $res['rides_today']);
        $this->assertSame(4600, $res['distance_today_m']);
    }

    /** AK2 (Variante): private Routen zählen mit (nur Summen, keine Leakage). */
    public function testPrivateRoutesCountTowardAggregate(): void
    {
        $user = $this->createUser('priv');
        $this->insertRoute($user, 800, Clock::nowUtcString(), 'private');

        $res = $this->community->today();
        $this->assertSame(1, $res['rides_today']);
        $this->assertSame(800, $res['distance_today_m']);
    }

    /** AK3: Route von gestern zählt nicht. */
    public function testYesterdayRouteExcluded(): void
    {
        $user = $this->createUser('old');
        $yesterday = Clock::nowUtc()->modify('-1 day')->format('Y-m-d') . ' 23:59:59';
        $this->insertRoute($user, 5000, $yesterday);

        $res = $this->community->today();
        $this->assertSame(0, $res['rides_today']);
        $this->assertSame(0, $res['distance_today_m']);
    }

    /** AK5: Antwort enthält nur Aggregat-Felder, keine Einzeldaten. */
    public function testResponseContainsOnlyAggregateFields(): void
    {
        $user = $this->createUser('agg');
        $this->insertRoute($user, 100, Clock::nowUtcString());

        $res = $this->community->today();
        $this->assertSame(['rides_today', 'distance_today_m', 'active_now'], array_keys($res));
        $this->assertIsInt($res['rides_today']);
        $this->assertIsInt($res['distance_today_m']);
        $this->assertIsInt($res['active_now']);
    }

    private function insertRoute(int $userId, int $distanceM, string $createdAt, string $visibility = 'public'): void
    {
        $this->pdo->prepare(
            'INSERT INTO routes
                (public_id, user_id, title, visibility, source, distance_m, centroid,
                 created_at, updated_at)
             VALUES (?, ?, ?, ?, "app", ?, ST_SRID(POINT(8.5, 49.5), 4326), ?, ?)'
        )->execute([
            self::uuid4(),
            $userId,
            'Community Test',
            $visibility,
            $distanceM,
            $createdAt,
            $createdAt,
        ]);
    }
}
