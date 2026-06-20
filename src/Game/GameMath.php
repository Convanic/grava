<?php
declare(strict_types=1);

namespace App\Game;

/**
 * Reine, seiteneffektfreie Spiel-Mathematik (Spec §5). Alle Methoden
 * statisch und ohne I/O → direkt Unit-testbar (Golden Numbers §10.1–10.3).
 */
final class GameMath
{
    /**
     * Pionier-Hill-Funktion: P0 / (1 + (n/k)^s).
     * Plateau fuer kleine n, Wendepunkt bei n=k (dort genau P0/2).
     */
    public static function pioneer(int $n, float $p0, float $k, float $s): float
    {
        if ($n <= 0 || $k <= 0.0) {
            return $p0;
        }
        return $p0 / (1.0 + ($n / $k) ** $s);
    }

    /** Beliebtheit: c * ln(1 + n90). */
    public static function popularity(int $n90, float $c): float
    {
        if ($n90 <= 0) {
            return 0.0;
        }
        return $c * log(1.0 + $n90);
    }

    /**
     * Praesenz-Gewicht eines Passes: max(0, 1 - age/window) (linear).
     * @param float $ageDays Alter des Passes in Tagen (>=0)
     */
    public static function presenceWeight(float $ageDays, int $windowDays): float
    {
        if ($windowDays <= 0) {
            return 0.0;
        }
        return max(0.0, 1.0 - $ageDays / $windowDays);
    }

    /** value = max(pioneer, popularity) + curation. */
    public static function combineValue(float $pioneer, float $popularity, float $curation): float
    {
        return max($pioneer, $popularity) + $curation;
    }

    /**
     * Besitzer-Entscheidung mit Hysterese (Spec §5.2).
     * Gibt die claimant_id des neuen Besitzers zurueck.
     *
     * @param int|null $currentOwnerId aktueller Besitzer (null = niemand)
     * @param float    $currentPresence Praesenz des aktuellen Besitzers
     * @param int      $challengerId    staerkster Herausforderer (argmax Praesenz)
     * @param float    $challengerPresence
     */
    public static function decideOwner(
        ?int $currentOwnerId,
        float $currentPresence,
        int $challengerId,
        float $challengerPresence,
        float $hysteresisFactor,
    ): int {
        if ($currentOwnerId === null) {
            return $challengerId;
        }
        if ($challengerId === $currentOwnerId) {
            return $currentOwnerId;
        }
        if ($challengerPresence > $currentPresence * $hysteresisFactor) {
            return $challengerId;
        }
        return $currentOwnerId;
    }
}
