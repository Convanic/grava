<?php
declare(strict_types=1);

namespace App\Heatmap;

/**
 * M9: Projiziert vorhandene Crowd-Belagsdaten (aus `heatmap_edges`) auf eine
 * fremde, hochgeladene Route — rein geometrisch, OHNE Valhalla.
 *
 * Idee: Für jeden (ausgedünnten) Routenpunkt wird die nächstgelegene
 * Crowd-Kante gesucht. Liegt sie innerhalb des Schwellwerts ({@see thresholdM}),
 * übernimmt das anschließende Segment deren `avg_score`/`surface`. Punkte ohne
 * Treffer bleiben "ohne Daten". Daraus entsteht eine GeoJSON-FeatureCollection
 * (Run-Length-codiert nach gleichem Belag) plus eine Zusammenfassung
 * (Abdeckung, Längen je Score, Buckets, Ø-Score).
 *
 * Bewusst pur und ohne I/O (DB/HTTP) gehalten — damit ohne Infrastruktur
 * testbar. Die Kandidaten-Kanten liefert {@see RouteSurfaceService} per
 * BBox-Query.
 *
 * Performance: Ein einfacher Hash-Grid-Index ({@see buildGrid()}) über die
 * Kanten-Stützpunkte macht die Nächste-Kante-Suche lokal (3x3 Zellen), statt
 * jede Route gegen jede Kante zu prüfen.
 */
final class SurfaceProjector
{
    private const M_PER_DEG_LAT = 110540.0;

    public function __construct(
        private readonly float $thresholdM = 25.0,
        private readonly int $resampleM = 20,
        private readonly float $cellDeg = 0.003, // ~333 m
    ) {}

    /**
     * Projiziert die Route auf die Kandidaten-Kanten.
     *
     * @param list<array{lat:float,lon:float}> $points Routenpunkte (Reihenfolge)
     * @param list<array{geom:list<array{0:float,1:float}>,avg_score:?float,surface:?string}> $edges
     * @return array{geojson:array<string,mixed>,summary:array<string,mixed>}
     */
    public function project(array $points, array $edges): array
    {
        $pts = $this->resample($points);
        $n = count($pts);
        if ($n < 2) {
            return [
                'geojson' => ['type' => 'FeatureCollection', 'features' => []],
                'summary' => self::emptySummary(),
            ];
        }

        $grid = $this->buildGrid($edges);

        // Pro Punkt die nächste Kante (innerhalb Schwellwert) zuordnen.
        $assign = [];
        for ($i = 0; $i < $n; $i++) {
            $assign[$i] = $this->nearestEdge($pts[$i]['lat'], $pts[$i]['lon'], $edges, $grid);
        }

        return $this->buildResult($pts, $assign);
    }

    /**
     * Dünnt die Punktfolge aus: aufeinanderfolgende Punkte mindestens
     * `resampleM` Meter auseinander; erster/letzter Punkt bleiben erhalten.
     *
     * @param list<array{lat:float,lon:float}> $points
     * @return list<array{lat:float,lon:float}>
     */
    public function resample(array $points): array
    {
        $clean = [];
        foreach ($points as $p) {
            if (isset($p['lat'], $p['lon'])) {
                $clean[] = ['lat' => (float)$p['lat'], 'lon' => (float)$p['lon']];
            }
        }
        $n = count($clean);
        if ($n <= 2) {
            return $clean;
        }
        $out = [$clean[0]];
        $last = $clean[0];
        for ($i = 1; $i < $n - 1; $i++) {
            if (self::haversine($last['lat'], $last['lon'], $clean[$i]['lat'], $clean[$i]['lon']) >= $this->resampleM) {
                $out[] = $clean[$i];
                $last = $clean[$i];
            }
        }
        $out[] = $clean[$n - 1];
        return $out;
    }

    /**
     * Baut einen Hash-Grid-Index: Zelle -> Liste von Kanten-Indizes, deren
     * Geometrie einen Stützpunkt in dieser Zelle hat.
     *
     * @param list<array{geom:list<array{0:float,1:float}>,avg_score:?float,surface:?string}> $edges
     * @return array<string,list<int>>
     */
    public function buildGrid(array $edges): array
    {
        $grid = [];
        foreach ($edges as $idx => $edge) {
            $seen = [];
            foreach (($edge['geom'] ?? []) as $vertex) {
                if (!isset($vertex[0], $vertex[1])) {
                    continue;
                }
                $key = $this->cellKey((float)$vertex[1], (float)$vertex[0]);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $grid[$key][] = $idx;
            }
        }
        return $grid;
    }

