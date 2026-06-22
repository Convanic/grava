<?php
declare(strict_types=1);

namespace Tests\Unit\Privacy;

use App\Privacy\PrivacyZone;
use PHPUnit\Framework\TestCase;

final class PrivacyZoneTest extends TestCase
{
    public function testClampRadius(): void
    {
        $this->assertSame(200, PrivacyZone::clampRadius(50));
        $this->assertSame(2000, PrivacyZone::clampRadius(9999));
        $this->assertSame(500, PrivacyZone::clampRadius(500));
    }

    public function testContainsPoint(): void
    {
        $zone = new PrivacyZone(48.20, 11.60, 500);
        // Mittelpunkt selbst.
        $this->assertTrue($zone->containsPoint(48.20, 11.60));
        // ~100 m nördlich (0.0009° lat ≈ 100 m).
        $this->assertTrue($zone->containsPoint(48.2009, 11.60));
        // ~1 km nördlich (0.009° lat ≈ 1000 m) → außerhalb 500 m.
        $this->assertFalse($zone->containsPoint(48.209, 11.60));
    }

    public function testIntersectsPolylineByVertex(): void
    {
        $zone = new PrivacyZone(48.20, 11.60, 500);
        // Linie mit einem Stützpunkt nahe dem Zentrum.
        $line = [[11.70, 48.30], [11.6005, 48.2005], [11.50, 48.10]];
        $this->assertTrue($zone->intersectsPolyline($line));
    }

    public function testIntersectsPolylinePassThroughWithoutVertexInside(): void
    {
        // Lange Kante mit beiden Stützpunkten außerhalb, die aber durch die
        // Zone führt (Durchfahrt) — konservativer Segment-Abstand greift.
        $zone = new PrivacyZone(48.20, 11.60, 500);
        $line = [[11.59, 48.20], [11.61, 48.20]]; // ost-west durch das Zentrum
        $this->assertTrue($zone->intersectsPolyline($line));
    }

    public function testDoesNotIntersectFarPolyline(): void
    {
        $zone = new PrivacyZone(48.20, 11.60, 500);
        $line = [[11.70, 48.30], [11.72, 48.32]];
        $this->assertFalse($zone->intersectsPolyline($line));
    }

    public function testEmptyPolyline(): void
    {
        $zone = new PrivacyZone(48.20, 11.60, 500);
        $this->assertFalse($zone->intersectsPolyline([]));
    }
}
