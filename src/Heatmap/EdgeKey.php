<?php
declare(strict_types=1);

namespace App\Heatmap;

/**
 * Richtungsunabhängiger Schlüssel pro physischem Wegstück.
 *
 * Aus {@see HeatmapLinesService} extrahiert, damit der Precompute-Rebuild
 * (der `heatmap_edges` befüllt) und der spätere Lookup beim Surface-Check
 * (M9, Valhalla-Pfad) garantiert DENSELBEN Schlüssel berechnen. Würde sich
 * die Logik auseinanderentwickeln, fänden Lookups die Kanten nicht mehr.
 *
 * Der Schlüssel kombiniert die OSM `way_id` mit den (auf 5 Nachkommastellen
 * gerundeten und sortierten) Endpunkten der Kantengeometrie. Dadurch ist er
 * unabhängig von der Befahrungsrichtung.
 */
final class EdgeKey
{
    /**
     * @param list<array{0:float,1:float}> $geom Liste aus [lon, lat]-Paaren
     */
    public static function for(?int $wayId, array $geom): ?string
    {
        $c = count($geom);
        if ($c < 2) {
            return null;
        }
        $a = $geom[0];
        $b = $geom[$c - 1];
        $pa = sprintf('%.5f,%.5f', $a[1], $a[0]); // lat,lon
        $pb = sprintf('%.5f,%.5f', $b[1], $b[0]);
        $ends = [$pa, $pb];
        sort($ends);
        return ($wayId ?? 0) . ':' . $ends[0] . '|' . $ends[1];
    }
}
