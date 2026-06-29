<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Engagement\NotificationPreferenceRepository;
use App\Engagement\NotificationService;
use App\Game\GameConfig;
use App\Game\GameEventRepository;
use App\Game\GameNotificationDispatcher;
use App\Game\GameRepository;
use App\Privacy\PrivacyZoneRepository;
use App\Push\ApnsTransport;
use App\Push\PushDeviceRepository;
use App\Push\PushService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/** Fake-APNs-Transport: zeichnet jeden Versand auf. */
final class RecordingApnsDispatch implements ApnsTransport
{
    /** @var list<array<string,mixed>> */
    public array $sent = [];

    public function send(string $environment, string $deviceToken, array $payload, ?string $collapseId = null): int
    {
        $this->sent[] = ['token' => $deviceToken, 'payload' => $payload];
        return 200;
    }
}

/**
 * Phase B: Zustellung des Spiel-Ereignis-Stroms (GAME_PUSH_BACKEND.md §6).
 * Deckt die Akzeptanzkriterien AC1–AC6 + das Digest-Zeitfenster ab.
 */
final class GameNotificationDispatchTest extends IntegrationTestCase
{
    private GameEventRepository $events;
    private NotificationPreferenceRepository $prefs;
    private PushDeviceRepository $devices;
    private RecordingApnsDispatch $apns;
    private NotificationService $notifications;
    private GameNotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events  = new GameEventRepository($this->pdo);
        $this->prefs   = new NotificationPreferenceRepository();
        $this->devices = new PushDeviceRepository();
        $this->apns    = new RecordingApnsDispatch();
        $this->notifications = new NotificationService(
            new PushService($this->devices, $this->apns),
            $this->prefs,
        );
        $this->dispatcher = new GameNotificationDispatcher(
            $this->events,
            $this->notifications,
            new GameRepository($this->pdo),
            new PrivacyZoneRepository($this->pdo),
            new GameConfig($this->pdo),
        );
    }

    private function now(string $iso = '2026-06-29T12:00:00Z'): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    /** Fügt eine game_event-Zeile mit kontrolliertem created_at ein. */
    private function insertEvent(
        string $type,
        int $userId,
        ?int $actorId,
        ?int $edgeId,
        string $riddenOn,
        string $createdAt,
    ): void {
        $this->pdo->prepare(
            'INSERT INTO game_event (type, user_id, actor_user_id, edge_id, ride_id, ridden_on, created_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?)'
        )->execute([$type, $userId, $actorId, $edgeId, $riddenOn, $createdAt]);
    }

    /** @return list<array<string,mixed>> */
    private function inbox(int $userId): array
    {
        return $this->pdo->query(
            "SELECT type, actor_id, edge_id, `count` FROM notifications WHERE user_id = {$userId} ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    // AC1 + AC2: Einzel-Übernahme (unter Schwelle, Fenster abgelaufen) ⇒
    // Mitteilung mit edge_id + Push (game_takeover default an).
    public function testSingleTakeoverNotifiesWithEdgeIdAndPush(): void
    {
        $owner = $this->createUser('owner');
        $actor = $this->createUser('taker');
        $this->devices->upsert($owner, 'TOK', 'ios', 'production');
        $this->insertEvent('edge_taken', $owner, $actor, 4242, '2026-06-29', '2026-06-29 09:00:00.000');

        $sent = $this->dispatcher->dispatch($this->now());

        $this->assertSame(1, $sent);
        $rows = $this->inbox($owner);
        $this->assertCount(1, $rows);
        $this->assertSame('edge_taken', $rows[0]['type']);
        $this->assertSame(4242, (int)$rows[0]['edge_id']);
        $this->assertSame((string)$actor, (string)$rows[0]['actor_id']);
        $this->assertCount(1, $this->apns->sent);
        $this->assertSame('4242', $this->apns->sent[0]['payload']['edge_id']);
        $this->assertSame('edge_taken', $this->apns->sent[0]['payload']['type']);
    }

    // AC3: ≥ Schwelle (3) gleichartige Ereignisse ⇒ EINE Digest-Mitteilung
    // (count=N, actor=null, edge_id=null) statt N Pushes.
    public function testDigestBundlesAtThreshold(): void
    {
        $owner = $this->createUser('owner');
        $a1 = $this->createUser('a1');
        $this->devices->upsert($owner, 'TOK', 'ios', 'production');
        foreach ([11, 12, 13] as $i => $edge) {
            $this->insertEvent('edge_taken', $owner, $a1, $edge, '2026-06-29', "2026-06-29 11:5{$i}:00.000");
        }

        $sent = $this->dispatcher->dispatch($this->now());

        $this->assertSame(1, $sent);
        $rows = $this->inbox($owner);
        $this->assertCount(1, $rows);
        $this->assertSame(3, (int)$rows[0]['count']);
        $this->assertNull($rows[0]['actor_id']);
        $this->assertNull($rows[0]['edge_id']);
        $this->assertCount(1, $this->apns->sent); // nur EIN Push statt drei
    }

    // AC4: Pref aus ⇒ kein Push, aber Inbox-Eintrag bleibt.
    public function testPrefOffNoPushButInbox(): void
    {
        $owner = $this->createUser('owner');
        $a1 = $this->createUser('a1');
        $this->devices->upsert($owner, 'TOK', 'ios', 'production');
        $this->prefs->upsert($owner, ['game_takeover' => false]);
        foreach ([21, 22, 23] as $i => $edge) {
            $this->insertEvent('edge_taken', $owner, $a1, $edge, '2026-06-29', "2026-06-29 11:5{$i}:00.000");
        }

        $sent = $this->dispatcher->dispatch($this->now());

        $this->assertSame(1, $sent);
        $this->assertCount(1, $this->inbox($owner));
        $this->assertCount(0, $this->apns->sent);
    }

    // AC5: game_pioneer default aus ⇒ kein Pionier-Push (Inbox dennoch).
    public function testPioneerDefaultOffNoPush(): void
    {
        $owner = $this->createUser('discoverer');
        $actor = $this->createUser('newcomer');
        $this->devices->upsert($owner, 'TOK', 'ios', 'production');
        $this->insertEvent('pioneer_joined', $owner, $actor, 7, '2026-06-29', '2026-06-29 09:00:00.000');

        $sent = $this->dispatcher->dispatch($this->now());

        $this->assertSame(1, $sent);
        $this->assertCount(1, $this->inbox($owner));
        $this->assertCount(0, $this->apns->sent);
    }

    // AC6: Kante in aktiver Heimatzone ⇒ kein Deep-Link (edge_id=null).
    public function testHomeZoneMaskingDropsEdgeId(): void
    {
        $owner = $this->createUser('owner');
        $actor = $this->createUser('taker');
        $this->devices->upsert($owner, 'TOK', 'ios', 'production');
        // Aktive Zone um (49.5, 8.5); Kante mitten drin.
        (new PrivacyZoneRepository($this->pdo))->upsert($owner, 49.5, 8.5, 500, true);
        $edgeId = $this->seedEdge([[8.5, 49.5], [8.5004, 49.5004]]);
        $this->insertEvent('edge_taken', $owner, $actor, $edgeId, '2026-06-29', '2026-06-29 09:00:00.000');

        $this->dispatcher->dispatch($this->now());

        $rows = $this->inbox($owner);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['edge_id'], 'Kante in Heimatzone wird nicht verlinkt');
    }

    // Fenster: frisches Einzel-Ereignis (unter Schwelle, Fenster NICHT abgelaufen)
    // wird zurückgestellt — keine Mitteilung, Ereignis bleibt pending.
    public function testFreshSingleEventDeferred(): void
    {
        $owner = $this->createUser('owner');
        $actor = $this->createUser('taker');
        $this->devices->upsert($owner, 'TOK', 'ios', 'production');
        $now = $this->now();
        $this->insertEvent('edge_taken', $owner, $actor, 9, '2026-06-29', $now->format('Y-m-d H:i:s.v'));

        $sent = $this->dispatcher->dispatch($now);

        $this->assertSame(0, $sent);
        $this->assertCount(0, $this->inbox($owner));
        // Ereignis bleibt unverarbeitet (notified_at NULL).
        $pending = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM game_event WHERE user_id = {$owner} AND notified_at IS NULL"
        )->fetchColumn();
        $this->assertSame(1, $pending);
    }

    /**
     * Minimal-Kante (2 Knoten + game_edge) mit geom_geojson für Masking-Tests.
     *
     * @param list<array{0:float,1:float}> $lonLat
     */
    private function seedEdge(array $lonLat): int
    {
        $lats = array_map(static fn($c) => $c[1], $lonLat);
        $lons = array_map(static fn($c) => $c[0], $lonLat);
        $osm = random_int(1, 2_000_000_000);
        $this->pdo->prepare('INSERT INTO game_node (osm_node_id, lat, lon) VALUES (?, ?, ?)')
            ->execute([$osm, $lonLat[0][1], $lonLat[0][0]]);
        $nodeA = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO game_node (osm_node_id, lat, lon) VALUES (?, ?, ?)')
            ->execute([$osm + 1, $lonLat[1][1], $lonLat[1][0]]);
        $nodeB = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO game_edge
                (way_id, node_a_id, node_b_id, length_m, geom_geojson,
                 min_lat, min_lon, max_lat, max_lon)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $osm, $nodeA, $nodeB, 100.0, json_encode($lonLat),
            min($lats), min($lons), max($lats), max($lons),
        ]);
        return (int)$this->pdo->lastInsertId();
    }
}
