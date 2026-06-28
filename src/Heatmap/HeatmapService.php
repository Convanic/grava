<?php
declare(strict_types=1);

namespace App\Heatmap;

use App\Database\Db;
use App\Support\Clock;
use App\Support\MapLod;
use PDO;

/**
 * M4f: Crowd-Heatmap über die Centroids aller public Routen.
 *
 * `rebuild()` ist ein voller Neuaufbau (TRUNCATE + INSERT…SELECT) —
 * für die erwarteten Volumina (ein paar tausend Routen) völlig
 * ausreichend und garantiert konsistente Zellen ohne Drift. Läuft im
 * cron:cleanup und über das CLI-Kommando `cron:heatmap`.
 *
 * `query()` liefert eine GeoJSON-FeatureCollection von Punkt-Features
 * mit `weight`, optional auf eine BBox eingeschränkt.
 *
 * Privacy: nur visibility='public' fließt ein. Die Aggregation ist
 * anonym (keine User-Zuordnung), daher kein Block-/Follower-Filter.
 */
final class HeatmapService
{
    /** Grid-Auflösung in Grad (~5.5 km bei 0.05). */
    public const GRID = 0.05;

    /**
     * Baut die heatmap_cells aus den public Routen neu auf.
     * Liefert die Anzahl erzeugter Zellen.
     */
    public function rebuild(): int
    {
        $pdo = Db::pdo();
        $grid = self::GRID;
        $now  = Clock::nowUtcString();

        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM heatmap_cells');

            // ST_Latitude/ST_Longitude sind SRS-bewusst (Centroid ist
            // POINT(lon,lat) mit SRID 4326). Wir runden auf das Grid und
            // gruppieren. cell_key wird aus den gerundeten Werten gebaut.
            //
            // Privacy (§17): Routen, deren Centroid in der eigenen Privatzone
            // des Eigentümers liegt, fließen NICHT in die öffentliche Heatmap
            // ein (LEFT JOIN + Haversine-Ausschluss). Distanz in Metern.
            $sql = "
                INSERT INTO heatmap_cells (cell_key, lat, lon, weight, updated_at)
                SELECT CONCAT(blat, ':', blon) AS cell_key, blat, blon, COUNT(*) AS weight, ?
                FROM (
                    SELECT
                        ROUND(ST_Latitude(r.centroid)  / {$grid}) * {$grid} AS blat,
                        ROUND(ST_Longitude(r.centroid) / {$grid}) * {$grid} AS blon
                    FROM routes r
                    LEFT JOIN user_privacy_zone z
                           ON z.user_id = r.user_id AND z.enabled = 1
                    WHERE r.visibility = 'public' AND r.deleted_at IS NULL
                      AND (
                        z.user_id IS NULL
                        OR (6371000 * 2 * ASIN(SQRT(
                              POW(SIN(RADIANS(ST_Latitude(r.centroid) - z.lat) / 2), 2)
                            + COS(RADIANS(z.lat)) * COS(RADIANS(ST_Latitude(r.centroid)))
                              * POW(SIN(RADIANS(ST_Longitude(r.centroid) - z.lon) / 2), 2)
                           ))) > z.radius_m
                      )
                ) t
                GROUP BY blat, blon
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$now]);
            $count = $stmt->rowCount();

            $pdo->commit();
            return $count;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array{min_lat:float,min_lon:float,max_lat:float,max_lon:float}|null $bbox
     * @param float|null $gridOverride Optionale gröbere Gitterweite (Grad): die
     *        Basiszellen ({@see GRID}) werden serverseitig in dieses Gitter
     *        aggregiert (Summe je Zelle) — deckungsgleich zur Client-Logik
     *        ({@see MapLod::clusterHeat}). Wird auf ≥ {@see GRID} geklemmt
     *        (feiner als die Quelle ist nicht möglich). `null` → unverändert.
     * @return array<string,mixed> GeoJSON FeatureCollection
     */
    public function query(?array $bbox, int $limit = 5000, ?float $gridOverride = null): array
    {
        $limit = max(1, min(20000, $limit));
        $pdo = Db::pdo();

        $where = '';
        $args  = [];
        if ($bbox !== null) {
            $where = 'WHERE lat BETWEEN ? AND ? AND lon BETWEEN ? AND ?';
            $args  = [$bbox['min_lat'], $bbox['max_lat'], $bbox['min_lon'], $bbox['max_lon']];
        }

        $stmt = $pdo->prepare(
            "SELECT lat, lon, weight FROM heatmap_cells {$where}
              ORDER BY weight DESC
              LIMIT {$limit}"
        );
        $stmt->execute($args);

        $points = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $points[] = ['lon' => (float)$row['lon'], 'lat' => (float)$row['lat'], 'weight' => (int)$row['weight']];
        }

        return self::buildResponse($points, self::GRID, $gridOverride);
    }

    /**
     * Baut die FeatureCollection aus gewichteten Punkten und aggregiert sie
     * optional in ein gröberes Gitter. Geteilt mit der persönlichen Heatmap.
     *
     * @param list<array{lon:float,lat:float,weight:int}> $points
     * @return array<string,mixed>
     */
    public static function buildResponse(array $points, float $baseGrid, ?float $gridOverride): array
    {
        $grid = $baseGrid;
        if ($gridOverride !== null && $gridOverride > $baseGrid) {
            $grid = $gridOverride;
            $clustered = MapLod::clusterHeat($points, $grid);
            $points = $clustered['cells'];
        }

        $features = [];
        $maxWeight = 0;
        foreach ($points as $p) {
            $w = (int)$p['weight'];
            if ($w > $maxWeight) {
                $maxWeight = $w;
            }
            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [round((float)$p['lon'], 6), round((float)$p['lat'], 6)],
                ],
                'properties' => ['weight' => $w],
            ];
        }

        return [
            'type'     => 'FeatureCollection',
            'features' => $features,
            'meta'     => [
                'grid'       => $grid,
                'cell_count' => count($features),
                'max_weight' => $maxWeight,
            ],
        ];
    }
}
