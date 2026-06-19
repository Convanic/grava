<?php
declare(strict_types=1);

namespace App\Heatmap;

use App\Database\Db;
use App\Routes\GeometryParser;
use App\Routes\SurfaceTrack;
use PDO;

/**
 * M9: Surface-Check — projiziert die vorhandenen Crowd-Belagsdaten
 * (`heatmap_edges`) auf eine hochgeladene Fremd-Route (z. B. Strava-GPX).
 *
 * Zwei Verfahren, gleiche Ergebnisform ({@see resultShape()}):
 *  - {@see analyzeSpatial()} (Standard, prod-tauglich): rein geometrische
 *    Projektion via {@see SurfaceProjector}. KEIN Valhalla im Request-Pfad —
 *    liest nur `heatmap_edges` (per BBox der Route). Läuft sofort in Prod.
 *  - {@see analyzeValhalla()} (on-demand, "Details zur Wegbeschaffenheit"):
 *    präzises Map-Matching via {@see ValhallaClient}; pro gematchter Kante der
 *    {@see EdgeKey} -> exakter Lookup in `heatmap_edges`. Braucht ein
 *    erreichbares Valhalla (lokal/Staging) und liefert sonst `null`.
 *
 * Privacy: liest ausschließlich die anonym aggregierten `heatmap_edges`.
 */
final class RouteSurfaceService
{
    public function __construct(
        private readonly GeometryParser $parser,
        private readonly SurfaceTrack $surface,
        private readonly SurfaceProjector $projector,
        private readonly ?ValhallaClient $valhalla = null,
        private readonly float $bufferDeg = 0.0005,
    ) {}

    /**
     * Extrahiert die Routenpunkte aus einem GPX/GeoJSON-Payload.
     *
     * @return list<array{lat:float,lon:float}>
     */
    public function routePoints(string $payload): array
    {
        $pts = $this->surface->points($payload);
        if ($pts !== null) {
            return array_map(static fn($p) => ['lat' => $p['lat'], 'lon' => $p['lon']], $pts);
        }
        // Kein GPX (z. B. GeoJSON) → Geometrie ohne Scores.
        $parsed = $this->parser->parse($payload);
        $out = [];
        foreach ($parsed->points as $p) {
            $out[] = ['lat' => $p->lat, 'lon' => $p->lon];
        }
        return $out;
    }

    /**
     * Dünnt die Punktfolge aus (delegiert an den {@see SurfaceProjector}) —
     * für den Aufrufer, der die Punkte für den späteren Valhalla-Pfad
     * zwischenspeichert.
     *
     * @param list<array{lat:float,lon:float}> $points
     * @return list<array{lat:float,lon:float}>
     */
    public function downsample(array $points): array
    {
        return $this->projector->resample($points);
    }

    /**
     * Standard-Analyse (geometrische Projektion, ohne Valhalla).
     *
     * @return array{method:string,geojson:array<string,mixed>,summary:array<string,mixed>}
     */
    public function analyzeSpatial(string $payload): array
    {
        return $this->analyzeSpatialPoints($this->routePoints($payload));
    }

    /**
     * Wie {@see analyzeSpatial()}, aber auf bereits extrahierten Punkten —
     * spart einen zweiten Parse, wenn der Aufrufer die Punkte schon hat.
     *
     * @param list<array{lat:float,lon:float}> $points
     * @return array{method:string,geojson:array<string,mixed>,summary:array<string,mixed>}
     */
    public function analyzeSpatialPoints(array $points): array
    {
        if (count($points) < 2) {
            $empty = $this->projector->project([], []);
            return ['method' => 'spatial', 'geojson' => $empty['geojson'], 'summary' => $empty['summary']];
        }

        $edges = $this->candidateEdges($points);
        $res = $this->projector->project($points, $edges);

        return [
            'method'  => 'spatial',
            'geojson' => $res['geojson'],
            'summary' => $res['summary'],
        ];
    }

    /**
     * Präzise Analyse via Valhalla-Map-Matching. Liefert `null`, wenn kein
     * Valhalla konfiguriert/erreichbar ist (Aufrufer zeigt dann einen Hinweis).
     *
     * @param list<array{lat:float,lon:float}> $points
     * @return array{method:string,geojson:array<string,mixed>,summary:array<string,mixed>}|null
     */
    public function analyzeValhalla(array $points): ?array
    {
        if ($this->valhalla === null || count($points) < 2) {
            return null;
        }
        // Vor dem Matching ausdünnen (Valhalla-Punktelimit / Performance).
        $points = $this->projector->resample($points);
        $match = $this->valhalla->matchTrace($points);
        if ($match === null || $match->edges === []) {
            return null;
        }

        // Eindeutige edge_keys der gematchten Kanten -> Crowd-Daten nachschlagen.
        $keys = [];
        foreach ($match->edges as $edge) {
            $key = EdgeKey::for($edge->wayId, $edge->geometry);
            if ($key !== null) {
                $keys[$key] = true;
            }
        }
        $crowd = $this->lookupEdges(array_keys($keys));

        return $this->buildFromMatchedEdges($match->edges, $crowd);
    }

    // ---- DB-Zugriff --------------------------------------------------------

    /**
     * Holt alle `heatmap_edges`, die die (gepufferte) BBox der Route schneiden.
     *
     * @param list<array{lat:float,lon:float}> $points
     * @return list<array{geom:list<array{0:float,1:float}>,avg_score:?float,surface:?string}>
     */
    private function candidateEdges(array $points): array
    {
        [$minLat, $minLon, $maxLat, $maxLon] = self::routeBbox($points);
        $b = $this->bufferDeg;

        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'SELECT geom_json, avg_score, osm_surface
               FROM heatmap_edges
              WHERE min_lat <= ? AND max_lat >= ? AND min_lon <= ? AND max_lon >= ?
              LIMIT 200000'
        );
        $stmt->execute([$maxLat + $b, $minLat - $b, $maxLon + $b, $minLon - $b]);

