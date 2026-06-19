<?php
declare(strict_types=1);

namespace App\Routes;

/**
 * Ordnet Wegpunkt-Hinweise einer Position **entlang der Strecke** zu.
 *
 * Für jeden Hinweis wird der nächstgelegene Trackpunkt gesucht und dessen
 * kumulierte Distanz vom Start als `distance_m` (Meter) an den Hinweis
 * gehängt. Das ist bewusst simpel (nächster Punkt statt perpendikulärer
 * Projektion auf das Segment) — für eine Listen-/Marker-Anzeige genau genug
 * und robust gegen GPS-Jitter. Distanzen per Haversine, konsistent mit
 * {@see RouteInsights}.
 *
 * Die zurückgegebene Liste ist nach `distance_m` aufsteigend sortiert, sodass
 * die Hinweise in Fahrt-Reihenfolge erscheinen.
 */
final class RouteHintLocator
{
    private const EARTH_RADIUS_M = 6_371_000.0;

    /**
     * @param list<ParsedPoint>          $points Trackpunkte in Fahrt-Reihenfolge
     * @param list<array<string,mixed>>  $hints  Hinweise in Public-Form (lat/lon)
     * @return list<array<string,mixed>>         Hinweise inkl. `distance_m`, sortiert
     */
    public static function withDistances(array $points, array $hints): array
    {
        if ($hints === []) {
            return [];
        }
        if (count($points) < 2) {
            // Ohne Geometrie keine km — Feld trotzdem (null) setzen, damit das
            // Ausgabe-Schema stabil bleibt.
            return array_map(static fn(array $h): array => $h + ['distance_m' => null], $hints);
        }

        // Kumulierte Distanz je Trackpunkt vorberechnen.
        $cum  = [];
        $acc  = 0.0;
        $prev = null;
        foreach ($points as $i => $p) {
            if ($prev !== null) {
                $acc += self::haversine($prev->lat, $prev->lon, $p->lat, $p->lon);
            }
            $cum[$i] = $acc;
            $prev = $p;
        }

        foreach ($hints as &$hint) {
            $hlat = (float)($hint['lat'] ?? 0.0);
            $hlon = (float)($hint['lon'] ?? 0.0);
            $bestDist = null;
            $bestIdx  = 0;
            foreach ($points as $i => $p) {
                $d = self::haversine($hlat, $hlon, $p->lat, $p->lon);
                if ($bestDist === null || $d < $bestDist) {
                    $bestDist = $d;
                    $bestIdx  = $i;
                }
            }
            $hint['distance_m'] = (int)round($cum[$bestIdx]);
        }
        unset($hint);

        usort(
            $hints,
            static fn(array $a, array $b): int =>
                ($a['distance_m'] ?? PHP_INT_MAX) <=> ($b['distance_m'] ?? PHP_INT_MAX),
        );

        return $hints;
    }

    private static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return self::EARTH_RADIUS_M * 2 * asin(min(1.0, sqrt($a)));
    }
}
