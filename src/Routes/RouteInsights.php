<?php
declare(strict_types=1);

namespace App\Routes;

/**
 * Berechnet aus einem Routen-Payload (GPX/GeoJSON) zwei Auswertungen für
 * die Web-Detail-Seite:
 *
 *  - **Höhenprofil**: kumulierte Distanz ↔ Höhe, heruntergerechnet auf eine
 *    zeichenbare Punktzahl für ein Inline-SVG (kein externes JS/Lib, damit
 *    CSP-konform).
 *  - **Surface-Score-Verteilung**: Meter und Prozent je Score, aggregiert aus
 *    den `<ge:surfaceScore>`-Segmenten (über {@see SurfaceTrack}).
 *
 * Bewusst defensiv: bei kaputtem/leerem Payload oder fehlenden Daten liefert
 * {@see compute()} `null` bzw. `hasData = false`, sodass die View das Panel
 * einfach weglässt. Längen werden per Haversine geschätzt — gut genug für
 * eine Verteilungsanzeige, ohne projizierte Geometrie.
 */
final class RouteInsights
{
    private const EARTH_RADIUS_M = 6_371_000.0;

    public function __construct(
        private readonly GeometryParser $parser,
        private readonly ?SurfaceTrack $surface = null,
    ) {
    }

    /**
     * @return array{
     *   elevation: array{points: list<array{d:int,e:int}>, hasData:bool, minE:int, maxE:int, gain:int, distanceM:int},
     *   surface: array{hasData:bool, totalM:int, buckets: list<array{score:int|null,distanceM:int,percent:float}>}
     * }|null
     */
    public function compute(string $payload, int $maxPoints = 240): ?array
    {
        try {
            $parsed = $this->parser->parse($payload);
        } catch (\Throwable) {
            return null;
        }
        if (count($parsed->points) < 2) {
            return null;
        }

        $elevation = $this->elevationProfile($parsed->points, max(2, $maxPoints));
        $surface   = $this->surfaceDistribution($payload);

        if (!$elevation['hasData'] && !$surface['hasData']) {
            return null;
        }
        return ['elevation' => $elevation, 'surface' => $surface];
    }

    /**
     * @param list<ParsedPoint> $points
     * @return array{points: list<array{d:int,e:int}>, hasData:bool, minE:int, maxE:int, gain:int, distanceM:int}
     */
    private function elevationProfile(array $points, int $maxPoints): array
    {
        $cum  = 0.0;
        $prev = null;
        $raw  = []; // list<array{d:float, e:?float}>
        foreach ($points as $p) {
            if ($prev !== null) {
                $cum += self::haversine($prev->lat, $prev->lon, $p->lat, $p->lon);
            }
            $raw[] = ['d' => $cum, 'e' => $p->elevationM];
            $prev  = $p;
        }
        $distanceM = (int)round($cum);

        $withE = array_values(array_filter($raw, static fn(array $r): bool => $r['e'] !== null));
        if (count($withE) < 2) {
            return ['points' => [], 'hasData' => false, 'minE' => 0, 'maxE' => 0, 'gain' => 0, 'distanceM' => $distanceM];
        }

        $gain = 0.0;
        $pe   = null;
        foreach ($withE as $r) {
            if ($pe !== null) {
                $delta = $r['e'] - $pe;
                if ($delta > 0) {
                    $gain += $delta;
                }
            }
            $pe = $r['e'];
        }

        $elevations = array_map(static fn(array $r): float => (float)$r['e'], $withE);
        $minE = min($elevations);
        $maxE = max($elevations);

        $sampled = self::downsample($withE, $maxPoints);
        $outPts  = array_map(
            static fn(array $r): array => ['d' => (int)round($r['d']), 'e' => (int)round((float)$r['e'])],
            $sampled,
        );

        return [
            'points'    => $outPts,
            'hasData'   => true,
            'minE'      => (int)round($minE),
            'maxE'      => (int)round($maxE),
            'gain'      => (int)round($gain),
            'distanceM' => $distanceM,
        ];
    }

    /**
     * @return array{hasData:bool, totalM:int, buckets: list<array{score:int|null,distanceM:int,percent:float}>}
     */
    private function surfaceDistribution(string $payload): array
    {
        $empty = ['hasData' => false, 'totalM' => 0, 'buckets' => []];
        if ($this->surface === null) {
            return $empty;
        }

        $fc = $this->surface->extract($payload);
        if ($fc === null || empty($fc['features'])) {
            return $empty;
        }

        $byScore = []; // int|'null' => float (Meter)
        $total   = 0.0;
        foreach ($fc['features'] as $feature) {
            $coords = $feature['geometry']['coordinates'] ?? null;
            if (!is_array($coords)) {
                continue;
            }
            $len = self::lineLength($coords);
            if ($len <= 0.0) {
                continue;
            }
            $score = self::featureScore($feature['properties'] ?? null);
            $key   = $score ?? 'null';
            $byScore[$key] = ($byScore[$key] ?? 0.0) + $len;
            $total += $len;
        }

        if ($total <= 0.0) {
            return $empty;
        }

        $buckets = [];
        $scores  = array_filter(array_keys($byScore), 'is_int');
        sort($scores);
        foreach ($scores as $s) {
            $buckets[] = [
                'score'     => $s,
                'distanceM' => (int)round($byScore[$s]),
                'percent'   => round($byScore[$s] / $total * 100, 1),
            ];
        }
        if (isset($byScore['null'])) {
            $buckets[] = [
                'score'     => null,
                'distanceM' => (int)round($byScore['null']),
                'percent'   => round($byScore['null'] / $total * 100, 1),
            ];
        }

        return ['hasData' => true, 'totalM' => (int)round($total), 'buckets' => $buckets];
    }

    /**
     * Liest den Score aus den Feature-Properties — die können Array oder
     * (bei score=null) ein leeres stdClass-Objekt sein.
     */
    private static function featureScore(mixed $props): ?int
    {
        if (is_array($props) && array_key_exists('score', $props) && is_numeric($props['score'])) {
            return (int)$props['score'];
        }
        if (is_object($props) && isset($props->score) && is_numeric($props->score)) {
            return (int)$props->score;
        }
        return null;
    }

    /**
     * @param list<mixed> $coords  Liste von [lon, lat, (alt)]
     */
    private static function lineLength(array $coords): float
    {
        $len  = 0.0;
        $prev = null; // [lat, lon]
        foreach ($coords as $c) {
            if (!is_array($c) || count($c) < 2) {
                continue;
            }
            $lat = (float)$c[1];
            $lon = (float)$c[0];
            if ($prev !== null) {
                $len += self::haversine($prev[0], $prev[1], $lat, $lon);
            }
            $prev = [$lat, $lon];
        }
        return $len;
    }

    /**
     * @param list<array{d:float,e:?float}> $items
     * @return list<array{d:float,e:?float}>
     */
    private static function downsample(array $items, int $max): array
    {
        $n = count($items);
        if ($n <= $max) {
            return $items;
        }
        $out  = [];
        $step = ($n - 1) / ($max - 1);
        for ($i = 0; $i < $max; $i++) {
            $out[] = $items[(int)round($i * $step)];
        }
        return $out;
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
