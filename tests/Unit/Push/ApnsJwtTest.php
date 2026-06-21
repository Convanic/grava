<?php
declare(strict_types=1);

namespace Tests\Unit\Push;

use App\Push\ApnsJwt;
use PHPUnit\Framework\TestCase;

final class ApnsJwtTest extends TestCase
{
    public function testProducesVerifiableEs256Token(): void
    {
        // Frischer P-256-Key (wie ein .p8 ECDSA-Key).
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        $this->assertNotFalse($res, 'EC-Key konnte nicht erzeugt werden.');
        openssl_pkey_export($res, $privatePem);
        $details = openssl_pkey_get_details($res);
        $publicPem = $details['key'];

        $now = 1_700_000_000;
        $jwt = ApnsJwt::provider($privatePem, 'ABCDE12345', 'TEAMID9999', $now);

        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'JWT muss aus drei Teilen bestehen.');

        $header = json_decode(self::b64urlDecode($parts[0]), true);
        $claims = json_decode(self::b64urlDecode($parts[1]), true);
        $this->assertSame('ES256', $header['alg']);
        $this->assertSame('ABCDE12345', $header['kid']);
        $this->assertSame('TEAMID9999', $claims['iss']);
        $this->assertSame($now, $claims['iat']);

        $rawSig = self::b64urlDecode($parts[2]);
        $this->assertSame(64, strlen($rawSig), 'ES256-Signatur muss 64 Byte (R||S) sein.');

        // Signatur gegen den Public Key prüfen: rohes R||S → DER zurückwandeln.
        $der = self::rawToDer($rawSig);
        $ok = openssl_verify($parts[0] . '.' . $parts[1], $der, $publicPem, OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $ok, 'JWT-Signatur muss gegen den Public Key verifizieren.');
    }

    public function testInvalidKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        ApnsJwt::provider('not-a-key', 'KID', 'TID', 1);
    }

    private static function b64urlDecode(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/'));
    }

    /** rohes 64-Byte R||S → DER SEQUENCE{INTEGER r, INTEGER s} */
    private static function rawToDer(string $raw): string
    {
        $r = self::derInteger(substr($raw, 0, 32));
        $s = self::derInteger(substr($raw, 32, 32));
        $seq = $r . $s;
        return "\x30" . chr(strlen($seq)) . $seq;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes; // positiv halten
        }
        return "\x02" . chr(strlen($bytes)) . $bytes;
    }
}
