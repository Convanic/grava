<?php
declare(strict_types=1);

namespace Tests\Integration\Engagement;

use App\Engagement\NotificationPreferenceRepository;
use App\Engagement\NotificationService;
use App\Push\ApnsTransport;
use App\Push\PushDeviceRepository;
use App\Push\PushService;
use PDO;
use Tests\IntegrationTestCase;

/** Fake-Transport: zeichnet jeden Send auf. */
final class RecordingApnsPref implements ApnsTransport
{
    /** @var list<array<string,mixed>> */
    public array $sent = [];

    public function send(string $environment, string $deviceToken, array $payload, ?string $collapseId = null): int
    {
        $this->sent[] = ['token' => $deviceToken, 'payload' => $payload];
        return 200;
    }
}

final class NotificationPreferenceTest extends IntegrationTestCase
{
    private NotificationPreferenceRepository $prefs;
    private PushDeviceRepository $devices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefs = new NotificationPreferenceRepository();
        $this->devices = new PushDeviceRepository();
    }

    public function testGetDefaultsAllTrueWithoutRow(): void
    {
        $u = $this->createUser('a');
        $this->assertSame(['follow' => true, 'like' => true, 'comment' => true], $this->prefs->get($u));
    }

    public function testUpsertPartialKeepsOthersUnchanged(): void
    {
        $u = $this->createUser('b');
        $res = $this->prefs->upsert($u, ['like' => false]);
        $this->assertSame(['follow' => true, 'like' => false, 'comment' => true], $res);
        $this->assertSame(['follow' => true, 'like' => false, 'comment' => true], $this->prefs->get($u));

        // Zweites Upsert ändert nur comment, like bleibt false.
        $this->prefs->upsert($u, ['comment' => false]);
        $this->assertSame(['follow' => true, 'like' => false, 'comment' => false], $this->prefs->get($u));
    }

    public function testIsPushEnabledUnknownTypeIsTrue(): void
    {
        $u = $this->createUser('c');
        $this->prefs->upsert($u, ['like' => false]);
        $this->assertTrue($this->prefs->isPushEnabled($u, 'territory_taken'));
        $this->assertFalse($this->prefs->isPushEnabled($u, 'like'));
    }

    public function testDisabledTypeSendsNoPushButKeepsInAppEntry(): void
    {
        $owner = $this->createUser('owner');
        $actor = $this->createUser('liker');
        $pub   = $this->createRoute($owner);
        $routeId = (int)$this->pdo->query("SELECT id FROM routes WHERE public_id = '{$pub}'")->fetchColumn();

        $this->devices->upsert($owner, 'TOK', 'ios', 'production');
        $this->prefs->upsert($owner, ['like' => false]);

        $fake = new RecordingApnsPref();
        $notif = new NotificationService(new PushService($this->devices, $fake), $this->prefs);
        $notif->notify($owner, $actor, 'like', 'route', $routeId);

        // Keine Push …
        $this->assertCount(0, $fake->sent);
        // … aber In-App-Eintrag + Unread-Count bleiben.
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = {$owner}")->fetchColumn();
        $this->assertSame(1, $count);
        $this->assertSame(1, $notif->unreadCount($owner));
    }

    public function testEnabledTypeStillSendsPush(): void
    {
        $owner = $this->createUser('owner');
        $actor = $this->createUser('follower');
        $this->devices->upsert($owner, 'TOK2', 'ios', 'production');
        // follow bleibt default true; like wird ausgeschaltet (darf follow nicht beeinflussen).
        $this->prefs->upsert($owner, ['like' => false]);

        $fake = new RecordingApnsPref();
        $notif = new NotificationService(new PushService($this->devices, $fake), $this->prefs);
        $notif->notify($owner, $actor, 'follow', 'user', $actor);

        $this->assertCount(1, $fake->sent);
        $this->assertSame('follow', $fake->sent[0]['payload']['type']);
    }

    public function testWithoutPrefRepoBehavesAsBefore(): void
    {
        $owner = $this->createUser('owner');
        $actor = $this->createUser('liker');
        $this->devices->upsert($owner, 'TOK3', 'ios', 'production');

        $fake = new RecordingApnsPref();
        // Keine Pref-Repo injiziert → Verhalten wie vor S9 (immer Push).
        $notif = new NotificationService(new PushService($this->devices, $fake));
        $notif->notify($owner, $actor, 'like', 'user', $actor);

        $this->assertCount(1, $fake->sent);
    }
}
