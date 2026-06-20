<?php
declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\GameMath;
use PHPUnit\Framework\TestCase;

final class GameMathTest extends TestCase
{
    // §10.1 Pionier-Formel
    public function testPioneerGoldenNumbers(): void
    {
        $this->assertEqualsWithDelta(100.0, GameMath::pioneer(1, 100.0, 12.0, 4.0), 0.1);
        $this->assertEqualsWithDelta(67.5,  GameMath::pioneer(10, 100.0, 12.0, 4.0), 0.1);
        $this->assertEqualsWithDelta(50.0,  GameMath::pioneer(12, 100.0, 12.0, 4.0), 0.1);
        $this->assertEqualsWithDelta(11.5,  GameMath::pioneer(20, 100.0, 12.0, 4.0), 0.1);
        $this->assertEqualsWithDelta(2.5,   GameMath::pioneer(30, 100.0, 12.0, 4.0), 0.1);
    }

    public function testPioneerPlateauBelowTen(): void
    {
        $this->assertGreaterThan(67.0, GameMath::pioneer(10, 100.0, 12.0, 4.0));
        $this->assertGreaterThan(95.0, GameMath::pioneer(5, 100.0, 12.0, 4.0));
    }

    // §10.2 Praesenz-Verfall (linear, window=90)
    public function testPresenceWeightLinearDecay(): void
    {
        $this->assertSame(1.0, GameMath::presenceWeight(0.0, 90));
        $this->assertSame(0.5, GameMath::presenceWeight(45.0, 90));
        $this->assertSame(0.0, GameMath::presenceWeight(90.0, 90));
        $this->assertSame(0.0, GameMath::presenceWeight(120.0, 90));
    }

    public function testPresenceSumOverThreePasses(): void
    {
        $sum = GameMath::presenceWeight(0.0, 90)
             + GameMath::presenceWeight(45.0, 90)
             + GameMath::presenceWeight(90.0, 90);
        $this->assertSame(1.5, $sum);
    }

    // §10.3 Wert-Verknüpfung
    public function testValueAtFirstRiderIsPioneer(): void
    {
        $pioneer = GameMath::pioneer(1, 100.0, 12.0, 4.0);
        $popularity = GameMath::popularity(1, 30.0);
        $value = GameMath::combineValue($pioneer, $popularity, 0.0);
        $this->assertEqualsWithDelta($pioneer, $value, 0.1);
        $this->assertGreaterThanOrEqual(max($pioneer, $popularity), $value);
    }

    public function testValueAtManyRidersIsPopularity(): void
    {
        $pioneer = GameMath::pioneer(30, 100.0, 12.0, 4.0);
        $popularity = GameMath::popularity(25, 30.0);
        $value = GameMath::combineValue($pioneer, $popularity, 0.0);
        $this->assertEqualsWithDelta($popularity, $value, 0.1);
        $this->assertGreaterThanOrEqual(max($pioneer, $popularity), $value);
    }

    public function testCurationAddsOnTop(): void
    {
        $value = GameMath::combineValue(50.0, 30.0, 7.0);
        $this->assertSame(57.0, $value);
    }
}
