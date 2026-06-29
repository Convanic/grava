<?php
declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\StreakCalculator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Akzeptanzkriterien der Wochen-Serie (GAME_EVENTS_BACKEND.md Teil 2 §68).
 *
 * Fester Anker: now = Mi, 2026-06-24. Montag der laufenden ISO-Woche =
 * 2026-06-22. Wochen-Montage rückwärts: 06-22, 06-15, 06-08, 06-01 (alle Juni),
 * 05-25, 05-18 (Mai).
 */
final class StreakCalculatorTest extends TestCase
{
    private const NOW = '2026-06-24T12:00:00Z';

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
    }

    /** AC1: ohne Fahrten = 0, Serie nicht aktiv. */
    public function testNoRidesIsZero(): void
    {
        $r = StreakCalculator::compute([], $this->now(), 1);
        $this->assertSame(0, $r['streak_weeks']);
        $this->assertFalse($r['streak_active_this_week']);
        $this->assertSame(0, $r['longest_streak_weeks']);
    }

    /** AC2: vier aufeinanderfolgende ISO-Wochen mit Fahrt → streak_weeks = 4. */
    public function testFourConsecutiveWeeks(): void
    {
        $weeks = ['2026-06-22', '2026-06-15', '2026-06-08', '2026-06-01'];
        $r = StreakCalculator::compute($weeks, $this->now(), 1);
        $this->assertSame(4, $r['streak_weeks']);
        $this->assertTrue($r['streak_active_this_week']);
        $this->assertSame(4, $r['longest_streak_weeks']);
    }

    /** AC3: eine ausgelassene Woche (erste Lücke im Monat, Gnade ≥ 1) bricht nicht ab. */
    public function testSingleGapBridgedByGrace(): void
    {
        // Lücke bei 06-08 (Juni). Mit Gnade=1 überbrückt; die Serie reicht über
        // die Lücke hinweg bis 06-01.
        $weeks = ['2026-06-22', '2026-06-15', '2026-06-01'];
        $r = StreakCalculator::compute($weeks, $this->now(), 1);
        $this->assertSame(3, $r['streak_weeks'], 'Eine Lücke bricht nicht ab.');
        $this->assertTrue($r['streak_active_this_week']);
    }

    /** AC4a: zweite Lücke im selben Monat setzt die Serie zurück. */
    public function testSecondGapSameMonthBreaks(): void
    {
        // Lücken bei 06-15 UND 06-08 (beide Juni). Mit Gnade=1 wird die erste
        // überbrückt, die zweite bricht ab → nur die laufende Woche zählt.
        $weeks = ['2026-06-22', '2026-06-01'];
        $r = StreakCalculator::compute($weeks, $this->now(), 1);
        $this->assertSame(1, $r['streak_weeks']);
    }

    /** AC4b: Gnade = 0 → jede Lücke bricht sofort ab. */
    public function testGraceZeroBreaksOnFirstGap(): void
    {
        // Lücke bei 06-15; mit Gnade=0 bricht es dort → nur die laufende Woche.
        $weeks = ['2026-06-22', '2026-06-08'];
        $r = StreakCalculator::compute($weeks, $this->now(), 0);
        $this->assertSame(1, $r['streak_weeks']);
        $this->assertSame(0, $r['streak_grace_remaining']);
    }

    /** AC6: streak_active_this_week spiegelt, ob die laufende Woche qualifiziert ist. */
    public function testActiveThisWeekFalseWhenOnlyLastWeek(): void
    {
        // Nur Vorwoche (06-15) gefahren, laufende Woche (06-22) noch offen.
        $weeks = ['2026-06-15'];
        $r = StreakCalculator::compute($weeks, $this->now(), 1);
        $this->assertFalse($r['streak_active_this_week'], 'Laufende Woche nicht gefahren.');
        $this->assertSame(1, $r['streak_weeks'], 'Vorwoche zählt; Serie ist offen, nicht gebrochen.');
    }

    /** Offene laufende Woche verbraucht KEINE Gnade. */
    public function testOpenCurrentWeekDoesNotConsumeGrace(): void
    {
        // Laufende Woche (06-22) offen, davor 4 Folgewochen.
        $weeks = ['2026-06-15', '2026-06-08', '2026-06-01', '2026-05-25'];
        $r = StreakCalculator::compute($weeks, $this->now(), 1);
        $this->assertFalse($r['streak_active_this_week']);
        $this->assertSame(4, $r['streak_weeks']);
    }

    /** Gnade-Budget ist pro Kalendermonat: je eine Lücke in Mai und Juni überbrückt. */
    public function testGracePerMonthIsSeparate(): void
    {
        // Gefahren: 06-22, 06-15, (Lücke 06-08 Juni), 06-01, (Lücke 05-25 Mai), 05-18.
        $weeks = ['2026-06-22', '2026-06-15', '2026-06-01', '2026-05-18'];
        $r = StreakCalculator::compute($weeks, $this->now(), 1);
        $this->assertSame(4, $r['streak_weeks'], 'Je eine Lücke pro Monat wird überbrückt.');
    }

    /** streak_grace_remaining: verbleibende Schoner im aktuellen Kalendermonat. */
    public function testGraceRemainingCurrentMonth(): void
    {
        // Ohne Lücke im Juni → volles Budget übrig.
        $full = StreakCalculator::compute(['2026-06-22', '2026-06-15'], $this->now(), 1);
        $this->assertSame(1, $full['streak_grace_remaining']);

        // Mit einer überbrückten Juni-Lücke (06-08) → 0 übrig.
        $used = StreakCalculator::compute(['2026-06-22', '2026-06-15', '2026-06-01'], $this->now(), 1);
        $this->assertSame(0, $used['streak_grace_remaining']);
    }

    /** longest_streak_weeks: längste je erreichte Serie, auch in der Vergangenheit. */
    public function testLongestStreakAcrossHistory(): void
    {
        // Alte Serie von 5 Wochen, dann lange Pause, dann 2 aktuelle Wochen.
        $weeks = [
            '2026-01-05', '2026-01-12', '2026-01-19', '2026-01-26', '2026-02-02', // 5er-Serie
            '2026-06-22', '2026-06-15', // aktuelle 2er-Serie
        ];
        $r = StreakCalculator::compute($weeks, $this->now(), 1);
        $this->assertSame(2, $r['streak_weeks']);
        $this->assertSame(5, $r['longest_streak_weeks']);
    }
}
