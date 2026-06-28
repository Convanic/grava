<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Heatmap\HeatmapService;
use PHPUnit\Framework\TestCase;

/**
 * Deckt die reine (DB-freie) Aggregations-Logik der Heatmap-Antwort ab:
 * ohne grid-Override unverändert, mit gröberem grid Summe je Zelle
 * (Akzeptanzkriterium 4) plus konsistentes meta.
 */
final class HeatmapBuildResponseTest extends TestCase
{
    public function testNoGridOverrideKeepsBaseCells(): void
    {
        $points = [
            ['lon' => 8.00, 'lat' => 49.00, 'weight' => 3],
            ['lon' => 8.06, 'lat' => 49.06, 'weight' => 5],
        ];
        $fc = HeatmapService::buildResponse($points, 0.05, null);

        $this->assertCount(2, $fc['features']);
        $this->assertSame(0.05, $fc['meta']['grid']);
        $this->assertSame(2, $fc['meta']['cell_count']);
        $this->assertSame(5, $fc['meta']['max_weight']);
    }

    public function testGridOverrideAggregatesWeights(): void
    {
        // Bei grid=0.1 fallen beide Punkte in dieselbe Zelle → Summe 8.
        $points = [
            ['lon' => 8.00, 'lat' => 49.00, 'weight' => 3],
            ['lon' => 8.06, 'lat' => 49.06, 'weight' => 5],
        ];
        $fc = HeatmapService::buildResponse($points, 0.05, 0.1);

        $this->assertCount(1, $fc['features']);
        $this->assertSame(0.1, $fc['meta']['grid']);
        $this->assertSame(8, $fc['meta']['max_weight']);
        $this->assertSame(8, $fc['features'][0]['properties']['weight']);
    }

    public function testFinerGridThanBaseIsIgnored(): void
    {
        $points = [['lon' => 8.0, 'lat' => 49.0, 'weight' => 2]];
        $fc = HeatmapService::buildResponse($points, 0.05, 0.01);

        // Feiner als die Quelle ist nicht möglich → Basisgitter bleibt.
        $this->assertSame(0.05, $fc['meta']['grid']);
    }
}
