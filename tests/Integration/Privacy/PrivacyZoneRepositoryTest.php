<?php
declare(strict_types=1);

namespace Tests\Integration\Privacy;

use App\Privacy\PrivacyZoneRepository;
use Tests\IntegrationTestCase;

final class PrivacyZoneRepositoryTest extends IntegrationTestCase
{
    private PrivacyZoneRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new PrivacyZoneRepository($this->pdo);
    }

    public function testOwnerZoneForRouteReturnsOwnerAndZone(): void
    {
        $owner = $this->createUser('owner');
        $pid = $this->createRoute($owner, 'public', 48.20, 11.60);
        $this->repo->upsert($owner, 48.20, 11.60, 500, true);

        $res = $this->repo->ownerZoneForRoute($pid);
        $this->assertNotNull($res);
        $this->assertSame($owner, $res['owner_id']);
        $this->assertSame(500, $res['zone']->radiusM);
    }

    public function testOwnerZoneForRouteNullWhenZoneDisabled(): void
    {
        $owner = $this->createUser('owner');
        $pid = $this->createRoute($owner, 'public', 48.20, 11.60);
        $this->repo->upsert($owner, 48.20, 11.60, 500, false);

        $this->assertNull($this->repo->ownerZoneForRoute($pid));
    }

    public function testOwnerZoneForRouteNullWhenNoZone(): void
    {
        $owner = $this->createUser('owner');
        $pid = $this->createRoute($owner, 'public', 48.20, 11.60);
        $this->assertNull($this->repo->ownerZoneForRoute($pid));
    }

    public function testEnabledZonesByUser(): void
    {
        $u1 = $this->createUser('a');
        $u2 = $this->createUser('b');
        $u3 = $this->createUser('c');
        $this->repo->upsert($u1, 48.20, 11.60, 500, true);
        $this->repo->upsert($u2, 49.00, 12.00, 800, false); // disabled
        $this->repo->upsert($u3, 47.00, 10.00, 300, true);

        $map = $this->repo->enabledZonesByUser();
        $this->assertArrayHasKey($u1, $map);
        $this->assertArrayHasKey($u3, $map);
        $this->assertArrayNotHasKey($u2, $map);
        $this->assertSame(300, $map[$u3]->radiusM);
    }
}
