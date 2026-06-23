<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Routes\RouteAdminService;
use App\Support\Clock;
use Tests\IntegrationTestCase;

final class RouteAdminServiceTest extends IntegrationTestCase
{
    private RouteAdminService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new RouteAdminService($this->pdo);
    }

    private function insertRoute(
        int $userId,
        string $title,
        string $source = 'app',
        string $visibility = 'public',
        bool $deleted = false,
    ): int {
        $now = Clock::nowUtcString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO routes
                (public_id, user_id, title, visibility, source, distance_m, centroid,
                 created_at, updated_at, deleted_at)
             VALUES (?, ?, ?, ?, ?, ?, ST_SRID(POINT(?, ?), 4326), ?, ?, ?)'
        );
        $stmt->execute([
            self::uuid4(), $userId, $title, $visibility, $source, 12345,
            8.5, 49.5, $now, $now, $deleted ? $now : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function attachVersion(int $routeId): void
    {
        $now = Clock::nowUtcString();
        $this->pdo->prepare(
            'INSERT INTO route_versions
                (route_id, version, format, payload_path, payload_sha256, payload_bytes,
                 point_count, distance_m, elevation_gain_m, created_at)
             VALUES (?, 1, "gpx", ?, ?, ?, 10, 12345, 100, ?)'
        )->execute([$routeId, $routeId . '/abc/v1.gpx', str_repeat('a', 64), 2048, $now]);
        $versionId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE routes SET head_version_id = ? WHERE id = ?')
            ->execute([$versionId, $routeId]);
    }

    public function testListsUploadsWithOwnerAndFileMeta(): void
    {
        $alice = $this->createUser('alice');
        $rid = $this->insertRoute($alice, 'Morgenrunde', 'strava');
        $this->attachVersion($rid);

        $res = $this->svc->listUploads();
        $this->assertSame(1, $res['total']);
        $row = $res['rows'][0];

        $this->assertSame('Morgenrunde', $row['title']);
        $this->assertSame('strava', $row['source']);
        $this->assertSame('alice', $row['handle']);
        $this->assertSame($alice, $row['user_id']);
        $this->assertSame(1, $row['version']);
        $this->assertSame('gpx', $row['format']);
        $this->assertSame(2048, $row['payload_bytes']);
        $this->assertSame($rid . '/abc/v1.gpx', $row['payload_path']);
        $this->assertNull($row['game_ingested_at']);
        $this->assertSame(0, $row['game_edges_count']);
    }

    public function testSourceAndQueryFilters(): void
    {
        $alice = $this->createUser('alice');
        $bob   = $this->createUser('bob');
        $this->insertRoute($alice, 'Gravel A', 'strava');
        $this->insertRoute($bob,   'Gravel B', 'app');

        $this->assertSame(2, $this->svc->listUploads()['total']);
        $this->assertSame(1, $this->svc->listUploads(['source' => 'strava'])['total']);
        $this->assertSame(1, $this->svc->listUploads(['source' => 'app'])['total']);

        // q trifft Owner-Handle.
        $byUser = $this->svc->listUploads(['q' => 'bob']);
        $this->assertSame(1, $byUser['total']);
        $this->assertSame('Gravel B', $byUser['rows'][0]['title']);

        // q trifft Routentitel.
        $this->assertSame(1, $this->svc->listUploads(['q' => 'gravel a'])['total']);
    }

    public function testDeletedHiddenByDefaultButShownOnRequest(): void
    {
        $alice = $this->createUser('alice');
        $this->insertRoute($alice, 'Aktiv', 'app');
        $this->insertRoute($alice, 'Gelöscht', 'app', 'public', true);

        $this->assertSame(1, $this->svc->listUploads()['total']);
        $this->assertSame(2, $this->svc->listUploads(['include_deleted' => true])['total']);

        $summary = $this->svc->summary();
        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['deleted']);
        $this->assertSame(1, $summary['by_source']['app']);
    }
}
