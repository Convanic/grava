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
    private RouteService $routes;
    private Crypto $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        $config = Config::instance();
        $this->routes = new RouteService(
            new RouteRepository(),
            new RouteStorage($config),
            new GeometryParser(),
            new GeometryStats(),
        );
        $this->crypto = new Crypto(base64_encode(str_repeat("\x07", 32)));
        $this->strava = new StravaService(
            new FakeStravaClient(),
            $this->crypto,
            $this->routes,
            'fake-client-id',
            'http://localhost/auth/strava/callback',
            true,
            'http://localhost',
        );
    }

    /** Service im ECHTEN Modus (fakeMode=false) — für die Authorize-URL. */
    private function realModeService(): StravaService
    {
        return new StravaService(
            new FakeStravaClient(),
            $this->crypto,
            $this->routes,
            'real-client-id',
            'https://grava.world/auth/strava/callback',
            false,
            'https://grava.world',
        );
    }

    private function connect(int $userId): void
    {
        // Mobile-Flow: session-los, State als Bindung.
        $url = $this->strava->authorizeUrl($userId, 'mobile');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->strava->handleCallback((string)$q['state'], 'fake-auth-code');
    }

    public function testStatusInitiallyDisconnected(): void
    {
        $userId = $this->createUser();
        $this->assertFalse($this->strava->status($userId)['connected']);
    }

    public function testAuthorizeUrlRequestsActivityReadAllScope(): void
    {
        $userId = $this->createUser();
        $url = $this->realModeService()->authorizeUrl($userId);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://www.strava.com/oauth/authorize', $url);
        $this->assertSame('read,activity:read_all', $q['scope'] ?? null,
            'Privatе Aktivitäten + GPS-Streams brauchen activity:read_all.');
    }

    public function testStatusExposesModeFlags(): void
    {
        $userId = $this->createUser();
        // Fake-Service: configured (Fake zählt als konfiguriert) + fake_mode=true.
        $fake = $this->strava->status($userId);
        $this->assertTrue($fake['configured']);
        $this->assertTrue($fake['fake_mode']);
        // Echter Modus: configured=true, fake_mode=false (so sieht man auf Prod,
        // dass die echten Credentials greifen).
        $real = $this->realModeService()->status($userId);
        $this->assertTrue($real['configured']);
        $this->assertFalse($real['fake_mode']);
    }

    public function testMobileFlowCompletesWithoutSession(): void
    {
        $userId = $this->createUser();
        $url = $this->strava->authorizeUrl($userId, 'mobile', 'grava://strava-connected');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);

        // Kein expectedUserId (keine Web-Session) — der State genügt.
        $res = $this->strava->handleCallback((string)$q['state'], 'fake-auth-code');
        $this->assertSame($userId, $res['user_id']);
        $this->assertSame('mobile', $res['flow']);
        $this->assertSame('grava://strava-connected', $res['return_to']);
        $this->assertTrue($this->strava->status($userId)['connected']);
    }

    public function testWebFlowRejectsCallbackWithoutSession(): void
    {
        $userId = $this->createUser();
        $url = $this->strava->authorizeUrl($userId, 'web');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);

        try {
            $this->strava->handleCallback((string)$q['state'], 'fake-auth-code'); // ohne Session
            $this->fail('Web-Flow muss ohne Session ablehnen.');
        } catch (\App\Integrations\Strava\StravaException $e) {
            $this->assertSame('oauth_state_invalid', $e->errorCode);
        }
        $this->assertFalse($this->strava->status($userId)['connected']);

        // Mit passender Session verbindet der Web-Flow normal.
        $url2 = $this->strava->authorizeUrl($userId, 'web');
        parse_str((string)parse_url($url2, PHP_URL_QUERY), $q2);
        $res = $this->strava->handleCallback((string)$q2['state'], 'fake-auth-code', $userId);
        $this->assertSame('web', $res['flow']);
        $this->assertTrue($this->strava->status($userId)['connected']);
    }

    public function testConnectThenImport(): void
    {
        $userId = $this->createUser();
        $this->connect($userId);
        $status = $this->strava->status($userId);
        $this->assertTrue($status['connected']);
        $this->assertSame('read,activity:read_all', $status['scope'],
            'Gewährter Scope wird gespeichert und im Status gemeldet.');

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
