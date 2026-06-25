<?php
declare(strict_types=1);

namespace Tests\Integration\Presence;

use App\Game\GameConfig;
use App\Presence\PresenceRepository;
use App\Presence\PresenceService;
use Tests\IntegrationTestCase;

/** Akzeptanzkriterien aus backend/PRESENCE_BACKEND.md §5. */
final class PresenceServiceTest extends IntegrationTestCase
{
    private PresenceService $presence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presence = new PresenceService(new PresenceRepository($this->pdo), new GameConfig($this->pdo));
    }

    /** AK1: ein Heartbeat → active_count ≥ 1; active() zeigt dieselbe Zahl. */
    public function testHeartbeatCountsAndActiveMatches(): void
    {
        $res = $this->presence->heartbeat(null, self::uuid4());
        $this->assertGreaterThanOrEqual(1, $res['active_count']);
        $this->assertSame($res['active_count'], $this->presence->active()['active_count']);
    }

    /** AK2: dieselbe session_id pingt 5× → zählt 1. */
    public function testDedupSameSessionId(): void
    {
        $sid = self::uuid4();
        for ($i = 0; $i < 5; $i++) {
            $this->presence->heartbeat(null, $sid);
        }
        $this->assertSame(1, $this->presence->activeCount());
    }

    /** AK3: zwei Geräte, gleicher User → zählt 1. */
    public function testDedupLoggedInUserAcrossDevices(): void
    {
        $userId = $this->createUser('rider1');
        $this->presence->heartbeat($userId, self::uuid4());
        $this->presence->heartbeat($userId, self::uuid4());
        $this->assertSame(1, $this->presence->activeCount());
    }

    /** AK4: kein Heartbeat > TTL → Identität fällt aus active_count. */
    public function testTtlExpiryRemovesStaleIdentity(): void
    {
        $sid = self::uuid4();
        $this->presence->heartbeat(null, $sid);
        $this->assertSame(1, $this->presence->activeCount());

        $this->pdo->prepare(
            'UPDATE presence_active
                SET last_seen = DATE_SUB(UTC_TIMESTAMP(3), INTERVAL 200 SECOND)
              WHERE identity = ?'
        )->execute(['s:' . $sid]);

        $this->assertSame(0, $this->presence->activeCount());
    }

    /** AK5: stop → Identität sofort weg, active_count sinkt um 1. */
    public function testStopRemovesIdentityImmediately(): void
    {
        $a = self::uuid4();
        $b = self::uuid4();
        $this->presence->heartbeat(null, $a);
        $this->presence->heartbeat(null, $b);
        $this->assertSame(2, $this->presence->activeCount());

        $res = $this->presence->stop(null, $a);
        $this->assertSame(1, $res['active_count']);
        $this->assertSame(1, $this->presence->activeCount());
    }

    /** AK6: active() ohne User-Kontext → active_count (öffentliche Zahl). */
    public function testActiveIsPublicCounter(): void
    {
        $this->presence->heartbeat(null, self::uuid4());
        $res = $this->presence->active();
        $this->assertArrayHasKey('active_count', $res);
        $this->assertSame(1, $res['active_count']);
    }

    public function testAnonymousDisabledSkipsSessionHeartbeats(): void
    {
        $this->pdo->exec(
            "INSERT INTO game_config (config_key, config_value) VALUES
                ('presence_count_anonymous', '0')
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)"
        );
        $presence = new PresenceService(new PresenceRepository($this->pdo), new GameConfig($this->pdo));

        $presence->heartbeat(null, self::uuid4());
        $this->assertSame(0, $presence->activeCount());

        $userId = $this->createUser('logged');
        $presence->heartbeat($userId, self::uuid4());
        $this->assertSame(1, $presence->activeCount());
    }
}
