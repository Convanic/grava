<?php
declare(strict_types=1);

namespace Tests\Integration\Discovery;

use App\Discovery\DiscoveryService;
use App\Discovery\ProfileService;
use App\Routes\RouteRepository;
use App\Support\Clock;
use Tests\IntegrationTestCase;

/**
 * Akzeptanzkriterien aus backend/USER_SEARCH_BACKEND.md (Personensuche).
 *
 * Die App dekodiert die Antwort mit demselben `UserListEnvelope`-Modell
 * wie /followers /following — die Tests verankern Feld-Vertrag,
 * Teilstring-/Relevanz-Suche, Viewer-Flags, Mindestlänge, Block-Filter
 * und Pagination.
 */
final class ProfileSearchTest extends IntegrationTestCase
{
    private ProfileService $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $routes = new RouteRepository();
        $this->profile = new ProfileService(new DiscoveryService($routes), $routes);
    }

    /** Setzt einen vom Handle abweichenden Anzeigenamen. */
    private function setDisplayName(int $userId, ?string $name): void
    {
        $this->pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')
            ->execute([$name, $userId]);
    }

    /** AK1: Treffer über Handle ODER Anzeigename (case-insensitive). */
    public function testMatchesHandleOrDisplayName(): void
    {
        $byHandle  = $this->createUser('annika');         // Handle enthält "ann"
        $byName    = $this->createUser('rider01');
        $this->setDisplayName($byName, 'Susann Berg');    // Anzeigename enthält "ann"
        $this->createUser('bob');                          // kein Treffer

        $res = $this->profile->searchProfiles(null, ['q' => 'ANN']);

        $handles = array_column($res['users'], 'handle');
        $this->assertContains('annika', $handles);
        $this->assertContains('rider01', $handles);
        $this->assertNotContains('bob', $handles);
        $this->assertSame(2, $res['pagination']['total']);
    }

    /** AK2: Schema deckungsgleich mit /followers /following. */
    public function testEnvelopeSchemaMatchesFollowList(): void
    {
        $viewer = $this->createUser('viewer');
        $target = $this->createUser('annika');
        $this->setDisplayName($target, 'Annika R.');

        $res = $this->profile->searchProfiles($viewer, ['q' => 'ann']);
        $this->assertCount(1, $res['users']);

        $user = $res['users'][0];
        $this->assertSame(
            ['handle', 'display_name', 'joined_at', 'route_count_public',
             'follower_count', 'following_count', 'is_followed_by_viewer', 'is_self'],
            array_keys($user),
        );
        $this->assertSame(
            ['limit', 'offset', 'total', 'has_more'],
            array_keys($res['pagination']),
        );
        $this->assertSame('annika', $user['handle']);
        $this->assertSame('Annika R.', $user['display_name']);
        $this->assertStringEndsWith('Z', $user['joined_at']);
    }

    /** AK3: Mit Viewer sind is_followed_by_viewer / is_self gesetzt. */
    public function testViewerFlagsWithBearer(): void
    {
        $viewer    = $this->createUser('annika_me');     // taucht selbst auf (is_self)
        $followed  = $this->createUser('annika_two');
        $other     = $this->createUser('annika_three');
        $this->seedFollow($viewer, $followed);

        $res = $this->profile->searchProfiles($viewer, ['q' => 'annika', 'limit' => 50]);
        $byHandle = [];
        foreach ($res['users'] as $u) {
            $byHandle[$u['handle']] = $u;
        }

        $this->assertTrue($byHandle['annika_me']['is_self']);
        $this->assertFalse($byHandle['annika_me']['is_followed_by_viewer']);

        $this->assertFalse($byHandle['annika_two']['is_self']);
        $this->assertTrue($byHandle['annika_two']['is_followed_by_viewer']);

        $this->assertFalse($byHandle['annika_three']['is_followed_by_viewer']);
    }

    /** AK3: Ohne Viewer funktioniert die Suche; Flags sind null/false. */
    public function testWorksAnonymouslyWithoutViewerFlags(): void
    {
        $this->createUser('annika');

        $res = $this->profile->searchProfiles(null, ['q' => 'ann']);
        $this->assertCount(1, $res['users']);
        $this->assertNull($res['users'][0]['is_followed_by_viewer']);
        $this->assertFalse($res['users'][0]['is_self']);
    }

    /** AK4: Leeres/zu kurzes q → leere Liste, kein Fehler. */
    public function testShortOrEmptyQueryReturnsEmptyList(): void
    {
        $this->createUser('annika');

        foreach (['', ' ', 'a', '  a '] as $q) {
            $res = $this->profile->searchProfiles(null, ['q' => $q]);
            $this->assertSame([], $res['users'], "q=[{$q}] sollte leer sein");
            $this->assertSame(0, $res['pagination']['total']);
            $this->assertFalse($res['pagination']['has_more']);
        }

        // Gar kein q-Key → ebenfalls leer, kein Fehler.
        $res = $this->profile->searchProfiles(null, []);
        $this->assertSame([], $res['users']);
    }

    /** AK5: Profile ohne public_handle sind nicht auffindbar. */
    public function testUsersWithoutHandleAreNotFound(): void
    {
        $noHandle = $this->createUser(null);              // public_handle NULL
        $this->setDisplayName($noHandle, 'Annika Ohne');  // Name enthält "ann"

        $res = $this->profile->searchProfiles(null, ['q' => 'ann']);
        $this->assertSame([], $res['users']);
    }

    /** AK5: Gegenüber dem Viewer blockierte User fallen aus Liste + total. */
    public function testBlockedUsersAreExcluded(): void
    {
        $viewer  = $this->createUser('seeker');
        $blocked = $this->createUser('annika');
        $this->block($viewer, $blocked);

        $res = $this->profile->searchProfiles($viewer, ['q' => 'ann']);
        $this->assertSame([], $res['users']);
        $this->assertSame(0, $res['pagination']['total']);

        // Auch in der Gegenrichtung (blocked → viewer) unsichtbar.
        $viewer2  = $this->createUser('seeker2');
        $blocker  = $this->createUser('annika2');
        $this->block($blocker, $viewer2);
        $res2 = $this->profile->searchProfiles($viewer2, ['q' => 'annika2']);
        $this->assertSame([], $res2['users']);
    }

    /** AK1: limit/offset paginieren, has_more stimmt. */
    public function testPagination(): void
    {
        for ($n = 0; $n < 5; $n++) {
            $this->createUser(sprintf('annika%02d', $n));
        }

        $page1 = $this->profile->searchProfiles(null, ['q' => 'annika', 'limit' => 2, 'offset' => 0]);
        $this->assertCount(2, $page1['users']);
        $this->assertSame(5, $page1['pagination']['total']);
        $this->assertTrue($page1['pagination']['has_more']);

        $page3 = $this->profile->searchProfiles(null, ['q' => 'annika', 'limit' => 2, 'offset' => 4]);
        $this->assertCount(1, $page3['users']);
        $this->assertFalse($page3['pagination']['has_more']);
    }

    /** Relevanz: exakter Handle-Treffer vor Präfix vor reinem Teilstring. */
    public function testRelevanceOrdering(): void
    {
        $substring = $this->createUser('zsamanna');   // enthält "anna" mittig
        $prefix    = $this->createUser('annapurna');  // beginnt mit "anna"
        $exact     = $this->createUser('anna');       // exakter Handle

        $res = $this->profile->searchProfiles(null, ['q' => 'anna', 'limit' => 50]);
        $handles = array_column($res['users'], 'handle');

        $this->assertSame('anna', $handles[0]);
        $this->assertSame('annapurna', $handles[1]);
        $this->assertSame('zsamanna', $handles[2]);
    }
}
