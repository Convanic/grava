<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameConfig;
use App\Game\GameRepository;
use App\Game\PlayerLeaderboardService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class PlayerLeaderboardTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private PlayerLeaderboardService $svc;
    private int $u1;
    private int $u2;
    private int $u3;
    private int $r1;
    private int $r2;
    private int $r3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->svc  = new PlayerLeaderboardService($this->repo, new GameConfig($this->pdo));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-20T12:00:00Z', new DateTimeZone('UTC'));
    }

    private function edge(int $wayId, int $nodeBase, float $lengthM, float $value): int
    {
        $a = $this->repo->upsertNode($nodeBase, 47.12, 9.65);
        $b = $this->repo->upsertNode($nodeBase + 1, 47.13, 9.66);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[9.65, 47.12], [9.66, 47.13]]]);
        $id = $this->repo->upsertEdge($wayId, $a, $b, $lengthM, $geom, null, 47.12, 9.65, 47.13, 9.66);
        $this->pdo->prepare('UPDATE game_edge SET value_cached = ? WHERE id = ?')->execute([$value, $id]);
        return $id;
    }

    private function pass(int $edge, int $claimant, int $user, int $route, string $riddenAt): void
    {
        $this->repo->insertPassIfAbsent($edge, $claimant, $user, $route, substr($riddenAt, 0, 10), $riddenAt);
    }

    private function follow(int $follower, int $followee): void
    {
        $this->pdo->prepare('INSERT INTO follows (follower_id, followee_id) VALUES (?, ?)')
            ->execute([$follower, $followee]);
    }

    /** Standard-Szenario: 3 Fahrer, 3 Kanten, Pässe im Saison-Fenster. */
    private function seed(): void
    {
        $this->u1 = $this->createUser('a');
        $this->u2 = $this->createUser('b');
        $this->u3 = $this->createUser('c');
        $this->r1 = $this->repo->riderClaimantId($this->u1);
        $this->r2 = $this->repo->riderClaimantId($this->u2);
        $this->r3 = $this->repo->riderClaimantId($this->u3);

        $e1 = $this->edge(7001, 70, 100.0, 10.0);
        $e2 = $this->edge(7002, 72, 200.0, 20.0);
        $e3 = $this->edge(7003, 74, 300.0, 30.0);

        // e1: u1 & u2 je 1 Tag -> Tie -> u1 (kleinere id) hält.
        $this->pass($e1, $this->r1, $this->u1, 100, '2026-06-20 08:00:00.000');
        $this->pass($e1, $this->r2, $this->u2, 200, '2026-06-20 08:00:00.000');
        // e2: u2 an 2 Tagen (D, D-1), u1 1 Tag -> u2 hält; u2 zuerst (D-1).
        $this->pass($e2, $this->r1, $this->u1, 101, '2026-06-20 08:00:00.000');
        $this->pass($e2, $this->r2, $this->u2, 201, '2026-06-20 08:00:00.000');
        $this->pass($e2, $this->r2, $this->u2, 202, '2026-06-19 08:00:00.000');
        // e3: nur u3.
        $this->pass($e3, $this->r3, $this->u3, 301, '2026-06-20 08:00:00.000');
    }

    public function testWorldAreaAnonymous(): void
    {
        $this->seed();
        $res = $this->svc->leaderboard('world', 'season', 'area', null, $this->now());

        $this->assertNull($res['me']);
        $this->assertCount(3, $res['entries']);
        // Rang fortlaufend, value absteigend: u3(300) > u2(200) > u1(100).
        $this->assertSame([1, 2, 3], array_column($res['entries'], 'rank'));
        $this->assertSame([300.0, 200.0, 100.0], array_column($res['entries'], 'value'));
        $this->assertSame(['c', 'b', 'a'], array_column($res['entries'], 'handle'));
        foreach ($res['entries'] as $e) {
            $this->assertFalse($e['is_me']);
        }
    }

    public function testWorldAreaWithBearerMarksMeAndFillsMe(): void
    {
        $this->seed();
        $res = $this->svc->leaderboard('world', 'season', 'area', $this->u1, $this->now());

        // u1 ist Rang 3 (kleinste held_length), nicht Top-1 -> me trotzdem gefüllt.
        $this->assertSame(['rank' => 3, 'value' => 100.0], $res['me']);
        $meRows = array_values(array_filter($res['entries'], static fn ($e) => $e['is_me']));
        $this->assertCount(1, $meRows);
        $this->assertSame('a', $meRows[0]['handle']);
    }

    public function testValueMetric(): void
    {
        $this->seed();
        $res = $this->svc->leaderboard('world', 'season', 'value', null, $this->now());
        $this->assertSame([30.0, 20.0, 10.0], array_column($res['entries'], 'value'));
        $this->assertSame(['c', 'b', 'a'], array_column($res['entries'], 'handle'));
    }

    public function testDistanceMetric(): void
    {
        $this->seed();
        $res = $this->svc->leaderboard('world', 'season', 'distance', null, $this->now());
        // u2=500 (e1 100 + e2 200 + e2 200), u1=300 (e1 100 + e2 200), u3=300 (e3).
        // Tie u1/u3 -> kleinere user_id (u1) zuerst.
        $this->assertSame(['b', 'a', 'c'], array_column($res['entries'], 'handle'));
        $this->assertSame([500.0, 300.0, 300.0], array_column($res['entries'], 'value'));
    }

    public function testPioneerMetric(): void
    {
        $this->seed();
        $res = $this->svc->leaderboard('world', 'all', 'pioneer', null, $this->now());
        // u1 in Kohorte von e1,e2 -> 2; u2 in e1,e2 -> 2; u3 in e3 -> 1.
        // Tie u1/u2 -> u1 zuerst. Werte als Ganzzahl.
        $by = [];
        foreach ($res['entries'] as $e) {
            $by[$e['handle']] = $e['value'];
        }
        $this->assertSame(2, $by['a']);
        $this->assertSame(2, $by['b']);
        $this->assertSame(1, $by['c']);
        $this->assertSame(['a', 'b', 'c'], array_column($res['entries'], 'handle'));
    }

    public function testFriendsScopeFiltersToFollowedPlusSelf(): void
    {
        $this->seed();
        $this->follow($this->u1, $this->u2); // u1 folgt nur u2

        $res = $this->svc->leaderboard('friends', 'season', 'area', $this->u1, $this->now());
        // Sichtbar: u1 (self) + u2. u3 ausgeschlossen.
        $this->assertSame(['b', 'a'], array_column($res['entries'], 'handle'));
        $this->assertSame(['rank' => 2, 'value' => 100.0], $res['me']);
    }

    public function testFriendsWithoutUserIsEmpty(): void
    {
        $this->seed();
        $res = $this->svc->leaderboard('friends', 'season', 'area', null, $this->now());
        $this->assertSame([], $res['entries']);
        $this->assertNull($res['me']);
    }

    public function testInvalidatedPassesExcluded(): void
    {
        $this->seed();
        // u2's früheren e2-Pass (D-1) invalidieren -> auf e2 nun Gleichstand
        // (beide 1 Tag) -> u1 (kleinere id) hält e2.
        $this->pdo->prepare(
            "UPDATE game_edge_pass SET invalidated_at = '2026-06-20 09:00:00.000', invalid_reason='t'
              WHERE user_id = ? AND ridden_on = '2026-06-19'"
        )->execute([$this->u2]);

        $res = $this->svc->leaderboard('world', 'season', 'area', null, $this->now());
        $by = [];
        foreach ($res['entries'] as $e) {
            $by[$e['handle']] = $e['value'];
        }
        // u1 hält jetzt e1 (Tie) + e2 (Tie) = 300; u3 = 300; u2 = 0 (nicht gelistet).
        $this->assertArrayNotHasKey('b', $by);
        $this->assertSame(300.0, $by['a']);
        $this->assertSame(300.0, $by['c']);
    }

    public function testEmptyAndInvalidParams(): void
    {
        // Keine Daten -> leer, kein Fehler.
        $res = $this->svc->leaderboard('world', 'season', 'area', null, $this->now());
        $this->assertSame([], $res['entries']);
        $this->assertNull($res['me']);

        // Ungültige Parameter -> Defaults (world/season/area), kein 500.
        $this->seed();
        $res = $this->svc->leaderboard('bogus', 'nope', '???', null, $this->now());
        $this->assertSame(['c', 'b', 'a'], array_column($res['entries'], 'handle'));
    }

    public function testWeekWindowLimitsUnderlyingData(): void
    {
        $this->u1 = $this->createUser('w1');
        $this->r1 = $this->repo->riderClaimantId($this->u1);
        $e1 = $this->edge(7101, 80, 100.0, 5.0);
        // Ein Pass vor 30 Tagen: in season sichtbar, in week NICHT.
        $this->pass($e1, $this->r1, $this->u1, 900, '2026-05-21 08:00:00.000');

        $season = $this->svc->leaderboard('world', 'season', 'distance', null, $this->now());
        $week   = $this->svc->leaderboard('world', 'week', 'distance', null, $this->now());

        $this->assertSame(['w1'], array_column($season['entries'], 'handle'));
        $this->assertSame([], $week['entries']);
    }
}
