<?php
declare(strict_types=1);

namespace App\Game;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Wochen-Serie (Streak) — reine Berechnung ohne DB-Zugriff
 * (GAME_EVENTS_BACKEND.md Teil 2).
 *
 * Einheit = ISO-Woche (Montag-Start). Eine Woche zählt, wenn der Fahrer in ihr
 * ≥1 authentische Fahrt hatte (Quelle: distinct Montage der game_edge_pass).
 *
 * Gnade ("Streak-Schoner"): pro Kalendermonat dürfen bis zu
 * `gracePerMonth` ausgelassene Wochen übersprungen werden, ohne die Serie zu
 * brechen. 0 = Gnade aus. Eine übersprungene Woche zählt NICHT mit (nur Wochen
 * mit echter Fahrt erhöhen `streak_weeks`), bricht die Serie aber auch nicht.
 *
 * Die laufende Woche ist „offen", solange sie keine qualifizierende Fahrt hat:
 * sie zählt dann nicht und verbraucht KEINE Gnade (sie ist noch nicht vorbei).
 */
final class StreakCalculator
{
    /**
     * @param list<string> $weekMondays Distinct Montags-Daten (Y-m-d) der ISO-
     *        Wochen mit ≥1 qualifizierender Fahrt. Reihenfolge egal.
     * @return array{
     *   streak_weeks:int,
     *   streak_active_this_week:bool,
     *   longest_streak_weeks:int,
     *   streak_grace_remaining:int
     * }
     */
    public static function compute(array $weekMondays, DateTimeImmutable $now, int $gracePerMonth): array
    {
        $gracePerMonth = max(0, $gracePerMonth);

        // Set der qualifizierenden Wochen (Schlüssel = Montag Y-m-d).
        $ridden = [];
        foreach ($weekMondays as $m) {
            $ridden[$m] = true;
        }

        $thisMonday = self::mondayOf($now);
        $thisKey = $thisMonday->format('Y-m-d');
        $activeThisWeek = isset($ridden[$thisKey]);

        [$streak, $graceUsed] = self::walkCurrent($ridden, $thisMonday, $activeThisWeek, $gracePerMonth);
        $currentMonth = $thisMonday->format('Y-m');
        $graceRemaining = $gracePerMonth === 0
            ? 0
            : max(0, $gracePerMonth - ($graceUsed[$currentMonth] ?? 0));

        return [
            'streak_weeks'            => $streak,
            'streak_active_this_week' => $activeThisWeek,
            'longest_streak_weeks'    => self::longestStreak($ridden, $gracePerMonth),
            'streak_grace_remaining'  => $graceRemaining,
        ];
    }

    /**
     * Aktuelle Serie: rückwärts ab der laufenden Woche zählen. Lücken werden je
     * Kalendermonat bis `gracePerMonth` mal überbrückt, sonst Abbruch.
     *
     * Gnade wird erst „committed", wenn sie tatsächlich zu einer weiteren
     * Fahrt-Woche überbrückt — nachlaufende Lücken (die zu keiner früheren Fahrt
     * mehr führen) verbrauchen kein Budget und gehen nicht in `graceUsed` ein.
     *
     * @param array<string,bool> $ridden
     * @return array{0:int,1:array<string,int>} [streak, committed graceUsed je Monat]
     */
    private static function walkCurrent(
        array $ridden,
        DateTimeImmutable $thisMonday,
        bool $activeThisWeek,
        int $gracePerMonth,
    ): array {
        $streak = 0;
        $graceUsed = [];    // committed: Brücken, die zwei Fahrt-Wochen verbinden
        $pendingGrace = []; // tentativ: Lücken seit der letzten Fahrt-Woche

        // Laufende Woche: aktiv → zählt mit; offen → kein Abbruch, keine Gnade.
        if ($activeThisWeek) {
            $streak++;
        }
        $cursor = $thisMonday->modify('-7 days');

        // Sicherung gegen Endlosschleife: nie weiter als ~30 Jahre zurück.
        for ($i = 0; $i < 1600; $i++) {
            $key = $cursor->format('Y-m-d');
            if (isset($ridden[$key])) {
                $streak++;
                foreach ($pendingGrace as $m => $c) {
                    $graceUsed[$m] = ($graceUsed[$m] ?? 0) + $c;
                }
                $pendingGrace = [];
            } else {
                $month = $cursor->format('Y-m');
                $tentative = ($graceUsed[$month] ?? 0) + ($pendingGrace[$month] ?? 0);
                if ($tentative >= $gracePerMonth) {
                    break;
                }
                $pendingGrace[$month] = ($pendingGrace[$month] ?? 0) + 1;
            }
            $cursor = $cursor->modify('-7 days');
        }

        return [$streak, $graceUsed];
    }

    /**
     * Längste je erreichte Serie über die gesamte Historie (gleicher Regelsatz).
     * Ein Durchlauf von der frühesten zur spätesten qualifizierenden Woche;
     * Lücken werden je Kalendermonat bis `gracePerMonth` mal überbrückt.
     *
     * @param array<string,bool> $ridden
     */
    private static function longestStreak(array $ridden, int $gracePerMonth): int
    {
        if ($ridden === []) {
            return 0;
        }
        $keys = array_keys($ridden);
        sort($keys); // chronologisch (Y-m-d ist lexikografisch = chronologisch)

        $first = new DateTimeImmutable($keys[0], new DateTimeZone('UTC'));
        $last = new DateTimeImmutable($keys[count($keys) - 1], new DateTimeZone('UTC'));

        $longest = 0;
        $current = 0;
        $graceUsed = []; // [YYYY-MM => int] innerhalb der laufenden Serie

        $cursor = $first;
        for ($i = 0; $i < 1600; $i++) {
            $key = $cursor->format('Y-m-d');
            if (isset($ridden[$key])) {
                $current++;
                if ($current > $longest) {
                    $longest = $current;
                }
            } else {
                $month = $cursor->format('Y-m');
                $used = $graceUsed[$month] ?? 0;
                if ($used >= $gracePerMonth) {
                    // Serie reißt: zurücksetzen.
                    $current = 0;
                    $graceUsed = [];
                } else {
                    $graceUsed[$month] = $used + 1;
                }
            }
            if ($cursor >= $last) {
                break;
            }
            $cursor = $cursor->modify('+7 days');
        }

        return $longest;
    }

    /** Montag (00:00 UTC) der ISO-Woche von $dt. */
    private static function mondayOf(DateTimeImmutable $dt): DateTimeImmutable
    {
        $utc = $dt->setTimezone(new DateTimeZone('UTC'));
        // N: 1 (Mo) .. 7 (So). Auf den Montag dieser Woche zurückgehen.
        $offset = (int)$utc->format('N') - 1;
        return $utc->setTime(0, 0, 0)->modify("-{$offset} days");
    }
}
