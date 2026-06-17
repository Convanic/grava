<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Config;
use App\Integrations\Strava\FakeStravaClient;
use App\Integrations\Strava\StravaService;
use App\Routes\GeometryParser;
use App\Routes\GeometryStats;
use App\Routes\RouteRepository;
use App\Routes\RouteService;
use App\Routes\RouteStorage;
use App\Support\Crypto;
use Tests\IntegrationTestCase;

final class StravaImportTest extends IntegrationTestCase
{
    private StravaService $strava;

    protected function setUp(): void
    {
        parent::setUp();
        $config = Config::instance();
        $routes = new RouteService(
            new RouteRepository(),
            new RouteStorage($config),
            new GeometryParser(),
            new GeometryStats(),
        );
        $crypto = new Crypto(base64_encode(str_repeat("\x07", 32)));
        $this->strava = new StravaService(
            new FakeStravaClient(),
            $crypto,
            $routes,
            'fake-client-id',
            'http://localhost/auth/strava/callback',
            true,
            'http://localhost',
        );
    }

    private function connect(int $userId): void
    {
        $url = $this->strava->authorizeUrl($userId);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->strava->handleCallback((string)$q['state'], 'fake-auth-code');
    }

    public function testStatusInitiallyDisconnected(): void
    {
        $userId = $this->createUser();
        $this->assertFalse($this->strava->status($userId)['connected']);
    }

    public function testConnectThenImport(): void
    {
        $userId = $this->createUser();
        $this->connect($userId);
        $this->assertTrue($this->strava->status($userId)['connected']);

        // Activity 1 hat GPS -> importiert; Activity 2 (Indoor) -> skip.
        $res = $this->strava->import($userId);
        $this->assertSame(1, $res['imported']);
        $this->assertSame(1, $res['skipped']);
        $this->assertSame(2, $res['total']);

        $count = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM routes WHERE user_id = {$userId} AND source = 'strava'"
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testReimportIsIdempotent(): void
    {
        $userId = $this->createUser();
        $this->connect($userId);
        $this->strava->import($userId);

        $res = $this->strava->import($userId);
        $this->assertSame(0, $res['imported']);
        $this->assertSame(2, $res['skipped']);

        $count = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM routes WHERE user_id = {$userId}"
        )->fetchColumn();
        $this->assertSame(1, $count, 'kein Duplikat beim Re-Import');
    }

    public function testImportWithoutConnectionThrows(): void
    {
        $userId = $this->createUser();
        $this->expectException(\App\Integrations\Strava\StravaException::class);
        $this->strava->import($userId);
    }

    public function testCallbackRejectsForeignSession(): void
    {
        $victim   = $this->createUser();
        $attacker = $this->createUser();

        // Victim startet den Connect (State gehört dem Victim) ...
        $url = $this->strava->authorizeUrl($victim);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);

        // ... aber der Callback läuft in der Session des Attackers.
        try {
            $this->strava->handleCallback((string)$q['state'], 'fake-auth-code', $attacker);
            $this->fail('StravaException erwartet (Session-Mismatch)');
        } catch (\App\Integrations\Strava\StravaException $e) {
            $this->assertSame('oauth_state_invalid', $e->errorCode);
        }

        // Weder Victim noch Attacker dürfen jetzt verbunden sein.
        $this->assertFalse($this->strava->status($victim)['connected']);
        $this->assertFalse($this->strava->status($attacker)['connected']);
    }
}
