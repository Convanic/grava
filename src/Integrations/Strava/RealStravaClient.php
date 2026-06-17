<?php
declare(strict_types=1);

namespace App\Integrations\Strava;

/**
 * M4e: echter Strava-API-Client (OAuth2 + Activities/Streams) via cURL.
 *
 * Wird nur instanziiert, wenn STRAVA_CLIENT_ID/SECRET konfiguriert
 * sind und STRAVA_FAKE nicht aktiv ist. Mangels Test-Credentials ist
 * dieser Pfad NICHT Teil des automatisierten Smoke-Tests (dort läuft
 * der FakeStravaClient). Die Implementierung folgt der offiziellen
 * Strava-API v3.
 */
final class RealStravaClient implements StravaClient
{
    private const TOKEN_URL = 'https://www.strava.com/oauth/token';
    private const API_BASE  = 'https://www.strava.com/api/v3';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {}

    public function exchangeCode(string $code): array
    {
        $res = $this->postForm(self::TOKEN_URL, [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'grant_type'    => 'authorization_code',
        ]);
        $athlete = $res['athlete'] ?? [];
        return [
            'access_token'     => (string)($res['access_token'] ?? ''),
            'refresh_token'    => (string)($res['refresh_token'] ?? ''),
            'expires_at'       => (int)($res['expires_at'] ?? (time() + 3600)),
            'athlete_id'       => (string)($athlete['id'] ?? ''),
            'athlete_username' => isset($athlete['username']) ? (string)$athlete['username'] : null,
            'scope'            => null,
        ];
    }

    public function refreshToken(string $refreshToken): array
    {
        $res = $this->postForm(self::TOKEN_URL, [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);
        return [
            'access_token'  => (string)($res['access_token'] ?? ''),
            'refresh_token' => (string)($res['refresh_token'] ?? $refreshToken),
            'expires_at'    => (int)($res['expires_at'] ?? (time() + 3600)),
        ];
    }

    public function listActivities(string $accessToken, int $perPage = 30): array
    {
        $res = $this->get(self::API_BASE . '/athlete/activities?per_page=' . $perPage, $accessToken);
        $out = [];
        foreach ((array)$res as $a) {
            if (!is_array($a)) {
                continue;
            }
            $out[] = [
                'id'         => (string)($a['id'] ?? ''),
                'name'       => (string)($a['name'] ?? 'Strava-Aktivität'),
                'type'       => (string)($a['type'] ?? 'Ride'),
                'start_date' => isset($a['start_date']) ? (string)$a['start_date'] : null,
            ];
        }
        return $out;
    }

    public function getActivityStreams(string $accessToken, string $activityId): array
    {
        $url = self::API_BASE . '/activities/' . rawurlencode($activityId)
            . '/streams?keys=latlng,altitude&key_by_type=true';
        $res = $this->get($url, $accessToken);
        $latlng = [];
        foreach ((array)($res['latlng']['data'] ?? []) as $pair) {
            if (is_array($pair) && count($pair) >= 2) {
                $latlng[] = [(float)$pair[0], (float)$pair[1]];
            }
        }
        $altitude = [];
        foreach ((array)($res['altitude']['data'] ?? []) as $alt) {
            $altitude[] = (float)$alt;
        }
        return ['latlng' => $latlng, 'altitude' => $altitude];
    }

    /**
     * @param array<string,string> $fields
     * @return array<string,mixed>
     */
    private function postForm(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        return $this->exec($ch);
    }

    /** @return array<string,mixed> */
    private function get(string $url, string $accessToken): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        return $this->exec($ch);
    }

    /** @return array<string,mixed> */
    private function exec(\CurlHandle $ch): array
    {
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new StravaException('strava_api_error',
                'Strava-API-Fehler (HTTP ' . $status . '): ' . $err, 502);
        }
        $decoded = json_decode((string)$body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
