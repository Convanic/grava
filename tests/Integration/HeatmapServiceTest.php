<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Heatmap\HeatmapService;
use Tests\IntegrationTestCase;

final class HeatmapServiceTest extends IntegrationTestCase
{
    private HeatmapService $heatmap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->heatmap = new HeatmapService();
    }

    public function testRebuildCountsOnlyPublicRoutes(): void
    {
        $owner = $this->createUser();
        $this->createRoute($owner, 'public',   49.50, 8.50);
        $this->createRoute($owner, 'public',   49.51, 8.51); // selbe Zelle
        $this->createRoute($owner, 'private',  49.50, 8.50); // zählt nicht
        $this->createRoute($owner, 'public',   48.20, 12.40); // andere Zelle
        $this->createRoute($owner, 'public',   40.00, 2.00, deleted: true); // soft-deleted

        $cells = $this->heatmap->rebuild();
        $this->assertSame(2, $cells);

        $fc = $this->heatmap->query(null);
        $this->assertSame('FeatureCollection', $fc['type']);
        $this->assertSame(2, $fc['meta']['cell_count']);
        $this->assertSame(2, $fc['meta']['max_weight']);

        // GeoJSON-Reihenfolge: [lon, lat]
        $first = $fc['features'][0];
        $this->assertEqualsWithDelta(8.5, $first['geometry']['coordinates'][0], 0.06);
        $this->assertEqualsWithDelta(49.5, $first['geometry']['coordinates'][1], 0.06);
    }

    public function testQueryBboxFilters(): void
    {
        $owner = $this->createUser();
        $this->createRoute($owner, 'public', 49.50, 8.50);
        $this->createRoute($owner, 'public', 48.20, 12.40);
        $this->heatmap->rebuild();

        $bbox = ['min_lat' => 49.0, 'min_lon' => 8.0, 'max_lat' => 50.0, 'max_lon' => 9.0];
        $fc = $this->heatmap->query($bbox);
        $this->assertSame(1, $fc['meta']['cell_count']);
    }

    public function testEmptyWhenNoPublicRoutes(): void
    {
        $owner = $this->createUser();
        $this->createRoute($owner, 'private', 49.5, 8.5);
        $this->assertSame(0, $this->heatmap->rebuild());
        $this->assertSame([], $this->heatmap->query(null)['features']);
    }
}
