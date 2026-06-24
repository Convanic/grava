<?php
declare(strict_types=1);

namespace App\Heatmap;

use App\Database\Db;
use App\Privacy\PrivacyZoneRepository;
use App\Routes\GeometryParser;
use App\Routes\RouteStorage;
use PDO;
use Throwable;

/**
 * Persönliche Heatmap (GET /me/heatmap): aggregiert NUR die eigenen Routen des
 * Nutzers (inkl. Strava-/GPX-Importe, alle Sichtbarkeiten) live aus den
 * Track-Geometrien zu gewichteten Gitter-Punkten.
 *
 * Unterschiede zur Community-Heatmap ({@see HeatmapService}):
 *  - Quelle: die TATSÄCHLICHEN Streckenpunkte des Nutzers (nicht nur der
 *    Routen-Centroid) → bildet die befahrenen Wege ab.
 *  - Feines Gitter (~0.0035°, ~390 m) statt 0.05°.
 *  - KEINE k-Anonymitäts-/Mindest-Schwelle (es sind die eigenen Daten).
 *  - Gewicht = Anzahl EIGENER Routen, die die Zelle berühren (distinct je Route).
 *  - Privatzone des Nutzers wird respektiert (Punkte in der Zone fallen raus).
 *
 * JSON-Form identisch zur Community-Heatmap (FeatureCollection aus Point-
 * Features mit `weight` + `meta`).
 */
final class PersonalHeatmapService
{
    /** Feines Gitter in Grad (~390 m). */
    public const GRID = 0.0035;

    public function __construct(
        private readonly RouteStorage $storage,
        private readonly GeometryParser $parser,
        private readonly PrivacyZoneRepository $zones,
    ) {}

    /**
     * @param array{min_lat:float,min_lon:float,max_lat:float,max_lon:float}|null $bbox
     * @return array<string,mixed> GeoJSON FeatureCollection (gleiche Form wie HeatmapService)
     */
    public function queryForUser(int $userId, ?array $bbox, int $limit = 20000): array
    {
        $limit = max(1, min(20000, $limit));
        $grid  = self::GRID;
        $zone  = $this->zones->enabledZoneForUser($userId);

        $stmt = Db::pdo()->prepare(
            'SELECT v.payload_path, v.format
               FROM routes r
               JOIN route_versions v ON v.id = r.head_version_id
              WHERE r.user_id = ? AND r.deleted_at IS NULL'
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // cell_key => ['ilat'=>int,'ilon'=>int,'weight'=>int]
        $cells = [];
        foreach ($rows as $row) {
            try {
                $payload = $this->storage->load((string)$row['payload_path']);
                $parsed  = $this->parser->parse($payload);
            } catch (Throwable) {
                // Fehlende/kaputte Datei überspringen — eine Route darf die
                // gesamte Heatmap nicht kippen.
                continue;
            }

            // Pro Route nur DISTINKTE Zellen zählen → Gewicht = Anzahl Routen,
            // die die Zelle berühren (nicht GPS-Punkt-Dichte).
            $seen = [];
            foreach ($parsed->points as $p) {
                $lat = $p->lat;
                $lon = $p->lon;
                if ($bbox !== null && (
                        $lat < $bbox['min_lat'] || $lat > $bbox['max_lat']
                     || $lon < $bbox['min_lon'] || $lon > $bbox['max_lon'])) {
                    continue;
                }
                if ($zone !== null && $zone->containsPoint($lat, $lon)) {
                    continue;
                }
                $ilat = (int)round($lat / $grid);
                $ilon = (int)round($lon / $grid);
                $seen[$ilat . ':' . $ilon] = [$ilat, $ilon];
            }

            foreach ($seen as $key => [$ilat, $ilon]) {
                if (!isset($cells[$key])) {
                    $cells[$key] = ['ilat' => $ilat, 'ilon' => $ilon, 'weight' => 0];
                }
                $cells[$key]['weight']++;
            }
        }

        // Stärkste Zellen zuerst, auf das Limit kappen.
        usort($cells, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
        $cells = array_slice($cells, 0, $limit);

        $features = [];
        $maxWeight = 0;
        foreach ($cells as $c) {
            $w = (int)$c['weight'];
            if ($w > $maxWeight) {
                $maxWeight = $w;
            }
            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [
                        round($c['ilon'] * $grid, 6),
                        round($c['ilat'] * $grid, 6),
                    ],
                ],
                'properties' => ['weight' => $w],
            ];
        }

        return [
            'type'     => 'FeatureCollection',
            'features' => $features,
            'meta'     => [
                'grid'       => self::GRID,
                'cell_count' => count($features),
                'max_weight' => $maxWeight,
            ],
        ];
    }
}
