<?php
declare(strict_types=1);

namespace Tests\Integration\Discovery;

use App\Discovery\DiscoveryService;
use App\Discovery\ProfileService;
use App\Routes\RouteRepository;
use Tests\IntegrationTestCase;

/**
 * Deckt die Akzeptanzkriterien aus backend/FOLLOW_LISTS_BACKEND.md ab:
 * GET /users/by-handle/{handle}/followers + /following.
 */
final class ProfileFollowListTest extends IntegrationTestCase
{
    private ProfileService $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $discovery = new DiscoveryService(new RouteRepository());
        $this->profile = new ProfileService($discovery, new RouteRepository());
    }

    /** AK1: anonym → volle Form, is_self=false, is_followed_by_viewer=null. */
    public function testFollowersAnonymousReturnsFullPublicProfileShape(): void
    {
        $profile = $this->createUser('starlet');
        $a = $this->createUser('alice');
        $b = $this->createUser('bob');
        $this->seedFollow($a, $profile);
        $this->seedFollow($b, $profile);

        $res = $this->profile->getProfileFollowList('starlet', null, 'followers', []);

        $this->assertNotNull($res);
        $this->assertSame(2, $res['pagination']['total']);
        $this->assertCount(2, $res['users']);

        $handles = array_column($res['users'], 'handle');
        $this->assertEqualsCanonicalizing(['alice', 'bob'], $handles);

        $row = $res['users'][0];
        $this->assertArrayHasKey('display_name', $row);
        $this->assertArrayHasKey('joined_at', $row);
        $this->assertArrayHasKey('route_count_public', $row);
        $this->assertArrayHasKey('follower_count', $row);
        $this->assertArrayHasKey('following_count', $row);
        $this->assertFalse($row['is_self']);
        $this->assertNull($row['is_followed_by_viewer']);
    }

    /** AK1 (Gegenrichtung): following = wem das Profil folgt. */
    public function testFollowingAnonymousListsFollowees(): void
    {
        $profile = $this->createUser('hub');
        $x = $this->createUser('xander');
        $y = $this->createUser('yara');
        $this->seedFollow($profile, $x);
        $this->seedFollow($profile, $y);
        // Gegenrichtung darf NICHT auftauchen:
        $this->seedFollow($this->createUser('zoe'), $profile);

        $res = $this->profile->getProfileFollowList('hub', null, 'following', []);

        $this->assertNotNull($res);
        $this->assertSame(2, $res['pagination']['total']);
        $this->assertEqualsCanonicalizing(['xander', 'yara'], array_column($res['users'], 'handle'));
    }

    /** AK2: mit Viewer → is_followed_by_viewer korrekt; eigene Zeile is_self=true. */
    public function testViewerFlagsReflectFollowAndSelf(): void
    {
        $profile = $this->createUser('idol');
        $viewer  = $this->createUser('viewer');
        $other   = $this->createUser('other');

        // viewer und other folgen beide dem Profil; viewer folgt zusätzlich other.
        $this->seedFollow($viewer, $profile);
        $this->seedFollow($other, $profile);
        $this->seedFollow($viewer, $other);

        $res = $this->profile->getProfileFollowList('idol', $viewer, 'followers', []);
        $this->assertNotNull($res);

        $byHandle = [];
        foreach ($res['users'] as $u) {
            $byHandle[$u['handle']] = $u;
        }

        $this->assertTrue($byHandle['viewer']['is_self']);
        $this->assertFalse($byHandle['viewer']['is_followed_by_viewer']);

        $this->assertFalse($byHandle['other']['is_self']);
        $this->assertTrue($byHandle['other']['is_followed_by_viewer']);
    }

    /** AK3: unbekannter/inaktiver Handle → null. */
    public function testUnknownOrInactiveHandleReturnsNull(): void
    {
        $this->assertNull($this->profile->getProfileFollowList('ghost', null, 'followers', []));

        $inactive = $this->createUser('zombie');
        $this->pdo->prepare("UPDATE users SET status = 'disabled' WHERE id = ?")->execute([$inactive]);
        $this->assertNull($this->profile->getProfileFollowList('zombie', null, 'followers', []));
    }

    /** AK3: Block (Profil ↔ Viewer) → null, kein Listen-Probing. */
    public function testBlockBetweenProfileAndViewerReturnsNull(): void
    {
        $profile = $this->createUser('walled');
        $viewer  = $this->createUser('peeker');
        $this->seedFollow($this->createUser('fan'), $profile);

        // Viewer blockt Profil → 404.
        $this->block($viewer, $profile);
        $this->assertNull($this->profile->getProfileFollowList('walled', $viewer, 'followers', []));

        // Auch umgekehrt: Profil blockt Viewer → 404.
        $profile2 = $this->createUser('walled2');
        $this->seedFollow($this->createUser('fan2'), $profile2);
        $this->block($profile2, $viewer);
        $this->assertNull($this->profile->getProfileFollowList('walled2', $viewer, 'followers', []));
    }

    /** AK4: limit/offset pagen; total/has_more korrekt; neueste zuerst. */
    public function testPaginationAndOrdering(): void
    {
        $profile = $this->createUser('famous');
        $first  = $this->createUser('first_follower');
        $second = $this->createUser('second_follower');
        $third  = $this->createUser('third_follower');
        $this->seedFollow($first,  $profile, '2025-01-01 10:00:00');
        $this->seedFollow($second, $profile, '2025-02-01 10:00:00');
        $this->seedFollow($third,  $profile, '2025-03-01 10:00:00');

        $page1 = $this->profile->getProfileFollowList('famous', null, 'followers', ['limit' => 2, 'offset' => 0]);
        $this->assertNotNull($page1);
        $this->assertSame(3, $page1['pagination']['total']);
        $this->assertTrue($page1['pagination']['has_more']);
        $this->assertCount(2, $page1['users']);
        // Neueste Follow-Beziehung zuerst.
        $this->assertSame('third_follower', $page1['users'][0]['handle']);
        $this->assertSame('second_follower', $page1['users'][1]['handle']);

        $page2 = $this->profile->getProfileFollowList('famous', null, 'followers', ['limit' => 2, 'offset' => 2]);
        $this->assertNotNull($page2);
        $this->assertFalse($page2['pagination']['has_more']);
        $this->assertCount(1, $page2['users']);
        $this->assertSame('first_follower', $page2['users'][0]['handle']);
    }

    /** AK5: leere Beziehung → users=[], total=0, kein Fehler. */
    public function testEmptyListIsSafe(): void
    {
        $this->createUser('lonely');
        $res = $this->profile->getProfileFollowList('lonely', null, 'followers', []);
        $this->assertNotNull($res);
        $this->assertSame([], $res['users']);
        $this->assertSame(0, $res['pagination']['total']);
        $this->assertFalse($res['pagination']['has_more']);
    }

    /** AK6: Block-Filter blendet User aus Liste UND total aus. */
    public function testBlockFilterExcludesUserFromListAndTotal(): void
    {
        $profile = $this->createUser('celeb');
        $viewer  = $this->createUser('observer');
        $clean   = $this->createUser('clean_user');
        $blocked = $this->createUser('blocked_user');
        $this->seedFollow($clean,   $profile);
        $this->seedFollow($blocked, $profile);

        // Viewer blockt einen der Follower → fällt aus Liste + total.
        $this->block($viewer, $blocked);

        $res = $this->profile->getProfileFollowList('celeb', $viewer, 'followers', []);
        $this->assertNotNull($res);
        $this->assertSame(1, $res['pagination']['total']);
        $this->assertSame(['clean_user'], array_column($res['users'], 'handle'));
    }
}