    /**
     * Sucht die nächste Kante zu (lat, lon) innerhalb des Schwellwerts.
     *
     * @param list<array{geom:list<array{0:float,1:float}>,avg_score:?float,surface:?string}> $edges
     * @param array<string,list<int>> $grid
     * @return array{avg_score:?float,surface:?string}|null
     */
    public function nearestEdge(float $lat, float $lon, array $edges, array $grid): ?array
    {
        $cellLat = (int)floor($lat / $this->cellDeg);
        $cellLon = (int)floor($lon / $this->cellDeg);

        $candidates = [];
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                $key = ($cellLat + $dy) . ':' . ($cellLon + $dx);
                foreach (($grid[$key] ?? []) as $idx) {
                    $candidates[$idx] = true;
                }
            }
        }
        if ($candidates === []) {
            return null;
        }

        $mPerDegLon = self::M_PER_DEG_LAT * cos(deg2rad($lat));
        $best = null;
        $bestDist = $this->thresholdM;

        foreach (array_keys($candidates) as $idx) {
            $geom = $edges[$idx]['geom'] ?? [];
            $c = count($geom);
            for ($k = 0; $k < $c - 1; $k++) {
                $d = self::distPointToSegment(
                    $lat, $lon,
                    (float)$geom[$k][1], (float)$geom[$k][0],
                    (float)$geom[$k + 1][1], (float)$geom[$k + 1][0],
                    $mPerDegLon,
                );
                if ($d <= $bestDist) {
                    $bestDist = $d;
                    $best = $idx;
                }
            }
        }

        if ($best === null) {
            return null;
        }
        return [
            'avg_score' => isset($edges[$best]['avg_score']) && $edges[$best]['avg_score'] !== null
                ? (float)$edges[$best]['avg_score'] : null,
            'surface'   => isset($edges[$best]['surface']) && $edges[$best]['surface'] !== null
                ? (string)$edges[$best]['surface'] : null,
        ];
    }

    // ---- Ergebnisaufbau ----------------------------------------------------

    /**
     * Baut aus Punkten + Zuordnungen die GeoJSON-Features (Run-Length nach
     * gleichem Belag) und die Zusammenfassung.
     *
     * @param list<array{lat:float,lon:float}> $pts
     * @param list<array{avg_score:?float,surface:?string}|null> $assign
     * @return array{geojson:array<string,mixed>,summary:array<string,mixed>}
     */
    private function buildResult(array $pts, array $assign): array
    {
        $n = count($pts);

        $features = [];
        $totalLen = 0.0;
        $coveredLen = 0.0;
        $scoreWeighted = 0.0;
        $scoreWeight = 0.0;
        $byScore = array_fill(0, 6, 0.0);
        $buckets = ['paved' => 0.0, 'mixed' => 0.0, 'gravel' => 0.0];

        $run = null; // ['coords'=>[], 'key'=>..., 'score'=>?int, 'surface'=>?string, 'source'=>...]

        for ($i = 0; $i < $n - 1; $i++) {
            $a = $pts[$i];
            $b = $pts[$i + 1];
            $segLen = self::haversine($a['lat'], $a['lon'], $b['lat'], $b['lon']);
            $totalLen += $segLen;

            $info = $assign[$i] ?? null;
            $source = $info !== null ? 'crowd' : 'none';
            $avg = $info['avg_score'] ?? null;
            $surface = $info['surface'] ?? null;
            $score = $avg !== null ? (int)round($avg) : null;
            $score = $score !== null ? max(0, min(5, $score)) : null;

            if ($source === 'crowd') {
                $coveredLen += $segLen;
                if ($avg !== null) {
                    $scoreWeighted += $segLen * $avg;
                    $scoreWeight += $segLen;
                    $byScore[$score] += $segLen;
                    if ($score <= 1) {
                        $buckets['paved'] += $segLen;
                    } elseif ($score <= 3) {
                        $buckets['mixed'] += $segLen;
                    } else {
                        $buckets['gravel'] += $segLen;
                    }
                }
            }

            $key = $source . '|' . ($score ?? 'na') . '|' . ($surface ?? '');
            if ($run !== null && $run['key'] === $key) {
                $run['coords'][] = [$b['lon'], $b['lat']];
            } else {
                if ($run !== null) {
                    $features[] = self::feature($run);
                }
                $run = [
                    'key'     => $key,
                    'coords'  => [[$a['lon'], $a['lat']], [$b['lon'], $b['lat']]],
                    'score'   => $score,
                    'surface' => $surface,
                    'source'  => $source,
                ];
            }
        }
        if ($run !== null) {
            $features[] = self::feature($run);
        }

        $coveragePct = $totalLen > 0 ? round($coveredLen / $totalLen * 100, 1) : 0.0;
        $avgScore = $scoreWeight > 0 ? round($scoreWeighted / $scoreWeight, 2) : null;

        $byScoreOut = [];
        for ($s = 0; $s <= 5; $s++) {
            $byScoreOut[] = [
                'score'    => $s,
                'length_m' => (int)round($byScore[$s]),
                'pct'      => $totalLen > 0 ? round($byScore[$s] / $totalLen * 100, 1) : 0.0,
            ];
        }

        return [
            'geojson' => ['type' => 'FeatureCollection', 'features' => $features],
            'summary' => [
                'total_length_m'   => (int)round($totalLen),
                'covered_length_m' => (int)round($coveredLen),
                'coverage_pct'     => $coveragePct,
                'avg_score'        => $avgScore,
                'by_score'         => $byScoreOut,
                'by_bucket'        => [
                    'paved'  => $totalLen > 0 ? round($buckets['paved'] / $totalLen * 100, 1) : 0.0,
                    'mixed'  => $totalLen > 0 ? round($buckets['mixed'] / $totalLen * 100, 1) : 0.0,
                    'gravel' => $totalLen > 0 ? round($buckets['gravel'] / $totalLen * 100, 1) : 0.0,
                ],
            ],
        ];
    }

    /**
     * @param array{coords:list<array{0:float,1:float}>,score:?int,surface:?string,source:string} $run
     * @return array<string,mixed>
     */
    private static function feature(array $run): array
    {
        return [
            'type' => 'Feature',
            'geometry' => ['type' => 'LineString', 'coordinates' => $run['coords']],
            'properties' => [
                'score'   => $run['score'],
                'surface' => $run['surface'],
                'source'  => $run['source'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function emptySummary(): array
    {
        $byScore = [];
        for ($s = 0; $s <= 5; $s++) {
            $byScore[] = ['score' => $s, 'length_m' => 0, 'pct' => 0.0];
        }
        return [
            'total_length_m'   => 0,
            'covered_length_m' => 0,
            'coverage_pct'     => 0.0,
            'avg_score'        => null,
            'by_score'         => $byScore,
            'by_bucket'        => ['paved' => 0.0, 'mixed' => 0.0, 'gravel' => 0.0],
        ];
    }

    // ---- Geometrie-Helfer --------------------------------------------------

    private function cellKey(float $lat, float $lon): string
    {
        return ((int)floor($lat / $this->cellDeg)) . ':' . ((int)floor($lon / $this->cellDeg));
    }

    /**
     * Kürzeste Distanz (Meter) von Punkt P zu Segment A–B. Planar mit
     * cos(lat)-Korrektur der Längengrade — auf diesen kurzen Distanzen genau
     * genug und deutlich billiger als sphärische Projektion je Segment.
     */
    private static function distPointToSegment(
        float $pLat, float $pLon,
        float $aLat, float $aLon,
        float $bLat, float $bLon,
        float $mPerDegLon,
    ): float {
        // In lokale Meter relativ zu A.
        $px = ($pLon - $aLon) * $mPerDegLon;
        $py = ($pLat - $aLat) * self::M_PER_DEG_LAT;
        $bx = ($bLon - $aLon) * $mPerDegLon;
        $by = ($bLat - $aLat) * self::M_PER_DEG_LAT;

        $segLenSq = $bx * $bx + $by * $by;
        if ($segLenSq <= 0.0) {
            return sqrt($px * $px + $py * $py);
        }
        $t = ($px * $bx + $py * $by) / $segLenSq;
        $t = max(0.0, min(1.0, $t));
        $cx = $t * $bx;
        $cy = $t * $by;
        $dx = $px - $cx;
        $dy = $py - $cy;
        return sqrt($dx * $dx + $dy * $dy);
    }

    private static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
