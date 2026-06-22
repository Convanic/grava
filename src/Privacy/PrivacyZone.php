<?php
declare(strict_types=1);

namespace App\Privacy;

/**
 * Geofence-Zone zum Heimat-Schutz (§17 / PRIVACY_ZONE_BACKEND.md).
 *
 * Unveränderliches Wertobjekt: ein Mittelpunkt (lat/lon) plus Radius.
 * Kapselt die autoritative „liegt in der Zone?"-Entscheidung, damit
 * Ingestion, Routen-Serialisierung und Heatmap identisch entscheiden.
 *
 * Entfernungen via Haversine (Meter). Die Mittelpunkt-Koordinaten sind
 * hochsensibel und dürfen NIE an andere Nutzer ausgeliefert werden — dieses
 * Objekt wird ausschließlich server-seitig zum Filtern verwendet.
 */
final class PrivacyZone
{
    public const RADIUS_MIN_M = 200;
    public const RADIUS_MAX_M = 2000;
    public const RADIUS_DEFAULT_M = 500;

    private const EARTH_RADIUS_M = 6371000.0;

    public function __construct(
        public readonly float $lat,
        public readonly float $lon,
        public readonly int $radiusM,
    ) {}

    /** Klemmt einen Radius server-seitig auf den erlaubten Bereich. */
    public static function clampRadius(int $radiusM): int
    {
        return max(self::RADIUS_MIN_M, min(self::RADIUS_MAX_M, $radiusM));
    }

    /** Ein Punkt liegt in der Zone, wenn haversine(point, center) <= radius. */
    public function containsPoint(float $lat, float $lon): bool
    {
        return $this->haversineM($lat, $lon) <= (float)$this->radiusM;
    }

    /**
     * Konservativ: Eine Kante/ein Streckenzug „liegt in der Zone", wenn der
     * kürzeste Abstand des Zonen-Mittelpunkts zur Polylinie <= radius ist.
     * Das deckt Stützpunkte UND Durchfahrten (ohne Stützpunkt in der Zone) ab.
     *
     * @param list<array{0:float|int,1:float|int}> $lonLat Stützpunkte als [lon, lat]
     */
    public function intersectsPolyline(array $lonLat): bool
    {
        $n = count($lonLat);
        if ($n === 0) {
            return false;
        }
        // Lokale equirektanguläre Projektion um den Zonen-Mittelpunkt — für die
        // kleinen Distanzen (<= 2 km) mehr als genau genug. Mittelpunkt = (0,0).
        $mPerDegLat = 111320.0;
        $mPerDegLon = 111320.0 * cos(deg2rad($this->lat));
        $project = fn(array $c): array => [
            ((float)$c[0] - $this->lon) * $mPerDegLon,
            ((float)$c[1] - $this->lat) * $mPerDegLat,
        ];

        if ($n === 1) {
            [$x, $y] = $project($lonLat[0]);
            return sqrt($x * $x + $y * $y) <= (float)$this->radiusM;
        }

        $r = (float)$this->radiusM;
        for ($i = 0; $i < $n - 1; $i++) {
            [$ax, $ay] = $project($lonLat[$i]);
            [$bx, $by] = $project($lonLat[$i + 1]);
            if (self::pointToSegmentMeters(0.0, 0.0, $ax, $ay, $bx, $by) <= $r) {
                return true;
            }
        }
        return false;
    }

    private function haversineM(float $lat, float $lon): float
    {
        $dLat = deg2rad($lat - $this->lat);
        $dLon = deg2rad($lon - $this->lon);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($this->lat)) * cos(deg2rad($lat)) * sin($dLon / 2) ** 2;
        return self::EARTH_RADIUS_M * 2 * asin(min(1.0, sqrt($a)));
    }

    private static function pointToSegmentMeters(
        float $px, float $py, float $ax, float $ay, float $bx, float $by,
    ): float {
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $lenSq = $dx * $dx + $dy * $dy;
        if ($lenSq <= 0.0) {
            return sqrt(($px - $ax) ** 2 + ($py - $ay) ** 2);
        }
        $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $lenSq;
        $t = max(0.0, min(1.0, $t));
        $cx = $ax + $t * $dx;
        $cy = $ay + $t * $dy;
        return sqrt(($px - $cx) ** 2 + ($py - $cy) ** 2);
    }
}
