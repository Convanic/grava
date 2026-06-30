<?php
declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\GameMath;
use PHPUnit\Framework\TestCase;

/**
 * Auswärts-Multiplikator (Konzept §20). Golden Numbers identisch zur
 * Referenz `GravelExplorer/backend/away_multiplier_reference.test.mjs`.
 * Defaults: away_max=2, near=30, far=150, linear.
 */
final class GameMathAwayTest extends TestCase
{
    private const MAX = 2.0;
    private const NEAR = 30.0;
    private const FAR = 150.0;

    private function away(float $d): float
    {
        return GameMath::awayMultiplier($d, self::MAX, self::NEAR, self::FAR, 'linear');
    }

    public function testGoldenNumbers(): void
    {
        $this->assertEqualsWithDelta(1.00, $this->away(0.0),   1e-9);
        $this->assertEqualsWithDelta(1.00, $this->away(30.0),  1e-9);
        $this->assertEqualsWithDelta(1.25, $this->away(60.0),  1e-9);
        $this->assertEqualsWithDelta(1.50, $this->away(90.0),  1e-9);  // Midpoint
        $this->assertEqualsWithDelta(1.75, $this->away(120.0), 1e-9);
        $this->assertEqualsWithDelta(2.00, $this->away(150.0), 1e-9);
        $this->assertEqualsWithDelta(2.00, $this->away(200.0), 1e-9);  // geklemmt
    }

    public function testWithinNearRadiusIsNeutral(): void
    {
        $this->assertSame(1.0, $this->away(0.0));
        $this->assertSame(1.0, $this->away(29.9));
    }

    public function testNullDistanceIsNeutral(): void
    {
        // keine etablierte Homebase → ×1.0 (§20.2)
        $this->assertSame(1.0, GameMath::awayMultiplier(null, self::MAX, self::NEAR, self::FAR));
    }

    public function testMonotonicNonDecreasing(): void
    {
        $prev = -1.0;
        for ($d = 0.0; $d <= 200.0; $d += 5.0) {
            $m = $this->away($d);
            $this->assertGreaterThanOrEqual($prev - 1e-12, $m);
            $prev = $m;
        }
    }

    public function testSigmoidCurveSameEndpointsGentlerMiddle(): void
    {
        $this->assertEqualsWithDelta(1.0, GameMath::awayMultiplier(30.0, self::MAX, self::NEAR, self::FAR, 'sigmoid'), 1e-9);
        $this->assertEqualsWithDelta(2.0, GameMath::awayMultiplier(150.0, self::MAX, self::NEAR, self::FAR, 'sigmoid'), 1e-9);
        // smoothstep(0.5)=0.5 → at midpoint identical to linear; check a quarter point is gentler
        $linQ = GameMath::awayMultiplier(60.0, self::MAX, self::NEAR, self::FAR, 'linear');   // t=0.25 → 1.25
        $sigQ = GameMath::awayMultiplier(60.0, self::MAX, self::NEAR, self::FAR, 'sigmoid');   // smoothstep(0.25) < 0.25
        $this->assertLessThan($linQ, $sigQ);
    }

    public function testCappedMultiplierStacking(): void
    {
        // rush 2.0 × away 2.0 = 4.0, am Deckel
        $this->assertSame(4.0, GameMath::cappedMultiplier(2.0, 2.0, 4.0, true));
        // Deckel enger → 3.0
        $this->assertSame(3.0, GameMath::cappedMultiplier(2.0, 2.0, 3.0, true));
        // away=1 (zuhause) → basis unverändert
        $this->assertSame(1.5, GameMath::cappedMultiplier(1.5, 1.0, 4.0, true));
    }

    public function testCappedMultiplierNonStackingUsesMax(): void
    {
        $this->assertSame(2.0, GameMath::cappedMultiplier(1.5, 2.0, 4.0, false)); // max(1.5,2.0)
        $this->assertSame(2.0, GameMath::cappedMultiplier(2.0, 1.25, 4.0, false)); // stärkere Basis bleibt
    }

    public function testHaversineKnownDistances(): void
    {
        // 1° Breite ≈ 111.19 km
        $this->assertEqualsWithDelta(111.19, GameMath::haversineKm(47.0, 8.0, 48.0, 8.0), 0.5);
        // Nulldistanz
        $this->assertSame(0.0, GameMath::haversineKm(47.0, 8.0, 47.0, 8.0));
    }
}