        $edges = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $geom = json_decode((string)$row['geom_json'], true);
            if (!is_array($geom) || count($geom) < 2) {
                continue;
            }
            $edges[] = [
                'geom'      => $geom,
                'avg_score' => $row['avg_score'] !== null ? (float)$row['avg_score'] : null,
                'surface'   => $row['osm_surface'] !== null ? (string)$row['osm_surface'] : null,
            ];
        }
        return $edges;
    }

    /**
     * Schlägt Crowd-Daten für eine Liste von edge_keys nach.
     *
     * @param list<string> $keys
     * @return array<string,array{avg_score:?float,surface:?string,route_count:int}>
     */
    private function lookupEdges(array $keys): array
    {
        $keys = array_values(array_filter($keys, static fn($k) => $k !== ''));
        if ($keys === []) {
            return [];
        }
        $pdo = Db::pdo();
        $out = [];
        // In Blöcken abfragen (IN-Liste begrenzt halten).
        foreach (array_chunk($keys, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $pdo->prepare(
                "SELECT edge_key, avg_score, osm_surface, route_count
                   FROM heatmap_edges
                  WHERE edge_key IN ({$placeholders})"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(string)$row['edge_key']] = [
                    'avg_score'   => $row['avg_score'] !== null ? (float)$row['avg_score'] : null,
                    'surface'     => $row['osm_surface'] !== null ? (string)$row['osm_surface'] : null,
                    'route_count' => (int)$row['route_count'],
                ];
            }
        }
        return $out;
    }

    // ---- Ergebnisaufbau (Valhalla-Pfad) -----------------------------------

    /**
     * Baut GeoJSON + Summary aus gematchten Kanten + Crowd-Lookup. Aufeinander
     * folgende Kanten gleichen Belags werden zu einem Feature zusammengefasst.
     *
     * @param list<ValhallaMatchedEdge> $edges
     * @param array<string,array{avg_score:?float,surface:?string,route_count:int}> $crowd
     * @return array{method:string,geojson:array<string,mixed>,summary:array<string,mixed>}
     */
    private function buildFromMatchedEdges(array $edges, array $crowd): array
    {
        $features = [];
        $totalLen = 0.0;
        $coveredLen = 0.0;
        $scoreWeighted = 0.0;
        $scoreWeight = 0.0;
        $byScore = array_fill(0, 6, 0.0);
        $buckets = ['paved' => 0.0, 'mixed' => 0.0, 'gravel' => 0.0];

        $run = null;

        foreach ($edges as $edge) {
            $geom = $edge->geometry;
            if (count($geom) < 2) {
                continue;
            }
            $len = (float)$edge->lengthM;
            $totalLen += $len;

            $key = EdgeKey::for($edge->wayId, $edge->geometry);
            $data = ($key !== null && isset($crowd[$key])) ? $crowd[$key] : null;
            $source = $data !== null ? 'crowd' : 'none';
            $avg = $data['avg_score'] ?? null;
            $surface = $data['surface'] ?? $edge->surface;
            $score = $avg !== null ? max(0, min(5, (int)round($avg))) : null;

            if ($source === 'crowd') {
                $coveredLen += $len;
                if ($avg !== null) {
                    $scoreWeighted += $len * $avg;
                    $scoreWeight += $len;
                    $byScore[$score] += $len;
                    if ($score <= 1) {
                        $buckets['paved'] += $len;
                    } elseif ($score <= 3) {
                        $buckets['mixed'] += $len;
                    } else {
                        $buckets['gravel'] += $len;
                    }
                }
            }

            $rkey = $source . '|' . ($score ?? 'na') . '|' . ($surface ?? '');
            if ($run !== null && $run['key'] === $rkey) {
                foreach ($geom as $i => $c) {
                    if ($i === 0) {
                        continue; // Anschlusspunkt schon enthalten
                    }
                    $run['coords'][] = [$c[0], $c[1]];
                }
            } else {
                if ($run !== null) {
                    $features[] = self::edgeFeature($run);
                }
                $coords = [];
                foreach ($geom as $c) {
                    $coords[] = [$c[0], $c[1]];
                }
                $run = ['key' => $rkey, 'coords' => $coords, 'score' => $score, 'surface' => $surface, 'source' => $source];
            }
        }
        if ($run !== null) {
            $features[] = self::edgeFeature($run);
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
            'method'  => 'valhalla',
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
    private static function edgeFeature(array $run): array
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

    // ---- Helfer ------------------------------------------------------------

    /**
     * @param list<array{lat:float,lon:float}> $points
     * @return array{0:float,1:float,2:float,3:float} [minLat, minLon, maxLat, maxLon]
     */
    private static function routeBbox(array $points): array
    {
        $minLat = 90.0; $minLon = 180.0; $maxLat = -90.0; $maxLon = -180.0;
        foreach ($points as $p) {
            $minLat = min($minLat, $p['lat']); $maxLat = max($maxLat, $p['lat']);
            $minLon = min($minLon, $p['lon']); $maxLon = max($maxLon, $p['lon']);
        }
        return [$minLat, $minLon, $maxLat, $maxLon];
    }

    /** @return array<string,mixed> */
    private static function emptyFc(): array
    {
        return ['type' => 'FeatureCollection', 'features' => []];
    }
}
