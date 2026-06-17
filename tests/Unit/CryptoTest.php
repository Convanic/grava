<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Crypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CryptoTest extends TestCase
{
    private function crypto(): Crypto
    {
        return new Crypto(base64_encode(str_repeat("\x01", 32)));
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $c = $this->crypto();
        $plain = 'strava-refresh-token-äöü-😀';
        $blob = $c->encrypt($plain);

        $this->assertNotSame($plain, $blob);
        $this->assertSame($plain, $c->decrypt($blob));
    }

    public function testCiphertextIsNonDeterministic(): void
    {
        $c = $this->crypto();
        $this->assertNotSame($c->encrypt('same'), $c->encrypt('same'));
    }

    public function testTamperingIsDetected(): void
    {
        $c = $this->crypto();
        $blob = $c->encrypt('secret');
        $blob[strlen($blob) - 1] = $blob[strlen($blob) - 1] === 'A' ? 'B' : 'A';

        $this->expectException(RuntimeException::class);
        $c->decrypt($blob);
    }

    public function testWrongKeyFailsToDecrypt(): void
    {
        $blob = $this->crypto()->encrypt('secret');
        $other = new Crypto(base64_encode(str_repeat("\x02", 32)));

        $this->expectException(RuntimeException::class);
        $other->decrypt($blob);
    }

    public function testInvalidKeyLengthRejected(): void
    {
        $this->expectException(RuntimeException::class);
        new Crypto(base64_encode('too-short'));
    }

    public function testTooShortBlobRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->crypto()->decrypt('xx');
    }
}
