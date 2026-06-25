<?php
declare(strict_types=1);

namespace App\Game;

/** Reine Rekord-Mathematik (Spec §3.1, §9.1). */
final class EdgeRecordMath
{
    /**
     * @return array{duration_ms:int,avg_speed_kmh:float}|null
     */
    public static function fromDurationSeconds(float $lengthM, float $durationS): ?array
    {
        if ($durationS <= 0.0 || $lengthM <= 0.0) {
            return null;
        }
        $durationMs = (int)round($durationS * 1000.0);
        $avgSpeedKmh = ($lengthM / $durationS) * 3.6;
        return [
            'duration_ms'   => $durationMs,
            'avg_speed_kmh' => round($avgSpeedKmh, 2),
        ];
    }
}
