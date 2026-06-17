<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Engagement\EngagementException;
use App\Engagement\LikeService;
use Tests\IntegrationTestCase;

final class LikeServiceTest extends IntegrationTestCase
{
    private LikeService $likes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->likes = new LikeService();
    }

    public function testLikeIsIdempotent(): void
    {
        $owner = $this->createUser('owner1');
        $fan   = $this->createUser('fan1');
        $route = $this->createRoute($owner, 'public');

        $this->assertTrue($this->likes->like($route, $fan), 'erstes Like neu');
        $this->assertFalse($this->likes->like($route, $fan), 'zweites Like no-op');

        $summary = $this->likes->summary($route, $fan);
        $this->assertSame(1, $summary['count']);
        $this->assertTrue($summary['liked_by_viewer']);
        $this->assertSame(['fan1'], $summary['recent']);
    }

    public function testUnlikeRemovesLike(): void
    {
        $owner = $this->createUser();
        $fan   = $this->createUser();
        $route = $this->createRoute($owner, 'public');

        $this->likes->like($route, $fan);
        $this->likes->unlike($route, $fan);

        $this->assertSame(0, $this->likes->summary($route, $fan)['count']);
    }

    public function testCannotLikePrivateRouteOfOtherUser(): void
    {
        $owner = $this->createUser();
        $fan   = $this->createUser();
        $route = $this->createRoute($owner, 'private');

        try {
            $this->likes->like($route, $fan);
            $this->fail('EngagementException erwartet');
        } catch (EngagementException $e) {
            $this->assertSame(404, $e->httpStatus);
            $this->assertSame('not_found', $e->errorCode);
        }
    }

    public function testBlockedViewerGets404(): void
    {
        $owner = $this->createUser();
        $fan   = $this->createUser();
        $route = $this->createRoute($owner, 'public');
        $this->block($owner, $fan);

        try {
            $this->likes->like($route, $fan);
            $this->fail('EngagementException erwartet');
        } catch (EngagementException $e) {
            $this->assertSame(404, $e->httpStatus);
        }
    }

    public function testOwnerCanLikeOwnPrivateRoute(): void
    {
        $owner = $this->createUser('owner2');
        $route = $this->createRoute($owner, 'private');

        $this->assertTrue($this->likes->like($route, $owner));
        $this->assertSame(1, $this->likes->summary($route, $owner)['count']);
    }
}
