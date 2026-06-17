<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Engagement\CommentService;
use App\Engagement\EngagementException;
use Tests\IntegrationTestCase;

final class CommentServiceTest extends IntegrationTestCase
{
    private CommentService $comments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->comments = new CommentService();
    }

    public function testCreateAndList(): void
    {
        $owner = $this->createUser('owner');
        $fan   = $this->createUser('commenter');
        $route = $this->createRoute($owner, 'public');

        $c = $this->comments->create($route, $fan, '  Tolle Runde!  ');
        $this->assertSame('Tolle Runde!', $c['body']);
        $this->assertSame('commenter', $c['author']['handle']);

        $list = $this->comments->list($route, null);
        $this->assertSame(1, $list['pagination']['total']);
    }

    public function testEmptyCommentRejected(): void
    {
        $owner = $this->createUser();
        $route = $this->createRoute($owner, 'public');

        try {
            $this->comments->create($route, $owner, '   ');
            $this->fail('EngagementException erwartet');
        } catch (EngagementException $e) {
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function testTooLongCommentRejected(): void
    {
        $owner = $this->createUser();
        $route = $this->createRoute($owner, 'public');

        try {
            $this->comments->create($route, $owner, str_repeat('x', CommentService::MAX_LEN + 1));
            $this->fail('EngagementException erwartet');
        } catch (EngagementException $e) {
            $this->assertSame(422, $e->httpStatus);
        }
    }

    public function testAuthorCanDeleteOwnComment(): void
    {
        $owner = $this->createUser();
        $fan   = $this->createUser();
        $route = $this->createRoute($owner, 'public');

        $c = $this->comments->create($route, $fan, 'lösch mich');
        $this->comments->delete($route, $c['id'], $fan);

        $this->assertSame(0, $this->comments->list($route, null)['pagination']['total']);
    }

    public function testRouteOwnerCanDeleteForeignComment(): void
    {
        $owner = $this->createUser();
        $fan   = $this->createUser();
        $route = $this->createRoute($owner, 'public');

        $c = $this->comments->create($route, $fan, 'unerwünscht');
        $this->comments->delete($route, $c['id'], $owner);

        $this->assertSame(0, $this->comments->list($route, null)['pagination']['total']);
    }

    public function testStrangerCannotDeleteComment(): void
    {
        $owner    = $this->createUser();
        $fan      = $this->createUser();
        $stranger = $this->createUser();
        $route    = $this->createRoute($owner, 'public');

        $c = $this->comments->create($route, $fan, 'meins');

        try {
            $this->comments->delete($route, $c['id'], $stranger);
            $this->fail('EngagementException erwartet');
        } catch (EngagementException $e) {
            $this->assertSame(403, $e->httpStatus);
        }
    }
}
