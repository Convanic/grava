<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Heatmap\EdgeKey;
use PHPUnit\Framework\TestCase;

final class EdgeKeyTest extends TestCase
{
    public function testKeyIsDirectionIndependent(): void
    {
        $geom = [[8.60, 49.12], [8.605, 49.125], [8.61, 49.13]];
        $forward = EdgeKey::for(100, $geom);
        $backward = EdgeKey::for(100, array_reverse($geom));

        $this->assertNotNull($forward);
        $this->assertSame($forward, $backward, 'Schlüssel muss richtungsunabhängig sein');
    }

    public function testDifferentWaysDiffer(): void
    {
        $geom = [[8.60, 49.12], [8.61, 49.13]];
        $this->assertNotSame(EdgeKey::for(100, $geom), EdgeKey::for(101, $geom));
    }

    public function testNullWayIdTreatedAsZero(): void
    {
        $geom = [[8.60, 49.12], [8.61, 49.13]];
        $this->assertSame(EdgeKey::for(null, $geom), EdgeKey::for(0, $geom));
    }

    public function testTooShortGeometryReturnsNull(): void
    {
        $this->assertNull(EdgeKey::for(100, []));
        $this->assertNull(EdgeKey::for(100, [[8.60, 49.12]]));
    }

    public function testMatchesHeatmapLinesServiceKey(): void
    {
        // Stabilitäts-Anker: gleiche Geometrie -> gleicher Key über Aufrufe,
        // damit der M6-Rebuild (der diesen Helfer nutzt) und der M9-Lookup
        // garantiert dieselben Schlüssel erzeugen.
        $geom = [[8.601234, 49.121234], [8.611111, 49.131111]];
        $this->assertSame('100:49.12123,8.60123|49.13111,8.61111', EdgeKey::for(100, $geom));
    }
}
