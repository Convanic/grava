<?php
declare(strict_types=1);

namespace Tests\Integration\Push;

use App\Engagement\NotificationService;
use App\Push\ApnsTransport;
use App\Push\PushDeviceRepository;
use App\Push\PushService;
use Tests\IntegrationTestCase;

/** Fake-Transport: zeichnet Sends auf, liefert konfigurierbare Status. */
final class RecordingApns implements ApnsTransport
{
    /** @var list<array{env:string,token:string,payload:array<string,mixed>,collapse:?string}> */
    public array $sent = [];
    /** @var array<string,int> token => status */
    public array $statusByToken = [];

    public function send(string $environment, string $deviceToken, array $payload, ?string $collapseId = null): int
    {
        $this->sent[] = ['env' => $environment, 'token' => $deviceToken, 'payload' => $payload, 'collapse' => $collapseId];
        return $this->statusByToken[$deviceToken] ?? 200;
    }
}

final class PushServiceTest extends IntegrationTestCase
{
    private PushDeviceRepository $devices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->devices = new PushDeviceRepository();
    }

    public function testUpsertIsIdempotentAndSwitchesOwner(): void
    {
        $u1 = $this->createUser('one');
        $u2 = $this->createUser('two');

        $this->devices->upsert($u1, 'ABC123', 'ios', 'production');
        $this->devices->upsert($u1, 'ABC123', 'ios', 'development');
        $this->assertCount(1, $this->devices->forUser($u1), 'Gleiches Token → kein Duplikat.');
        $this->assertSame('development', $this->devices->forUser($u1)[0]['environment']);

        // Token wechselt den Besitzer.
        $this->devices->upsert($u2, 'ABC123', 'ios', 'production');
        $this->assertCount(0, $this->devices->forUser($u1));
        $this->assertCount(1, $this->devices->forUser($u2));
    }

    public function testDeleteForUser(): void
    {
        $u = $this->createUser();
        $this->devices->upsert($u, 'DEAD', 'ios', 'production');
        $this->assertTrue($this->devices->deleteForUser($u, 'DEAD'));
        $this->assertFalse($this->devices->deleteForUser($u, 'DEAD'));
        $this->assertCount(0, $this->devices->forUser($u));
    }

    public function testLikeNotificationSendsPushWithRouteAndBadge(): void
    {
        $owner = $this->createUser('owner');
        $actor = $this->createUser('liker');
        $pub   = $this->createRoute($owner);
        $routeId = (int)$this->pdo->query("SELECT id FROM routes WHERE public_id = '{$pub}'")->fetchColumn();

        $this->devices->upsert($owner, 'TOKDEV', 'ios', 'development');

        $fake = new RecordingApns();
        $notif = new NotificationService(new PushService($this->devices, $fake));
        $notif->notify($owner, $actor, 'like', 'route', $routeId);

        $this->assertCount(1, $fake->sent);
        $p = $fake->sent[0]['payload'];
        $this->assertSame('like', $p['type']);
        $this->assertSame($pub, $p['route_id']);
        $this->assertSame(1, $p['aps']['badge'], 'Badge = Ungelesen-Zahl.');
        $this->assertNotEmpty($p['notification_id']);
        $this->assertSame('development', $fake->sent[0]['env']);
    }

    public function testFollowNotificationCarriesActorHandle(): void
    {
        $recipient = $this->createUser('recv');
        $actor     = $this->createUser('bob');
        $this->devices->upsert($recipient, 'TOKPROD', 'ios', 'production');

        $fake = new RecordingApns();
        $notif = new NotificationService(new PushService($this->devices, $fake));
        $notif->notify($recipient, $actor, 'follow', 'user', $actor);

        $this->assertCount(1, $fake->sent);
        $p = $fake->sent[0]['payload'];
        $this->assertSame('follow', $p['type']);
        $this->assertSame('bob', $p['handle']);
        $this->assertArrayNotHasKey('route_id', $p);
    }

    public function testApns410RemovesToken(): void
    {
        $recipient = $this->createUser('gone');
        $actor     = $this->createUser('actor');
        $this->devices->upsert($recipient, 'STALE', 'ios', 'production');

        $fake = new RecordingApns();
        $fake->statusByToken['STALE'] = 410;
        $notif = new NotificationService(new PushService($this->devices, $fake));
        $notif->notify($recipient, $actor, 'follow', 'user', $actor);

        $this->assertCount(0, $this->devices->forUser($recipient), 'Ungültiges Token (410) wird entfernt.');
    }

    public function testNoDevicesNoSend(): void
    {
        $recipient = $this->createUser();
        $actor     = $this->createUser();
        $fake = new RecordingApns();
        $notif = new NotificationService(new PushService($this->devices, $fake));
        $notif->notify($recipient, $actor, 'follow', 'user', $actor);
        $this->assertCount(0, $fake->sent);
    }
}
