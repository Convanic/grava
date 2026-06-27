<?php
declare(strict_types=1);

namespace Tests\Integration\Community;

use App\Community\CommunityTodayService;
use App\Presence\PresenceRepository;
use App\Presence\PresenceService;
use App\Game\GameConfig;
use App\Game\GameRepository;
use App\Routes\RouteRepository;
use App\Support\Clock;
use Tests\IntegrationTestCase;

/** Akzeptanzkriterien aus backend/COMMUNITY_TODAY_BACKEND.md §3. */
final class CommunityTodayServiceTest extends IntegrationTestCase
{
    private CommunityTodayService $community;
    private GameRepository $game;

    protected function setUp(): void
    {
        parent::setUp();
        $this->game = new GameRepository($this->pdo);
        $this->community = new CommunityTodayService(
            new RouteRepository(),
            new PresenceService(new PresenceRepository($this->pdo), new GameConfig($this->pdo)),
            $this->game,
        );
    }

    /** AK1: keine Routen heute → 0 / 0 / 0. */
    public function testEmptyDayReturnsZeros(): void
    {
        $res = $this->community->today();
        $this->assertSame(0, $res['rides_today']);
        $this->assertSame(0, $res['distance_today_m']);
        $this->assertSame(0, $res['edges_today']);
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
        $this->assertSame(
            ['rides_today', 'distance_today_m', 'active_now', 'edges_today'],
            array_keys($res),
        );
        $this->assertIsInt($res['rides_today']);
        $this->assertIsInt($res['distance_today_m']);
        $this->assertIsInt($res['active_now']);
        $this->assertIsInt($res['edges_today']);
    }

    /** AK6: dieselbe Kante heute von 3 Fahrten befahren → edges_today zählt sie einmal. */
    public function testEdgesTodayCountsDistinctEdges(): void
    {
        $edgeShared = $this->makeEdge(7001, 70, 71);
        $edgeOther = $this->makeEdge(7002, 72, 73);
        $today = Clock::nowUtc()->format('Y-m-d');
        $at = $today . ' 08:00:00.000';

        // drei verschiedene Fahrer befahren heute dieselbe Kante (Daycap je Fahrer/Tag)
        foreach (['r1', 'r2', 'r3'] as $name) {
            $this->passEdge($edgeShared, $name, $today, $at);
        }
        // eine zweite Kante heute → insgesamt 2 distinct
        $this->passEdge($edgeOther, 'r4', $today, $at);

        $res = $this->community->today();
        $this->assertSame(2, $res['edges_today']);
    }

    /** AK3 (Kanten): eine gestern befahrene Kante zählt nicht für edges_today. */
    public function testEdgesYesterdayExcluded(): void
    {
        $edge = $this->makeEdge(7100, 80, 81);
        $yesterday = Clock::nowUtc()->modify('-1 day')->format('Y-m-d');
        $this->passEdge($edge, 'old', $yesterday, $yesterday . ' 23:59:59.000');

        $res = $this->community->today();
        $this->assertSame(0, $res['edges_today']);
    }

    private function makeEdge(int $wayId, int $nodeAOsm, int $nodeBOsm): int
    {
        $a = $this->game->upsertNode($nodeAOsm, 47.12, 9.65);
        $b = $this->game->upsertNode($nodeBOsm, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        return $this->game->upsertEdge($wayId, $a, $b, 120.0, $geom, null, 47.12, 9.65, 47.13, 9.66);
    }

    private function passEdge(int $edgeId, string $userName, string $riddenOn, string $riddenAt): void
    {
        $uid = $this->createUser($userName);
        $cid = $this->game->riderClaimantId($uid);
        $this->game->insertPassIfAbsent($edgeId, $cid, $uid, 1, $riddenOn, $riddenAt);
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
