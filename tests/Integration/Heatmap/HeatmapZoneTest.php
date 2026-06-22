<?php
declare(strict_types=1);

namespace Tests\Integration\Heatmap;

use App\Heatmap\HeatmapService;
use App\Privacy\PrivacyZoneRepository;
use Tests\IntegrationTestCase;

/**
 * §17 Punkt 6: Centroid-Heatmap zählt keine Beiträge eines Nutzers innerhalb
 * seiner eigenen Privatzone.
 */
final class HeatmapZoneTest extends IntegrationTestCase
{
    public function testRouteWithCentroidInOwnZoneIsExcluded(): void
    {
        $owner = $this->createUser('owner');

        // Route, deren Centroid GENAU im Zonen-Mittelpunkt liegt → ausgeschlossen.
        $this->createRoute($owner, 'public', 48.2000, 11.6000);
        // Zweite Route weit außerhalb der Zone → bleibt sichtbar.
        $this->createRoute($owner, 'public', 49.5000, 8.5000);

        (new PrivacyZoneRepository($this->pdo))->upsert($owner, 48.2000, 11.6000, 500, true);

        $built = (new HeatmapService())->rebuild();
        $this->assertSame(1, $built, 'Nur die Route außerhalb der Zone erzeugt eine Zelle.');

        $fc = (new HeatmapService())->query(null, 5000);
        $this->assertCount(1, $fc['features']);
        $coords = $fc['features'][0]['geometry']['coordinates']; // [lon, lat]
        $this->assertEqualsWithDelta(8.5, $coords[0], 0.05);
        $this->assertEqualsWithDelta(49.5, $coords[1], 0.05);
    }

    public function testWithoutZoneBothRoutesCount(): void
    {
        $owner = $this->createUser('owner');
        $this->createRoute($owner, 'public', 48.2000, 11.6000);
        $this->createRoute($owner, 'public', 49.5000, 8.5000);

        $built = (new HeatmapService())->rebuild();
        $this->assertSame(2, $built);
    }
}
