<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Serverseitige Spiegelung der Client-LOD-Logik aus
 * `GravelExplorer/Views/MapLOD.swift` (Bucket-Mittelung für Tracks,
 * Gitter-Aggregation für Heatmaps, adaptive Gitterweite). Ziel: identische
 * Optik bei kleineren Payloads — der Server kann optional schon ausgedünnte
 * Detailstufen liefern, ohne dass der Client sichtbar anders rendert.
 *
 * Alle Methoden sind rein und ohne Seiteneffekte, damit sie 1:1 unit-testbar
 * sind und sowohl im Request-Pfad als auch im Precompute genutzt werden können.
 * Antimeridian-Wrap wird (wie im Client) ignoriert — die App ist regional.
 */
final class MapLod
{
    /** Standard-Obergrenze der Punkte pro Track (deckungsgleich Swift `defaultCap`). */
    public const DEFAULT_CAP = 2000;

    /**
     * Parst eine BBox im Format "minLon,minLat,maxLon,maxLat" zu vier Floats.
     * Liefert `null` bei ungültiger Eingabe (falsche Feldzahl, nicht-numerisch,
     * Koordinaten außerhalb des gültigen Bereichs oder min > max).
     *
     * @return array{0:float,1:float,2:float,3:float}|null  [minLon,minLat,maxLon,maxLat]
     */
    public static function parseBbox(string $raw): ?array
    {
        $parts = array_map('trim', explode(',', $raw));
        if (count($parts) !== 4) {
            return null;
        }
        foreach ($parts as $p) {
            if (!is_numeric($p)) {
                return null;
            }
        }
        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $parts);
        if ($minLat < -90 || $maxLat > 90 || $minLon < -180 || $maxLon > 180) {
            return null;
        }
        if ($minLat > $maxLat || $minLon > $maxLon) {
            return null;
        }
        return [$minLon, $minLat, $maxLon, $maxLat];
    }

    /**
     * Baut aus den Query-Parametern (`bbox`, `max_points`) eine LOD-Beschreibung
     * für {@see \App\Routes\RouteGeoJson::toFeatureCollection()}. Liefert `null`,
     * wenn weder eine gültige BBox noch ein gültiges `max_points` anliegt — der
     * Aufrufer fällt dann auf volle Auflösung zurück (rückwärtskompatibel).
     *
     * @param array<string,mixed> $query
     * @return array{bbox:array{0:float,1:float,2:float,3:float}|null,max_points:int|null}|null
     */
    public static function lodFromQuery(array $query): ?array
    {
        $bbox = null;
        $raw = $query['bbox'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $bbox = self::parseBbox($raw);
        }

        $maxPoints = null;
        $mp = $query['max_points'] ?? null;
        if (is_scalar($mp) && (string)$mp !== '' && is_numeric((string)$mp)) {
            $maxPoints = max(1, (int)$mp);
        }

        if ($bbox === null && $maxPoints === null) {
            return null;
        }
        return ['bbox' => $bbox, 'max_points' => $maxPoints];
    }

    /**
     * Liest den optionalen `grid`-Parameter (Gitterweite in Grad) für die
     * Heatmap-Aggregation. Liefert `null` bei fehlendem/unplausiblem Wert —
     * der Aufrufer bleibt dann bei der Basisauflösung (rückwärtskompatibel).
     *
     * @param array<string,mixed> $query
     */
    public static function gridFromQuery(array $query): ?float
    {
        $g = $query['grid'] ?? null;
        if (!is_scalar($g) || (string)$g === '' || !is_numeric((string)$g)) {
            return null;
        }
        $grid = (float)$g;
        if ($grid <= 0 || $grid > 5.0) {
            return null;
        }
        return $grid;
    }

    /**
     * Vereinfacht eine Trackpunkt-Folge für die Anzeige — Spiegel von
     * `MapLOD.simplify`:
     *
     *  1. Ist eine BBox gesetzt, wird auf die *zusammenhängende* Index-Spanne
     *     geklippt, die das Rechteck berührt (je ein Punkt über den Rand hinaus,
     *     damit die Linie verbunden bleibt, auch wenn der Track aus dem Bild
     *     herausläuft und zurückkommt).
     *  2. Die Spanne wird per Bucket-Mittelung auf höchstens `cap` Punkte
     *     reduziert. Eine Spanne, die bereits ≤ `cap` ist, bleibt unverändert
     *     (Hineinzoomen stellt volle Auflösung wieder her).
     *
     * Der Score wird pro Bucket als gerundeter Mittelwert der vorhandenen
     * (nicht-`null`) Scores übernommen; enthält ein Bucket keinen Score, ist er
     * `null`.
     *
     * @param list<array{lon:float,lat:float,score:int|null}> $points
     * @param array{0:float,1:float,2:float,3:float}|null     $bbox minLon,minLat,maxLon,maxLat
     * @param int|null                                        $cap  null = keine Ausdünnung (nur Klippen)
     * @return array{points:list<array{lon:float,lat:float,score:int|null}>,simplified:bool,source_points:int,returned_points:int}
     */
    public static function simplifyTrack(array $points, ?array $bbox, ?int $cap): array
    {
        $n = count($points);
        if ($n === 0) {
            return ['points' => [], 'simplified' => false, 'source_points' => 0, 'returned_points' => 0];
        }
        if ($cap !== null && $cap < 1) {
            $cap = 1;
        }

        // 1. Zusammenhängende sichtbare Spanne bestimmen.
        $lo = 0;
        $hi = $n - 1;
        if ($bbox !== null) {
            [$minLon, $minLat, $maxLon, $maxLat] = $bbox;
            $first = null;
            $last  = null;
            for ($i = 0; $i < $n; $i++) {
                $lon = $points[$i]['lon'];
                $lat = $points[$i]['lat'];
                if ($lat >= $minLat && $lat <= $maxLat && $lon >= $minLon && $lon <= $maxLon) {
                    if ($first === null) {
                        $first = $i;
                    }
                    $last = $i;
                }
            }
            if ($first === null || $last === null) {
                // Nichts im Ausschnitt.
                return ['points' => [], 'simplified' => true, 'source_points' => $n, 'returned_points' => 0];
            }
            $lo = max(0, $first - 1);          // je einen Punkt über den Rand hinaus
            $hi = min($n - 1, $last + 1);
        }

        $count = $hi - $lo + 1;

        // 2a. Spanne passt bereits → unverändert zurück (nur ggf. geklippt).
        if ($cap === null || $count <= $cap) {
            $out = array_values(array_slice($points, $lo, $count));
            return [
                'points'          => $out,
                'simplified'      => count($out) !== $n,
                'source_points'   => $n,
                'returned_points' => count($out),
            ];
        }

        // 2b. Bucket-Mittelung auf ≤ cap Punkte.
        $bucket = (int)ceil($count / $cap);
        $out = [];
        $i = $lo;
        while ($i <= $hi) {
            $end = min($i + $bucket - 1, $hi);
            $k = $end - $i + 1;
            $latSum = 0.0;
            $lonSum = 0.0;
            $scoreSum = 0;
            $scoreCount = 0;
            for ($j = $i; $j <= $end; $j++) {
                $latSum += $points[$j]['lat'];
                $lonSum += $points[$j]['lon'];
                $s = $points[$j]['score'] ?? null;
                if ($s !== null) {
                    $scoreSum += $s;
                    $scoreCount++;
                }
            }
            $out[] = [
                'lon'   => $lonSum / $k,
                'lat'   => $latSum / $k,
                'score' => $scoreCount > 0 ? (int)round($scoreSum / $scoreCount) : null,
            ];
            $i = $end + 1;
        }

        return [
            'points'          => $out,
            'simplified'      => true,
            'source_points'   => $n,
            'returned_points' => count($out),
        ];
    }

    /**
     * Aggregiert gewichtete Punkte in ein gröberes Gitter der Zellweite `grid`
     * (Grad) und summiert die Gewichte je Zelle — Spiegel von
     * `MapLOD.clusterHeat`. Der Zellmittelpunkt liegt (wie im Client) bei
     * `(floor(coord/grid) + 0.5) * grid`.
     *
     * @param list<array{lon:float,lat:float,weight:int}> $points
     * @return array{cells:list<array{lon:float,lat:float,weight:int}>,max_weight:int}
     */
    public static function clusterHeat(array $points, float $grid): array
    {
        if ($grid <= 0 || $points === []) {
            $max = 0;
            foreach ($points as $p) {
                $max = max($max, (int)$p['weight']);
            }
            return ['cells' => array_values($points), 'max_weight' => $max];
        }

        /** @var array<string,array{x:int,y:int,weight:int}> $sum */
        $sum = [];
        foreach ($points as $p) {
            $x = (int)floor($p['lon'] / $grid);
            $y = (int)floor($p['lat'] / $grid);
            $key = $x . ':' . $y;
            if (!isset($sum[$key])) {
                $sum[$key] = ['x' => $x, 'y' => $y, 'weight' => 0];
            }
            $sum[$key]['weight'] += (int)$p['weight'];
        }

        $cells = [];
        $maxW = 0;
        foreach ($sum as $cell) {
            $w = $cell['weight'];
            if ($w > $maxW) {
                $maxW = $w;
            }
            $cells[] = [
                'lon'    => ($cell['x'] + 0.5) * $grid,
                'lat'    => ($cell['y'] + 0.5) * $grid,
                'weight' => $w,
            ];
        }
        return ['cells' => $cells, 'max_weight' => $maxW];
    }

    /**
     * Gitterweite (Grad), die über die Spanne `spanDeg` ungefähr `targetCells`
     * Zellen ergibt, nie feiner als `minGrid`, gesnappt auf die 1-2-5-Folge —
     * Spiegel von `MapLOD.adaptiveGrid`. `spanDeg === null` → `minGrid`.
     */
    public static function adaptiveGrid(?float $spanDeg, float $minGrid, float $targetCells = 40.0): float
    {
        if ($spanDeg === null || $targetCells <= 0) {
            return $minGrid;
        }
        $raw = max($minGrid, $spanDeg / $targetCells);
        return self::snap125($raw);
    }

    /** Rundet auf den nächsten Wert der 1·10ⁿ / 2·10ⁿ / 5·10ⁿ-Folge auf. */
    public static function snap125(float $value): float
    {
        if ($value <= 0) {
            return $value;
        }
        $exponent = floor(log10($value));
        $base = 10 ** $exponent;
        $mantissa = $value / $base;
        $snapped = $mantissa <= 1 ? 1.0 : ($mantissa <= 2 ? 2.0 : ($mantissa <= 5 ? 5.0 : 10.0));
        return $snapped * $base;
    }
}
