<?php
declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\GameMath;
use PHPUnit\Framework\TestCase;

/**
 * Verkehrs-Faktor (RADAR_TRAFFIC_BACKEND.md §B3). Defaults: t0=5, k=0.5,
 * f_min=0.7, f_max=1.3, n_prior=3.
 */
final class GameMathTrafficTest extends TestCase
{
    private function factor(int $passes, int $obs, float $lenM = 120.0): float
    {
        return GameMath::trafficFactor($passes, $obs, $lenM, 5.0, 0.5, 0.7, 1.3, 3);
    }

    public function testNoObservationsIsNeutral(): void
    {
        $this->assertSame(1.0, $this->factor(0, 0));
        $this->assertSame(1.0, $this->factor(7, 0));
    }

    public function testHeavyTrafficClampsToFloorWithShrinkage(): void
    {
        // 10 Pässe auf 120 m, 1 Beobachtung → t≈83/km ⇒ f=clamp 0.7,
        // f_eff = 1 + (0.7-1)*1/(1+3) = 0.925.
        $this->assertEqualsWithDelta(0.925, $this->factor(10, 1), 1e-9);
    }

    public function testQuietRideRaisesFactorTowardCeiling(): void
    {
        // 0 Pässe, 1 Beobachtung → t=0 ⇒ f=clamp 1.3,
        // f_eff = 1 + (1.3-1)*1/4 = 1.075.
        $this->assertEqualsWithDelta(1.075, $this->factor(0, 1), 1e-9);
    }

    public function testShrinkageWeakensWithFewObservations(): void
    {
        // Mehr Beobachtungen → näher am rohen f (weniger Shrinkage).
        $few  = $this->factor(0, 1);   // 1.075
        $many = $this->factor(0, 10);  // 1 + 0.3*10/13 ≈ 1.2308
        $this->assertGreaterThan($few, $many);
        $this->assertEqualsWithDelta(1.0 + 0.3 * 10 / 13, $many, 1e-9);
    }

    public function testNeutralTrafficNearT0KeepsFactorNearOne(): void
    {
        // t ≈ t0 = 5/km. Bei Länge 1000 m und 1 Beobachtung: 5 Pässe = 5/km.
        $f = GameMath::trafficFactor(5, 1, 1000.0, 5.0, 0.5, 0.7, 1.3, 3);
        $this->assertEqualsWithDelta(1.0, $f, 1e-9);
    }
}
