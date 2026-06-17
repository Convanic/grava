<?php
declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * M4e: Symmetrische Verschlüsselung at-rest (AES-256-GCM).
 *
 * Wird für OAuth-Tokens (Strava) verwendet. Der Schlüssel kommt aus
 * APP_KEY (Base64-kodierte 32 Bytes). Format des Ciphertexts:
 *
 *     nonce(12 Bytes) || tag(16 Bytes) || ciphertext
 *
 * GCM liefert Authentizität mit (AEAD) — Manipulation am Blob führt
 * beim Entschlüsseln zu einer Exception statt zu falschen Klartexten.
 */
final class Crypto
{
    private const CIPHER     = 'aes-256-gcm';
    private const NONCE_LEN  = 12;
    private const TAG_LEN    = 16;

    private readonly string $key;

    public function __construct(string $appKeyBase64)
    {
        $key = base64_decode($appKeyBase64, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(
                'Crypto: APP_KEY muss Base64 von genau 32 Bytes sein (AES-256).'
            );
        }
        $this->key = $key;
    }

    /** Verschlüsselt Klartext → binärer Blob (nonce|tag|ciphertext). */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_LEN);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LEN,
        );
        if ($ciphertext === false) {
            throw new RuntimeException('Crypto: Verschlüsselung fehlgeschlagen.');
        }
        return $nonce . $tag . $ciphertext;
    }

    /** Entschlüsselt einen Blob aus {@see encrypt()}. */
    public function decrypt(string $blob): string
    {
        if (strlen($blob) < self::NONCE_LEN + self::TAG_LEN) {
            throw new RuntimeException('Crypto: Ciphertext zu kurz/korrupt.');
        }
        $nonce      = substr($blob, 0, self::NONCE_LEN);
        $tag        = substr($blob, self::NONCE_LEN, self::TAG_LEN);
        $ciphertext = substr($blob, self::NONCE_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );
        if ($plaintext === false) {
            throw new RuntimeException('Crypto: Entschlüsselung fehlgeschlagen (Tag-Mismatch?).');
        }
        return $plaintext;
    }
}
