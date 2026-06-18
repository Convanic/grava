<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Config;
use App\Referral\ReferralService;
use Tests\IntegrationTestCase;

/**
 * M7: Empfehlungen — deckt die Akzeptanzkriterien aus REFERRALS_BACKEND.md ab.
 */
final class ReferralServiceTest extends IntegrationTestCase
{
    private ReferralService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ReferralService(Config::instance());
    }

    public function testEnsureCodeIsStableAndUnique(): void
    {
        $u1 = $this->createUser('armin');
        $u2 = $this->createUser('lena');

        $c1 = $this->svc->ensureCode($u1);
        $this->assertNotSame('', $c1);
        // Idempotent: zweiter Aufruf liefert denselben Code.
        $this->assertSame($c1, $this->svc->ensureCode($u1));

        $c2 = $this->svc->ensureCode($u2);
        $this->assertNotSame($c1, $c2);

        // Slug-Basis aus Handle.
        $this->assertStringStartsWith('armin-', $c1);
    }

    public function testCodeWithoutHandleIsRandom(): void
    {
        $u = $this->createUser(null);
        // display_name fällt im Helper auf 'Test User' zurück → Slug 'testuser'.
        $code = $this->svc->ensureCode($u);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,16}$/', $code);
    }

    public function testLinkOnRegisterCreatesRegisteredRow(): void
    {
        $referrer = $this->createUser('werber');
        $code = $this->svc->ensureCode($referrer);
        $referred = $this->createUser('geworben');

        $this->svc->linkOnRegister($referred, $code);

        $this->assertSame($referrer, (int)$this->scalar('SELECT referred_by FROM users WHERE id = ?', [$referred]));
        $row = $this->row('SELECT * FROM referrals WHERE referred_user_id = ?', [$referred]);
        $this->assertSame($referrer, (int)$row['referrer_id']);
        $this->assertSame('registered', $row['status']);
        $this->assertSame($code, $row['code']);
    }

    public function testUnknownCodeIsIgnored(): void
    {
        $referred = $this->createUser('geworben');
        $this->svc->linkOnRegister($referred, 'gibtsnicht');

        $this->assertNull($this->scalar('SELECT referred_by FROM users WHERE id = ?', [$referred]));
        $this->assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM referrals WHERE referred_user_id = ?', [$referred]));
    }

    public function testSelfReferralPrevented(): void
    {
        $u = $this->createUser('solo');
        $code = $this->svc->ensureCode($u);

        $this->svc->linkOnRegister($u, $code);

        $this->assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM referrals WHERE referred_user_id = ?', [$u]));
    }

    public function testNoDoubleCount(): void
    {
        $referrer = $this->createUser('werber');
        $code = $this->svc->ensureCode($referrer);
        $referred = $this->createUser('geworben');

        $this->svc->linkOnRegister($referred, $code);
        $this->svc->linkOnRegister($referred, $code);

        $this->assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM referrals WHERE referred_user_id = ?', [$referred]));
    }

    public function testStatusProgression(): void
    {
        $referrer = $this->createUser('werber');
        $code = $this->svc->ensureCode($referrer);
        $referred = $this->createUser('geworben');
        $this->svc->linkOnRegister($referred, $code);

        // activated greift NICHT, solange nicht verified.
        $this->svc->markActivated($referred);
        $this->assertSame('registered', $this->scalar('SELECT status FROM referrals WHERE referred_user_id = ?', [$referred]));

        $this->svc->markVerified($referred);
        $this->assertSame('verified', $this->scalar('SELECT status FROM referrals WHERE referred_user_id = ?', [$referred]));

        $this->svc->markActivated($referred);
        $this->assertSame('activated', $this->scalar('SELECT status FROM referrals WHERE referred_user_id = ?', [$referred]));

        // Erneutes markVerified ändert nichts mehr (nur registered→verified).
        $this->svc->markVerified($referred);
        $this->assertSame('activated', $this->scalar('SELECT status FROM referrals WHERE referred_user_id = ?', [$referred]));
    }

    public function testOverviewCountsAndList(): void
    {
        $referrer = $this->createUser('werber');
        $code = $this->svc->ensureCode($referrer);

        $a = $this->createUser('alpha');   // bleibt registered
        $b = $this->createUser('beta');    // verified
        $c = $this->createUser('gamma');   // activated
        foreach ([$a, $b, $c] as $id) {
            $this->svc->linkOnRegister($id, $code);
        }
        $this->svc->markVerified($b);
        $this->svc->markVerified($c);
        $this->svc->markActivated($c);

        $ov = $this->svc->overviewForUser($referrer);

        $this->assertSame($code, $ov['code']);
        $this->assertStringEndsWith('/i/' . $code, $ov['url']);
        // Kumulativ: registered=alle(3), verified=≥verified(2), activated=1.
        $this->assertSame(3, $ov['counts']['registered']);
        $this->assertSame(2, $ov['counts']['verified']);
        $this->assertSame(1, $ov['counts']['activated']);
        $this->assertCount(3, $ov['referred']);
        // Keine E-Mails in der Ausgabe.
        $this->assertArrayNotHasKey('email', $ov['referred'][0]);
    }

    public function testAdminReportAggregatesAndSorts(): void
    {
        $r1 = $this->createUser('top');
        $r2 = $this->createUser('low');
        $code1 = $this->svc->ensureCode($r1);
        $code2 = $this->svc->ensureCode($r2);

        // r1: 2 verified
        foreach (['t1', 't2'] as $h) {
            $id = $this->createUser($h);
            $this->svc->linkOnRegister($id, $code1);
            $this->svc->markVerified($id);
        }
        // r2: 1 registered
        $id = $this->createUser('l1');
        $this->svc->linkOnRegister($id, $code2);

        $report = $this->svc->adminReport();
        $this->assertCount(2, $report);
        // Bestenliste: r1 (2 verified) zuerst.
        $this->assertSame($r1, $report[0]['referrer_id']);
        $this->assertSame(2, $report[0]['verified']);
        $this->assertSame(1.0, $report[0]['conversion']);
        $this->assertSame($r2, $report[1]['referrer_id']);
        $this->assertSame(0, $report[1]['verified']);
    }

    public function testResolveReferrerActiveOnly(): void
    {
        $u = $this->createUser('werber');
        $code = $this->svc->ensureCode($u);
        $this->assertSame($u, $this->svc->resolveReferrer($code));
        $this->assertNull($this->svc->resolveReferrer('nope'));

        $this->pdo->prepare('UPDATE users SET status = "deleted" WHERE id = ?')->execute([$u]);
        $this->assertNull($this->svc->resolveReferrer($code));
    }

    /** @param array<int,mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    /**
     * @param array<int,mixed> $params
     * @return array<string,mixed>
     */
    private function row(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        $this->assertNotFalse($row, 'Erwartete Zeile nicht gefunden.');
        return $row;
    }
}
