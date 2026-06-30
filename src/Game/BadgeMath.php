<?php
declare(strict_types=1);

namespace App\Game;

/**
 * Reine Progressions-Logik für Ränge & Abzeichen (RankBadges_Concept.md).
 * Keine DB, keine Zeit — vollständig unit-testbar. Die DB-/Persistenz-Seite
 * (Messwerte sammeln, erreichte Stufen schreiben) lebt in
 * {@see PlayerProgressionService}.
 *
 * Stufen-Indizes: 0=Bronze, 1=Silber, 2=Gold, 3=Platin, 4=Onyx.
 */
final class BadgeMath
{
    public const TIER_GOLD = 2;
    public const TIER_ONYX = 4;

    /**
     * Höchste erreichte Stufe für einen Messwert.
     * @param list<int|float> $tiers aufsteigende Schwellen [bronze..onyx]
     * @return int -1 = noch keine Stufe, sonst 0..len-1
     */
    public static function tierForValue(float $value, array $tiers): int
    {
        $tier = -1;
        foreach (array_values($tiers) as $i => $threshold) {
            if ($value >= (float)$threshold) {
                $tier = $i;
            } else {
                break;
            }
        }
        return $tier;
    }

    /**
     * Rang allein aus AP (vor dem Abzeichen-Gate, §4): höchster Rang, dessen
     * AP-Schwelle erreicht ist. Rang 1 = erste Schwelle (i. d. R. 0).
     * @param list<int|float> $rankAp aufsteigende AP-Schwellen, Index 0 = Rang 1
     */
    public static function rankForAp(int $ap, array $rankAp): int
    {
        $rank = 1;
        foreach (array_values($rankAp) as $i => $threshold) {
            if ($ap >= (int)$threshold) {
                $rank = $i + 1;
            } else {
                break;
            }
        }
        return $rank;
    }

    /**
     * Endgültiger Rang nach dem Abzeichen-Gate (§13.2): ab Rang 6 verlangt der
     * Aufstieg zusätzlich eine Breite an Abzeichen-Stufen. Wir senken den
     * AP-Rang so weit, bis das Gate des Ziel-Rangs erfüllt ist.
     *
     * @param array<int|string,array{gold?:int,onyx?:int,allCoreGold?:bool}> $gate
     *        Regeln je Rang (Schlüssel = Rang-Nummer). Ränge ohne Eintrag haben
     *        kein Gate (reine AP-Ränge, i. d. R. 1–5).
     */
    public static function finalRank(
        int $apRank,
        array $gate,
        int $goldCount,
        int $onyxCount,
        int $coreGoldCount,
        int $coreFamilyCount,
    ): int {
        $rank = $apRank;
        while ($rank > 1) {
            $rule = $gate[$rank] ?? $gate[(string)$rank] ?? null;
            if ($rule === null) {
                break; // kein Gate für diesen Rang → AP genügt
            }
            if (self::gateSatisfied($rule, $goldCount, $onyxCount, $coreGoldCount, $coreFamilyCount)) {
                break;
            }
            $rank--;
        }
        return $rank;
    }

    /**
     * @param array{gold?:int,onyx?:int,allCoreGold?:bool} $rule
     */
    private static function gateSatisfied(
        array $rule,
        int $goldCount,
        int $onyxCount,
        int $coreGoldCount,
        int $coreFamilyCount,
    ): bool {
        if ($goldCount < (int)($rule['gold'] ?? 0)) {
            return false;
        }
        if ($onyxCount < (int)($rule['onyx'] ?? 0)) {
            return false;
        }
        if (!empty($rule['allCoreGold']) && $coreGoldCount < $coreFamilyCount) {
            return false;
        }
        return true;
    }
}
