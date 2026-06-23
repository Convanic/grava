<?php
declare(strict_types=1);

namespace App\Integrations\Strava;

/**
 * M4e Dev-Seam: deterministischer Fake-Client für Smoke-Tests und
 * lokale Entwicklung ohne Strava-Credentials/Netz.
 *
 * Aktiviert über STRAVA_FAKE=1 (oder automatisch, wenn keine
 * STRAVA_CLIENT_ID gesetzt ist). Liefert zwei feste Activities, von
 * denen eine eine GPS-Spur hat und eine nicht (für den „skip ohne
 * Geo"-Pfad).
 */
final class FakeStravaClient implements StravaClient
{
    public function exchangeCode(string $code): array
    {
        // Der Fake akzeptiert jeden Code; athlete_id ist stabil, damit
        // Re-Connect denselben Account trifft.
        return [
            'access_token'     => 'fake-access-' . substr(sha1($code), 0, 12),
            'refresh_token'    => 'fake-refresh-token',
            'expires_at'       => time() + 3600,
            'athlete_id'       => '99000001',
            'athlete_username' => 'fake_athlete',
            'scope'            => 'read,activity:read_all',
        ];
    }

    public function refreshToken(string $refreshToken): array
    {
        return [
            'access_token'  => 'fake-access-refreshed',
            'refresh_token' => $refreshToken,
            'expires_at'    => time() + 3600,
        ];
    }

    public function listActivities(string $accessToken, int $perPage = 30): array
    {
        return [
            [
                'id'         => '7000000001',
                'name'       => 'Morgendliche Gravel-Runde',
                'type'       => 'Ride',
                'start_date' => '2026-05-01T07:30:00Z',
            ],
            [
                'id'         => '7000000002',
                'name'       => 'Indoor Trainer (ohne GPS)',
                'type'       => 'VirtualRide',
                'start_date' => '2026-05-02T18:00:00Z',
            ],
        ];
    }

    public function getActivityStreams(string $accessToken, string $activityId): array
    {
        // Aktivität 2 hat keine GPS-Spur → leere latlng.
        if ($activityId === '7000000002') {
            return ['latlng' => [], 'altitude' => []];
        }

        // Aktivität 1: kleine Spur im Kraichgau.
        return [
            'latlng' => [
                [49.1000, 8.7000],
                [49.1010, 8.7020],
                [49.1025, 8.7035],
                [49.1040, 8.7055],
            ],
            'altitude' => [180.0, 192.0, 205.0, 198.0],
        ];
    }
}
