<?php
declare(strict_types=1);

namespace App\Push;

/**
 * Erzeugt das APNs Provider-Token (ES256-JWT) aus dem .p8-Key.
 * Ausgelagert aus {@see ApnsHttpClient}, damit die Signatur-Logik
 * (inkl. DER→JOSE-Konvertierung) isoliert testbar ist.
 */
final class ApnsJwt
{
    /**
     * @throws \RuntimeException wenn Key ungültig ist oder Signatur scheitert
     */
    public static function provider(string $keyPem, string $keyId, string $teamId, int $now): string
    {
        $header = ['alg' => 'ES256', 'kid' => $keyId];
        $claims = ['iss' => $teamId, 'iat' => $now];
        $signingInput = self::b64url((string)json_encode($header))
            . '.' . self::b64url((string)json_encode($claims));

        $key = openssl_pkey_get_private($keyPem);
        if ($key === false) {
            throw new \RuntimeException('APNs-Key konnte nicht geladen werden.');
        }
        $der = '';
        if (!openssl_sign($signingInput, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('APNs-JWT-Signatur fehlgeschlagen.');
        }
        return $signingInput . '.' . self::b64url(self::derToRawSignature($der));
    }

    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * DER-ECDSA-Signatur (SEQUENCE{INTEGER r, INTEGER s}) → rohes 64-Byte
     * R||S (JWS/ES256).
     */
    public static function derToRawSignature(string $der): string
    {
        $pos = 0;
        if ($der === '' || ord($der[$pos++]) !== 0x30) {
            throw new \RuntimeException('Ungültige DER-Signatur (SEQUENCE).');
        }
        self::readLength($der, $pos); // Sequenzlänge überspringen
        $r = self::readInteger($der, $pos);
        $s = self::readInteger($der, $pos);
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }

    private static function readLength(string $der, int &$pos): int
    {
        $b = ord($der[$pos++]);
        if ($b < 0x80) {
            return $b;
        }
        $n = $b & 0x7f;
        $len = 0;
        for ($i = 0; $i < $n; $i++) {
            $len = ($len << 8) | ord($der[$pos++]);
        }
        return $len;
    }

    private static function readInteger(string $der, int &$pos): string
    {
        if (ord($der[$pos++]) !== 0x02) {
            throw new \RuntimeException('Ungültige DER-Signatur (INTEGER).');
        }
        $len = self::readLength($der, $pos);
        $val = substr($der, $pos, $len);
        $pos += $len;
        return ltrim($val, "\x00");
    }
}
