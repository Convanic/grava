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
     * Verkehrs-Faktor f_eff (RADAR_TRAFFIC_BACKEND.md §B3). Multipliziert
     * am Ende den Kantenwert. **Keine Daten → exakt 1.0** (neutral).
     *
     *   t     = pass_count / max(km_summiert, ε)        # Vorbeifahrten/km
     *   f     = clamp(1 + k·(t0 - t)/t0, f_min, f_max)
     *   f_eff = 1 + (f - 1)·n / (n + n_prior)            # Shrinkage Richtung 1.0
     *
     * km_summiert = (edge_length_m / 1000) · observations (Expositions-km).
     *
     * @param int   $passCount     map-gematchte Vorbeifahrten auf der Kante
     * @param int   $observations  distinct Fahrten mit Radar auf der Kante
     * @param float $edgeLengthM   Länge der Kante in Metern
     */
    public static function trafficFactor(
        int $passCount,
        int $observations,
        float $edgeLengthM,
        float $t0,
        float $k,
        float $fMin,
        float $fMax,
        int $nPrior,
    ): float {
        if ($observations <= 0) {
            return 1.0;
        }
        $eps = 1e-6;
        $kmSummed = max(($edgeLengthM / 1000.0) * $observations, $eps);
        $t  = $passCount / $kmSummed;
        $t0 = $t0 <= 0.0 ? $eps : $t0;
        $f  = 1.0 + $k * ($t0 - $t) / $t0;
        $f  = max($fMin, min($fMax, $f));
        return 1.0 + ($f - 1.0) * $observations / ($observations + max(0, $nPrior));
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
