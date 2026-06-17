<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Engagement\NotificationService;
use Tests\IntegrationTestCase;

final class NotificationServiceTest extends IntegrationTestCase
{
    private NotificationService $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = new NotificationService();
    }

    public function testNotifyCreatesUnread(): void
    {
        $recipient = $this->createUser('recv');
        $actor     = $this->createUser('act');

        $this->notifications->notify($recipient, $actor, 'follow');

        $this->assertSame(1, $this->notifications->unreadCount($recipient));
        $list = $this->notifications->list($recipient);
        $this->assertSame('follow', $list['notifications'][0]['type']);
        $this->assertSame('act', $list['notifications'][0]['actor']['handle']);
    }

    public function testSelfNotificationSkipped(): void
    {
        $user = $this->createUser();
        $this->notifications->notify($user, $user, 'like', 'route', 1);
        $this->assertSame(0, $this->notifications->unreadCount($user));
    }

    public function testBlockedActorNotificationSkipped(): void
    {
        $recipient = $this->createUser();
        $actor     = $this->createUser();
        $this->block($recipient, $actor);

        $this->notifications->notify($recipient, $actor, 'follow');
        $this->assertSame(0, $this->notifications->unreadCount($recipient));
    }

    public function testMarkAllRead(): void
    {
        $recipient = $this->createUser();
        $a = $this->createUser();
        $b = $this->createUser();
        $this->notifications->notify($recipient, $a, 'follow');
        $this->notifications->notify($recipient, $b, 'follow');

        $this->assertSame(2, $this->notifications->markAllRead($recipient));
        $this->assertSame(0, $this->notifications->unreadCount($recipient));
    }

    public function testPurgeOldReadKeepsRecent(): void
    {
        $recipient = $this->createUser();
        $actor     = $this->createUser();
        $this->notifications->notify($recipient, $actor, 'follow');
        $this->notifications->markAllRead($recipient);

        // Frisch gelesen -> wird durch 30-Tage-Karenz NICHT entfernt.
        $this->assertSame(0, $this->notifications->purgeOldRead(30));
    }
}
