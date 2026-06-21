<?php
declare(strict_types=1);

namespace Tests\Integration\Discovery;

use App\Discovery\DiscoveryService;
use App\Discovery\FeedService;
use App\Routes\RouteRepository;
use App\Support\Clock;
use Tests\IntegrationTestCase;

/**
 * Regression: Der Community-/Feed-Pfad darf bei **leerer Follow-Menge**
 * keinen SQLSTATE[HY093] (Invalid parameter number) werfen.
 *
 * Hintergrund: Mit ATTR_EMULATE_PREPARES=false (siehe Db) führt jede
 * Diskrepanz zwischen Platzhalter- und Bind-Anzahl bzw. ein leeres
 * `IN ()` zu einem Fehler. Die Queries sind JOIN-basiert und alle
 * IN()-Listen sind gegen leere Mengen abgesichert — dieser Test
 * friert dieses Verhalten ein.
 */
final class FeedServiceTest extends IntegrationTestCase
{
    private FeedService $feed;
    private DiscoveryService $discovery;

    protected function setUp(): void
    {
        parent::setUp();
        $routes          = new RouteRepository();
        $this->discovery = new DiscoveryService($routes);
        $this->feed      = new FeedService($routes, $this->discovery);
    }

    public function testFeedWithNoFollowsReturnsEmptyWithoutError(): void
    {
        $viewer = $this->createUser('loner');

        // Es gibt sogar öffentliche Routen anderer User — der Viewer
        // folgt nur niemandem.
        $other = $this->createUser('stranger');
        $this->createRoute($other, 'public');

        $res = $this->feed->getFeed($viewer, 20, 0);

        $this->assertSame([], $res['routes']);
        $this->assertSame(0, $res['pagination']['total']);
        $this->assertFalse($res['pagination']['has_more']);
    }

    public function testFeedWithNoFollowsButBlockedUsersStaysSafe(): void
    {
        // Exercise den NOT IN (...)-Zweig: Block-Liste ist nicht leer,
        // Follow-Menge aber schon.
        $viewer  = $this->createUser('blocker');
        $blocked = $this->createUser('blocked');
        $this->block($viewer, $blocked);
        $this->createRoute($blocked, 'public');

        $res = $this->feed->getFeed($viewer, 20, 0);

        $this->assertSame([], $res['routes']);
        $this->assertSame(0, $res['pagination']['total']);
    }

    public function testFeedReturnsFollowedUsersPublicRoutes(): void
    {
        $viewer   = $this->createUser('follower');
        $followee = $this->createUser('author');
        $this->follow($viewer, $followee);
        $this->createRoute($followee, 'public');
        $this->createRoute($followee, 'private'); // darf nicht erscheinen

        $res = $this->feed->getFeed($viewer, 20, 0);

        $this->assertCount(1, $res['routes']);
        $this->assertSame(1, $res['pagination']['total']);
    }

    /**
     * Regression für SQLSTATE[HY093]: Wenn ein gefolgter User MEHRERE
     * public Routen im Feed hat, entstand durch `array_unique` (Keys
     * bleiben erhalten) eine Lücke in der Owner-ID-Liste → PDO::execute
     * mit positionalen `?` brach ab. Mit zwei Routen desselben Owners
     * trifft der Test genau diesen Pfad.
     */
    public function testFeedWithMultipleRoutesFromSameOwnerResolvesOwners(): void
    {
        $viewer   = $this->createUser('fan');
        $followee = $this->createUser('prolific');
        $this->follow($viewer, $followee);
        $this->createRoute($followee, 'public');
        $this->createRoute($followee, 'public');
        $this->createRoute($followee, 'public');

        $res = $this->feed->getFeed($viewer, 20, 0);

        $this->assertCount(3, $res['routes']);
        foreach ($res['routes'] as $route) {
            $this->assertSame('prolific', $route['owner']['handle']);
        }
    }

    private function follow(int $followerId, int $followeeId): void
    {
        $this->pdo->prepare(
            'INSERT INTO follows (follower_id, followee_id, created_at)
             VALUES (?, ?, ?)'
        )->execute([$followerId, $followeeId, Clock::nowUtcString()]);
    }
}
