<?php
declare(strict_types=1);

namespace App\Push;

/**
 * Echter APNs-Versand über HTTP/2 mit Token-based Auth (ES256-JWT,
 * .p8-Key). Ein JWT wird ~50 Minuten gecacht (Apple erlaubt < 60 min).
 *
 * Fehler werden geloggt und als Status 0 signalisiert — der Aufrufer
 * (PushService) behandelt den Versand stets als best effort und lässt
 * die auslösende Aktion niemals daran scheitern.
 */
final class ApnsHttpClient implements ApnsTransport
{
    private ?string $cachedJwt = null;
    private int $jwtExpiresAt = 0;

    public function __construct(private readonly ApnsConfig $config) {}

    public function send(string $environment, string $deviceToken, array $payload, ?string $collapseId = null): int
    {
        if (!$this->config->usable() || $deviceToken === '') {
            return 0;
        }
        if (!function_exists('curl_init')) {
            error_log('APNs: ext-curl fehlt — Push übersprungen.');
            return 0;
        }

        try {
            $jwt = $this->jwt();
        } catch (\Throwable $e) {
            error_log('APNs JWT-Fehler: ' . $e->getMessage());
            return 0;
        }

        $host = $environment === 'production'
            ? 'api.push.apple.com'
            : 'api.sandbox.push.apple.com';
        $url = 'https://' . $host . '/3/device/' . $deviceToken;

        $headers = [
            'authorization: bearer ' . $jwt,
            'apns-topic: ' . $this->config->bundleId,
            'apns-push-type: alert',
            'apns-priority: 10',
            'content-type: application/json',
        ];
        if ($collapseId !== null && $collapseId !== '') {
            $headers[] = 'apns-collapse-id: ' . $collapseId;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($status === 0) {
            error_log('APNs Transport-Fehler: ' . $err);
        } elseif ($status >= 400 && $status !== 410) {
            error_log(sprintf('APNs %d für Token …%s: %s', $status, substr($deviceToken, -6), is_string($body) ? $body : ''));
        }
        return $status;
    }

    /** Erzeugt (oder liefert gecacht) das ES256-Provider-Token. */
    private function jwt(): string
    {
        $now = time();
        if ($this->cachedJwt !== null && $now < $this->jwtExpiresAt) {
            return $this->cachedJwt;
        }
        $jwt = ApnsJwt::provider($this->config->keyPem, $this->config->keyId, $this->config->teamId, $now);
        $this->cachedJwt    = $jwt;
        $this->jwtExpiresAt = $now + 3000; // ~50 min
        return $jwt;
    }
}
