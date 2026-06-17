<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Heatmap\HeatmapService;
use App\Http\Request;
use App\Http\Response;

/**
 * M4f: GET /api/v1/heatmap?bbox=minLon,minLat,maxLon,maxLat
 *
 * Public (kein Auth). Liefert eine GeoJSON-FeatureCollection von
 * Punkt-Features mit `weight`. bbox ist optional; ungültige bbox →
 * 422.
 */
final class HeatmapController
{
    public function __construct(private readonly HeatmapService $heatmap) {}

    public function index(Request $req): void
    {
        $bbox = null;
        $raw = $req->query['bbox'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $bbox = self::parseBbox($raw);
            if ($bbox === null) {
                Response::error('validation_error',
                    'bbox muss "minLon,minLat,maxLon,maxLat" mit gültigen Koordinaten sein.', 422);
            }
        }
        Response::json($this->heatmap->query($bbox));
    }

    /**
     * @return array{min_lat:float,min_lon:float,max_lat:float,max_lon:float}|null
     */
    private static function parseBbox(string $raw): ?array
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
        return [
            'min_lat' => $minLat, 'min_lon' => $minLon,
            'max_lat' => $maxLat, 'max_lon' => $maxLon,
        ];
    }
}
