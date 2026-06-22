<?php
declare(strict_types=1);

namespace App\Heatmap;

use App\Database\Db;
use App\Privacy\PrivacyZone;
use App\Privacy\PrivacyZoneRepository;
use App\Routes\GeometryParser;
use App\Routes\RouteService;
use App\Routes\SurfaceTrack;
use App\Support\Clock;
use PDO;

/**
 * M6: Heatmap-Streckenlinien via Map-Matching.
 *
 * `rebuild()` snappt alle public Routen durch Valhalla aufs OSM-Netz und
 * aggregiert pro (ungerichteter) Kante Häufigkeit + Ø-Surface-Score in
 * `heatmap_edges` (voller Neuaufbau, idempotent — wie {@see HeatmapService}).
 * `query()` liest nur diese Tabelle (kein Valhalla im Request-Pfad).
 *
 * Die Aggregation ({@see accumulate()}/{@see finalize()}) ist bewusst pur
 * und ohne I/O gehalten, damit sie ohne DB/Valhalla testbar ist.
 *
 * Privacy: nur visibility='public'; anonyme Aggregation (keine User-Zuordnung).
 */
final class HeatmapLinesService
{
    public function __construct(
        private readonly ?ValhallaClient $valhalla = null,
        private readonly ?RouteService $routes = null,
        private readonly ?GeometryParser $parser = null,
        private readonly ?SurfaceTrack $surface = null,
        private readonly int $minRoutes = 1,
        private readonly int $resampleM = 20,
        private readonly int $maxPointsPerRequest = 15000,
        private readonly ?PrivacyZoneRepository $privacyZones = null,
    ) {}

    /**
     * Voller Neuaufbau der heatmap_edges aus den public Routen.
     *
     * @return array{routes:int,matched:int,skipped:int,edges:int}
     */
    public function rebuild(): array
    {
        if ($this->valhalla === null || $this->routes === null || $this->parser === null || $this->surface === null) {
            throw new \RuntimeException('HeatmapLinesService::rebuild() benötigt valhalla/routes/parser/surface.');
        }
        $pdo = Db::pdo();
        // user_id mitziehen, um Track-Punkte in der Privatzone des Eigentümers
        // auszuklammern (§17 Punkt 3). Zonen einmalig als Map laden.
        $rowsR = $pdo
            ->query("SELECT public_id, user_id FROM routes WHERE visibility='public' AND deleted_at IS NULL")
            ->fetchAll(PDO::FETCH_ASSOC);
        $zonesByUser = $this->privacyZones?->enabledZonesByUser() ?? [];

        $acc = [];
        $matched = 0;
        $skipped = 0;

        foreach ($rowsR as $r) {
            $pid = (string)$r['public_id'];
            $zone = $zonesByUser[(int)$r['user_id']] ?? null;
            try {
                $loaded = $this->routes->loadPayloadByPublicId($pid);
                $this->accumulateOne($acc, $loaded['payload'], $matched, $skipped, $zone);
            } catch (\Throwable) {
                $skipped++;
            }
        }

        $rows = $this->finalize($acc);
        $this->write($rows);

        return [
            'routes'  => count($rowsR),
            'matched' => $matched,
            'skipped' => $skipped,
            'edges'   => count($rows),
        ];
    }

