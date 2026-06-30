<?php
declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\BadgeMath;
use PHPUnit\Framework\TestCase;

final class BadgeMathTest extends TestCase
{
    private const TIERS = [25, 250, 1500, 6000, 25000]; // Erschließer (§5.2)
    private const RANK_AP = [0, 100, 400, 1000, 2500, 5000, 10000, 20000, 40000, 80000];
    /** @var array<string,array{gold?:int,onyx?:int,allCoreGold?:bool}> */
    private const GATE = [
        6 => ['gold' => 1],
        7 => ['gold' => 2],
        8 => ['gold' => 3],
        9 => ['gold' => 4, 'onyx' => 1],
        10 => ['onyx' => 2, 'allCoreGold' => true],
    ];

    public function testTierForValueBelowBronzeIsMinusOne(): void
    {
        $this->assertSame(-1, BadgeMath::tierForValue(24, self::TIERS));
    }

    public function testTierForValueExactThresholds(): void
    {
        $this->assertSame(0, BadgeMath::tierForValue(25, self::TIERS));     // Bronze
        $this->assertSame(1, BadgeMath::tierForValue(250, self::TIERS));    // Silber
        $this->assertSame(2, BadgeMath::tierForValue(1500, self::TIERS));   // Gold
        $this->assertSame(3, BadgeMath::tierForValue(6000, self::TIERS));   // Platin
        $this->assertSame(4, BadgeMath::tierForValue(25000, self::TIERS));  // Onyx
    }

    public function testTierForValueBetweenAndAbove(): void
    {
        $this->assertSame(2, BadgeMath::tierForValue(5999, self::TIERS));   // zwischen Gold und Platin
        $this->assertSame(4, BadgeMath::tierForValue(999999, self::TIERS)); // über Onyx → bleibt Onyx
        // Realwert des Nutzers: 7.615 pionierte Kanten → Platin (nicht mehr)
        $this->assertSame(3, BadgeMath::tierForValue(7615, self::TIERS));
    }

    public function testRankForAp(): void
    {
        $this->assertSame(1, BadgeMath::rankForAp(0, self::RANK_AP));
        $this->assertSame(1, BadgeMath::rankForAp(99, self::RANK_AP));
        $this->assertSame(2, BadgeMath::rankForAp(100, self::RANK_AP));
        $this->assertSame(6, BadgeMath::rankForAp(8421, self::RANK_AP)); // heavy early user
        $this->assertSame(10, BadgeMath::rankForAp(999999, self::RANK_AP));
    }

    public function testFinalRankWithoutGateBelowSix(): void
    {
        // Rang 1–5 sind reine AP-Ränge → Gate ändert nichts.
        $this->assertSame(5, BadgeMath::finalRank(5, self::GATE, 0, 0, 0, 4));
    }

    public function testFinalRankGatedDownWhenBadgesMissing(): void
    {
        // AP reicht für Rang 8, aber keine Gold-Abzeichen → fällt auf 5 zurück
        // (R6 braucht 1 Gold).
        $this->assertSame(5, BadgeMath::finalRank(8, self::GATE, 0, 0, 0, 4));
    }

    public function testFinalRankGatePartiallySatisfied(): void
    {
        // AP für Rang 8, 2 Gold vorhanden → R6(1) und R7(2) erfüllt, R8(3) nicht → 7.
        $this->assertSame(7, BadgeMath::finalRank(8, self::GATE, 2, 0, 1, 4));
    }

    public function testFinalRankTenNeedsAllCoreGoldPlusOnyx(): void
    {
        // AP für 10, 4 Gold (alle Kern) + 2 Onyx, allCoreGold erfüllt → 10.
        $this->assertSame(10, BadgeMath::finalRank(10, self::GATE, 4, 2, 4, 4));
        // Onyx fehlt (nur 1) → fällt auf 9 (R9 = 4 gold + 1 onyx erfüllt).
        $this->assertSame(9, BadgeMath::finalRank(10, self::GATE, 4, 1, 4, 4));
        // allCoreGold nicht erfüllt (nur 3 Kern-Gold) und nur 4 Gold gesamt →
        // R10 scheitert (allCoreGold), R9 ok (4 gold,1 onyx) wenn 2 onyx ≥1.
        $this->assertSame(9, BadgeMath::finalRank(10, self::GATE, 4, 2, 3, 4));
    }
}
