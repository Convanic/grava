<?php
declare(strict_types=1);

namespace Tests\Unit\Privacy;

use App\Privacy\PrivacyZone;
use App\Privacy\RoutePrivacyTrimmer;
use PHPUnit\Framework\TestCase;

final class RoutePrivacyTrimmerTest extends TestCase
{
    private function fc(array $coords, array $hints = []): array
    {
        $fc = [
            'type' => 'FeatureCollection',
            'features' => [[
                'type' => 'Feature',
                'properties' => (object)[],
                'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
            ]],
        ];
        if ($hints !== []) {
            $fc['hints'] = $hints;
        }
        return $fc;
    }

    public function testTrimsTrailingPointsInsideZone(): void
    {
        // Zone um (48.20, 11.60). Linie startet weit weg und endet im Zentrum.
        $zone = new PrivacyZone(48.20, 11.60, 500);
        $coords = [[11.70, 48.30], [11.68, 48.28], [11.6005, 48.2005], [11.60, 48.20]];
        $out = (new RoutePrivacyTrimmer())->trim($this->fc($coords), $zone);

        $this->assertCount(1, $out['features']);
        $kept = $out['features'][0]['geometry']['coordinates'];
        // Die zwei Punkte in der Zone sind entfernt.
        $this->assertSame([[11.70, 48.30], [11.68, 48.28]], $kept);
    }

    public function testSplitsLineWhenZoneIsInTheMiddle(): void
    {
        $zone = new PrivacyZone(48.20, 11.60, 500);
        $coords = [
            [11.70, 48.30], [11.69, 48.29],     // Lauf A (außerhalb)
            [11.6003, 48.2003], [11.60, 48.20], // in der Zone -> entfernt
            [11.50, 48.10], [11.49, 48.09],     // Lauf B (außerhalb)
        ];
        $out = (new RoutePrivacyTrimmer())->trim($this->fc($coords), $zone);

        // Zwei getrennte LineStrings, kein gerader Sprung über die Zone.
        $this->assertCount(2, $out['features']);
        $this->assertSame([[11.70, 48.30], [11.69, 48.29]], $out['features'][0]['geometry']['coordinates']);
        $this->assertSame([[11.50, 48.10], [11.49, 48.09]], $out['features'][1]['geometry']['coordinates']);
    }

    public function testStripsHintsInsideZone(): void
    {
        $zone = new PrivacyZone(48.20, 11.60, 500);
        $coords = [[11.70, 48.30], [11.69, 48.29]];
        $hints = [
            ['lat' => 48.2002, 'lon' => 11.6002, 'kind' => 'home'],   // in Zone -> weg
            ['lat' => 48.30, 'lon' => 11.70, 'kind' => 'peak'],       // bleibt
        ];
        $out = (new RoutePrivacyTrimmer())->trim($this->fc($coords, $hints), $zone);

        $this->assertCount(1, $out['hints']);
        $this->assertSame('peak', $out['hints'][0]['kind']);
    }

    public function testLineFullyInsideZoneYieldsNoFeatures(): void
    {
        $zone = new PrivacyZone(48.20, 11.60, 800);
        $coords = [[11.6005, 48.2005], [11.6001, 48.2001], [11.60, 48.20]];
        $out = (new RoutePrivacyTrimmer())->trim($this->fc($coords), $zone);

        $this->assertSame([], $out['features']);
    }
}