    /**
     * Manifest der public Routen für den Cutover-Hinweg (Modell A): Liste aus
     * `public_id` + relativem `payload_path` + `format` der jeweiligen
     * Head-Version. Wird auf PROD (wo die DB erreichbar ist) erzeugt und per
     * `/internal/heatmap/manifest` ausgegeben; lokal speist es
     * {@see rebuildFromManifest()}. Privacy: nur visibility='public'.
     *
     * @return list<array{public_id:string,payload_path:string,format:string}>
     */
    public function publicManifest(): array
    {
        $pdo = Db::pdo();
        $rows = $pdo->query(
            "SELECT r.public_id, v.payload_path, v.format
               FROM routes r
               JOIN route_versions v ON v.id = r.head_version_id
              WHERE r.visibility='public' AND r.deleted_at IS NULL
              ORDER BY r.public_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'public_id'    => (string)$row['public_id'],
                'payload_path' => (string)$row['payload_path'],
                'format'       => (string)$row['format'],
            ];
        }
        return $out;
    }

    /**
     * DB-freier Rebuild aus einem Manifest + lokal vorliegenden Payload-Dateien.
     *
     * Anders als {@see rebuild()} braucht das KEINE `routes`/`route_versions`
     * in der lokalen DB und KEINEN {@see RouteService} — gedacht für den
     * Cutover-Hinweg: Manifest (HTTP) + Dateien (SFTP) von PROD, Matching
     * lokal, Ergebnis nach `heatmap_edges`. Valhalla/Parser/Surface sind nötig.
     *
     * @param list<array{public_id?:string,payload_path:string,format?:string}> $entries
     * @param string $baseDir Basisverzeichnis, gegen das `payload_path` aufgelöst wird.
     * @return array{routes:int,matched:int,skipped:int,edges:int}
     */
    public function rebuildFromManifest(array $entries, string $baseDir): array
    {
        if ($this->valhalla === null || $this->parser === null || $this->surface === null) {
            throw new \RuntimeException('rebuildFromManifest() benötigt valhalla/parser/surface.');
        }
        $base = rtrim($baseDir, '/');

        $acc = [];
        $matched = 0;
        $skipped = 0;

        foreach ($entries as $e) {
            try {
                $rel = ltrim((string)($e['payload_path'] ?? ''), '/');
                if ($rel === '' || str_contains($rel, '..')) {
                    $skipped++;
                    continue;
                }
                $abs = $base . '/' . $rel;
                if (!is_file($abs)) {
                    $skipped++;
                    continue;
                }
                $payload = @file_get_contents($abs);
                if ($payload === false || $payload === '') {
                    $skipped++;
                    continue;
                }
                $this->accumulateOne($acc, $payload, $matched, $skipped);
            } catch (\Throwable) {
                $skipped++;
            }
        }

        $rows = $this->finalize($acc);
        $this->write($rows);

        return [
            'routes'  => count($entries),
            'matched' => $matched,
            'skipped' => $skipped,
            'edges'   => count($rows),
        ];
    }

    // ---- Cutover-Rückweg: Export/Import der heatmap_edges ------------------

    /**
     * Liest alle heatmap_edges-Zeilen für den HTTP-Export (Cutover-Rückweg).
     * `geom_json` bleibt als JSON-String (wird beim Import 1:1 wieder gesetzt).
     *
     * @return list<array<string,mixed>>
     */
    public function exportRows(): array
    {
        $pdo = Db::pdo();
        $rows = $pdo->query(
            "SELECT edge_key, way_id, geom_json, min_lat, min_lon, max_lat, max_lon,
                    length_m, route_count, score_sum, score_n, avg_score, osm_surface
               FROM heatmap_edges"
        )->fetchAll(PDO::FETCH_ASSOC);
        return $rows ?: [];
    }

    /**
     * Importiert heatmap_edges-Zeilen aus JSON in eine Shadow-Tabelle und macht
     * dann einen atomaren RENAME-Swap (kein Lese-Ausfall). Cutover-Rückweg über
     * HTTP — bewusst sicher: ausschließlich parametrisierte INSERTs + DDL auf
     * fixe Tabellennamen, KEIN beliebiges SQL aus dem Body.
     *
     * Akzeptiert `{"rows":[…]}` oder ein nacktes Array `[…]`.
     *
     * @return array{received:int,imported:int,swapped:bool}
     */
    public function importEdges(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $rows = [];
        if (is_array($data)) {
            $rows = is_array($data['rows'] ?? null) ? $data['rows'] : (array_is_list($data) ? $data : []);
        }

        $pdo = Db::pdo();
        $now = Clock::nowUtcString();

        // DDL committet implizit — daher Shadow erst frisch erzeugen, dann die
        // INSERTs in einer Transaktion, danach der atomare RENAME.
        $pdo->exec('DROP TABLE IF EXISTS heatmap_edges_new');
        $pdo->exec('CREATE TABLE heatmap_edges_new LIKE heatmap_edges');

        $imported = 0;
        $pdo->beginTransaction();
        try {
            $sql = 'INSERT INTO heatmap_edges_new
                (edge_key, way_id, geom_json, min_lat, min_lon, max_lat, max_lon,
                 length_m, route_count, score_sum, score_n, avg_score, osm_surface, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $stmt = $pdo->prepare($sql);
            foreach ($rows as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $edgeKey = (string)($r['edge_key'] ?? '');
                $geom    = $r['geom_json'] ?? null;
                if (is_array($geom)) {
                    $geom = json_encode($geom, JSON_THROW_ON_ERROR);
                }
                $geom = (string)($geom ?? '');
                if ($edgeKey === '' || $geom === '') {
                    continue;
                }
                $stmt->execute([
                    $edgeKey,
                    isset($r['way_id']) && $r['way_id'] !== null ? (int)$r['way_id'] : null,
                    $geom,
                    (float)($r['min_lat'] ?? 0),
                    (float)($r['min_lon'] ?? 0),
                    (float)($r['max_lat'] ?? 0),
                    (float)($r['max_lon'] ?? 0),
                    (int)($r['length_m'] ?? 0),
                    (int)($r['route_count'] ?? 0),
                    isset($r['score_sum']) && $r['score_sum'] !== null ? (float)$r['score_sum'] : null,
                    (int)($r['score_n'] ?? 0),
                    isset($r['avg_score']) && $r['avg_score'] !== null ? (float)$r['avg_score'] : null,
                    isset($r['osm_surface']) && $r['osm_surface'] !== null ? (string)$r['osm_surface'] : null,
                    $now,
                ]);
                $imported++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $pdo->exec('DROP TABLE IF EXISTS heatmap_edges_new');
            throw $e;
        }

        $swapped = false;
        if ($imported > 0) {
            $pdo->exec('DROP TABLE IF EXISTS heatmap_edges_old');
            $pdo->exec('RENAME TABLE heatmap_edges TO heatmap_edges_old, heatmap_edges_new TO heatmap_edges');
            $pdo->exec('DROP TABLE heatmap_edges_old');
            $swapped = true;
        } else {
            // Nichts Brauchbares erhalten → Swap NICHT durchführen, Live-Tabelle
            // bleibt unverändert.
            $pdo->exec('DROP TABLE IF EXISTS heatmap_edges_new');
        }

        return [
            'received' => is_array($rows) ? count($rows) : 0,
            'imported' => $imported,
            'swapped'  => $swapped,
        ];
    }

    /**
     * Verarbeitet genau einen Payload (extrahieren → downsample → match →
     * akkumulieren). Erhöht `matched` bei Erfolg, sonst `skipped`. Geteilt von
     * {@see rebuild()} (DB) und {@see rebuildFromManifest()} (Datei).
     *
     * @param array<string,array<string,mixed>> $acc by-ref Akkumulator
     */
    private function accumulateOne(array &$acc, string $payload, int &$matched, int &$skipped, ?PrivacyZone $zone = null): void
    {
        $points = $this->extractPoints($payload);
        if (count($points) < 2) {
            $skipped++;
            return;
        }

        // Privacy: Track-Punkte in der Eigentümer-Zone entfernen und den Track
        // in zusammenhängende Läufe AUSSERHALB der Zone zerlegen, damit
        // Valhalla nicht quer durch das Zuhause snappt.
        $runs = $this->splitOutsideZone($points, $zone);
        if ($runs === []) {
            $skipped++;
            return;
        }

        $anyMatched = false;
        foreach ($runs as $run) {
            $run   = $this->downsample($run);
            $match = $this->valhalla->matchTrace(
                array_map(static fn($p) => ['lat' => $p['lat'], 'lon' => $p['lon']], $run)
            );
            if ($match === null) {
                continue;
            }
            $this->accumulate($acc, $run, $match);
            $anyMatched = true;
        }
        $anyMatched ? $matched++ : $skipped++;
    }

    /**
     * Zerlegt eine Punktfolge in zusammenhängende Läufe außerhalb der Zone
     * (Läufe < 2 Punkte verworfen). Ohne Zone: ein Lauf = ganze Folge.
     *
     * @param list<array{lat:float,lon:float}> $points
     * @return list<list<array{lat:float,lon:float}>>
     */
    private function splitOutsideZone(array $points, ?PrivacyZone $zone): array
    {
        if ($zone === null) {
            return [$points];
        }
        $runs = [];
        $current = [];
        foreach ($points as $p) {
            if ($zone->containsPoint((float)$p['lat'], (float)$p['lon'])) {
                if (count($current) >= 2) {
                    $runs[] = $current;
                }
                $current = [];
                continue;
            }
            $current[] = $p;
        }
        if (count($current) >= 2) {
            $runs[] = $current;
        }
        return $runs;
    }

    /**
     * @param array{min_lat:float,min_lon:float,max_lat:float,max_lon:float}|null $bbox
     * @return array<string,mixed> GeoJSON FeatureCollection von LineStrings
     */
    public function query(?array $bbox, int $limit = 20000): array
    {
        $limit = max(1, min(50000, $limit));
        $pdo = Db::pdo();

        $where = '';
        $args  = [];
        if ($bbox !== null) {
            // BBox-Overlap (kein Spatial nötig): Kante schneidet das Viewport.
            $where = 'WHERE min_lat <= ? AND max_lat >= ? AND min_lon <= ? AND max_lon >= ?';
            $args  = [$bbox['max_lat'], $bbox['min_lat'], $bbox['max_lon'], $bbox['min_lon']];
        }

        $stmt = $pdo->prepare(
            "SELECT geom_json, route_count, avg_score, length_m, osm_surface
               FROM heatmap_edges {$where}
              ORDER BY route_count DESC
              LIMIT {$limit}"
        );
        $stmt->execute($args);

        $features = [];
        $maxCount = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $coords = json_decode((string)$row['geom_json'], true);
            if (!is_array($coords) || count($coords) < 2) {
                continue;
            }
            $count = (int)$row['route_count'];
            if ($count > $maxCount) {
                $maxCount = $count;
            }
            $features[] = [
                'type' => 'Feature',
                'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
                'properties' => [
                    'count'     => $count,
                    'avg_score' => $row['avg_score'] !== null ? (float)$row['avg_score'] : null,
                    'length_m'  => (int)$row['length_m'],
                    'surface'   => $row['osm_surface'],
                ],
            ];
        }

        return [
            'type'     => 'FeatureCollection',
            'features' => $features,
            'meta'     => [
                'edge_count' => count($features),
                'max_count'  => $maxCount,
            ],
        ];
    }

    // ---- pure Aggregation (testbar ohne DB/Valhalla) -----------------------

    /**
     * Aggregiert eine gematchte Route in den Akkumulator. `accumulate` zählt
     * pro Route+Kante genau einmal; der Ø-Score je Kante wird über die der
     * Kante zugeordneten Eingabepunkte gebildet.
     *
     * @param array<string,array<string,mixed>>          $acc    Akkumulator (by-ref)
     * @param list<array{lat:float,lon:float,score:?int}> $points gesendete Punkte (Reihenfolge wie matched_points)
     */
    public function accumulate(array &$acc, array $points, ?ValhallaMatch $match): void
    {
        if ($match === null || $match->edges === []) {
            return;
        }
        $edges = $match->edges;

        // Surface-Scores je Kanten-Index aus den matched_points sammeln.
        $scoresByIdx = [];
        foreach ($match->matchedPoints as $i => $mp) {
            if (($mp['type'] ?? '') === 'unmatched') {
                continue;
            }
            $e = (int)($mp['edgeIndex'] ?? -1);
            if ($e < 0 || !isset($edges[$e])) {
                continue;
            }
            $score = $points[$i]['score'] ?? null;
            if ($score !== null) {
                $scoresByIdx[$e][] = (int)$score;
            }
        }

        // Innerhalb der Route nach edge_key gruppieren (gleiche Kante zählt 1×).
        $routeEdges = [];
        foreach ($edges as $idx => $edge) {
            $key = EdgeKey::for($edge->wayId, $edge->geometry);
            if ($key === null) {
                continue;
            }
            if (!isset($routeEdges[$key])) {
                $routeEdges[$key] = ['edge' => $edge, 'scores' => []];
            }
            foreach (($scoresByIdx[$idx] ?? []) as $s) {
                $routeEdges[$key]['scores'][] = $s;
            }
        }

        foreach ($routeEdges as $key => $re) {
            /** @var ValhallaMatchedEdge $edge */
            $edge = $re['edge'];
            if (!isset($acc[$key])) {
                [$minLat, $minLon, $maxLat, $maxLon] = self::bbox($edge->geometry);
                $acc[$key] = [
                    'way_id'      => $edge->wayId,
                    'geom'        => $edge->geometry,
                    'min_lat'     => $minLat,
                    'min_lon'     => $minLon,
                    'max_lat'     => $maxLat,
                    'max_lon'     => $maxLon,
                    'length_m'    => (int)round($edge->lengthM),
                    'route_count' => 0,
                    'score_sum'   => 0.0,
                    'score_n'     => 0,
                    'osm_surface' => $edge->surface,
                ];
            }
            $acc[$key]['route_count']++;
            if ($re['scores'] !== []) {
                $acc[$key]['score_sum'] += array_sum($re['scores']) / count($re['scores']);
                $acc[$key]['score_n']++;
            }
            if ($acc[$key]['osm_surface'] === null && $edge->surface !== null) {
                $acc[$key]['osm_surface'] = $edge->surface;
            }
        }
    }

    /**
     * Schließt die Aggregation ab: minRoutes-Filter + Ø-Score.
     *
     * @param array<string,array<string,mixed>> $acc
     * @return list<array<string,mixed>>
     */
    public function finalize(array $acc): array
    {
        $rows = [];
        foreach ($acc as $key => $a) {
            if ((int)$a['route_count'] < $this->minRoutes) {
                continue;
            }
            $avg = ((int)$a['score_n'] > 0)
                ? round((float)$a['score_sum'] / (int)$a['score_n'], 2)
                : null;
            $rows[] = [
                'edge_key'    => $key,
                'way_id'      => $a['way_id'],
                'geom'        => $a['geom'],
                'min_lat'     => $a['min_lat'],
                'min_lon'     => $a['min_lon'],
                'max_lat'     => $a['max_lat'],
                'max_lon'     => $a['max_lon'],
                'length_m'    => $a['length_m'],
                'route_count' => (int)$a['route_count'],
                'score_sum'   => ((int)$a['score_n'] > 0) ? round((float)$a['score_sum'], 2) : null,
                'score_n'     => (int)$a['score_n'],
                'avg_score'   => $avg,
                'osm_surface' => $a['osm_surface'],
            ];
        }
        return $rows;
    }

    // ---- Helfer ------------------------------------------------------------

    /**
     * @return list<array{lat:float,lon:float,score:?int}>
     */
    private function extractPoints(string $payload): array
    {
        $pts = $this->surface->points($payload);
        if ($pts !== null) {
            return $pts;
        }
        // Kein GPX (z. B. GeoJSON) → Geometrie ohne Scores.
        $parsed = $this->parser->parse($payload);
        $out = [];
        foreach ($parsed->points as $p) {
            $out[] = ['lat' => $p->lat, 'lon' => $p->lon, 'score' => null];
        }
        return $out;
    }

    /**
     * Dünnt die Punktfolge aus: aufeinanderfolgende Punkte mindestens
     * `resampleM` Meter auseinander; erster/letzter Punkt bleiben erhalten.
     * Schützt vor sehr dichten Spuren und dem Valhalla-Punktelimit.
     *
     * @param list<array{lat:float,lon:float,score:?int}> $points
     * @return list<array{lat:float,lon:float,score:?int}>
     */
    private function downsample(array $points): array
    {
        $n = count($points);
        if ($n <= 2) {
            return $points;
        }
        $out = [$points[0]];
        $last = $points[0];
        for ($i = 1; $i < $n - 1; $i++) {
            if (self::haversine($last['lat'], $last['lon'], $points[$i]['lat'], $points[$i]['lon']) >= $this->resampleM) {
                $out[] = $points[$i];
                $last = $points[$i];
            }
        }
        $out[] = $points[$n - 1];

        // Hartes Limit: gleichmäßig weiter ausdünnen, falls noch zu viele.
        $m = count($out);
        if ($m > $this->maxPointsPerRequest) {
            $step = (int)ceil($m / $this->maxPointsPerRequest);
            $reduced = [];
            for ($i = 0; $i < $m; $i += $step) {
                $reduced[] = $out[$i];
            }
            if ($reduced[count($reduced) - 1] !== $out[$m - 1]) {
                $reduced[] = $out[$m - 1];
            }
            $out = $reduced;
        }
        return $out;
    }

    /**
     * @param list<array{0:float,1:float}> $geom
     * @return array{0:float,1:float,2:float,3:float} [minLat, minLon, maxLat, maxLon]
     */
    private static function bbox(array $geom): array
    {
        $minLat = 90.0; $minLon = 180.0; $maxLat = -90.0; $maxLon = -180.0;
        foreach ($geom as [$lon, $lat]) {
            $minLat = min($minLat, $lat); $maxLat = max($maxLat, $lat);
            $minLon = min($minLon, $lon); $maxLon = max($maxLon, $lon);
        }
        return [$minLat, $minLon, $maxLat, $maxLon];
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

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function write(array $rows): void
    {
        $pdo = Db::pdo();
        $now = Clock::nowUtcString();

        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM heatmap_edges');

            if ($rows !== []) {
                $sql = 'INSERT INTO heatmap_edges
                    (edge_key, way_id, geom_json, min_lat, min_lon, max_lat, max_lon,
                     length_m, route_count, score_sum, score_n, avg_score, osm_surface, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                $stmt = $pdo->prepare($sql);
                foreach ($rows as $r) {
                    $stmt->execute([
                        $r['edge_key'],
                        $r['way_id'],
                        json_encode($r['geom'], JSON_THROW_ON_ERROR),
                        $r['min_lat'], $r['min_lon'], $r['max_lat'], $r['max_lon'],
                        $r['length_m'],
                        $r['route_count'],
                        $r['score_sum'],
                        $r['score_n'],
                        $r['avg_score'],
                        $r['osm_surface'],
                        $now,
                    ]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
