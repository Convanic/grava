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
    /** @var array<string,array{activity_id:string,description:?string,visibility:?string}> */
    private array $uploadsByExternal = [];

    /** @var array<string,string> upload_id → external_id */
    private array $uploadExternal = [];

    /** @var array<string,int> Poll-Zähler je upload_id */
    private array $uploadPolls = [];

    public function exchangeCode(string $code): array
    {
        return [
            'access_token'     => 'fake-access-' . substr(sha1($code), 0, 12),
            'refresh_token'    => 'fake-refresh-token',
            'expires_at'       => time() + 3600,
            'athlete_id'       => '99000001',
            'athlete_username' => 'fake_athlete',
            'scope'            => 'read,activity:read_all,activity:write',
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
        if ($activityId === '7000000002') {
            return ['latlng' => [], 'altitude' => []];
        }

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

    public function uploadActivity(
        string $accessToken,
        string $fileContents,
        string $dataType,
        string $name,
        string $description,
        string $externalId,
    ): array {
        if ($externalId !== '' && isset($this->uploadsByExternal[$externalId])) {
            $existing = $this->uploadsByExternal[$externalId]['activity_id'];
            if ($description !== '') {
                $this->uploadsByExternal[$externalId]['description'] = $description;
            }
            return [
                'upload_id'   => 'fake-upload-existing-' . substr(sha1($externalId), 0, 8),
                'external_id' => $externalId,
                'activity_id' => $existing,
            ];
        }

        $uploadId = 'fake-upload-' . substr(sha1($externalId . $name), 0, 10);
        $activityId = (string)(8800000000 + hexdec(substr(sha1($externalId), 0, 6)) % 1000000);
        $this->uploadPolls[$uploadId] = 0;
        if ($externalId !== '') {
            $this->uploadsByExternal[$externalId] = [
                'activity_id' => $activityId,
                'description' => $description,
                'visibility'  => null,
            ];
            $this->uploadExternal[$uploadId] = $externalId;
        }

        return ['upload_id' => $uploadId, 'external_id' => $externalId];
    }

    public function getUploadStatus(string $accessToken, string $uploadId): array
    {
        $this->uploadPolls[$uploadId] = ($this->uploadPolls[$uploadId] ?? 0) + 1;
        if (($this->uploadPolls[$uploadId] ?? 0) < 1) {
            return ['activity_id' => null, 'error' => null, 'status' => 202];
        }

        $ext = $this->uploadExternal[$uploadId] ?? null;
        if ($ext !== null && isset($this->uploadsByExternal[$ext])) {
            return [
                'activity_id' => $this->uploadsByExternal[$ext]['activity_id'],
                'error'       => null,
                'status'      => 200,
            ];
        }

        return ['activity_id' => '8800000001', 'error' => null, 'status' => 200];
    }

    public function updateActivity(
        string $accessToken,
        string $activityId,
        ?string $description,
        ?string $visibility,
    ): void {
        foreach ($this->uploadsByExternal as $ext => $row) {
            if ($row['activity_id'] === $activityId) {
                if ($description !== null) {
                    $this->uploadsByExternal[$ext]['description'] = $description;
                }
                if ($visibility !== null) {
                    $this->uploadsByExternal[$ext]['visibility'] = $visibility;
                }
                return;
            }
        }
    }

    /** Test-Helfer: gespeicherte Beschreibung je external_id. */
    public function fakeDescriptionForExternal(string $externalId): ?string
    {
        return $this->uploadsByExternal[$externalId]['description'] ?? null;
    }

    /** Test-Helfer: activity_id je external_id. */
    public function fakeActivityForExternal(string $externalId): ?string
    {
        return $this->uploadsByExternal[$externalId]['activity_id'] ?? null;
    }
}
