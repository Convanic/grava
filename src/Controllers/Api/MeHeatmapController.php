<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Heatmap\PersonalHeatmapService;
use App\Http\Request;
use App\Http\Response;

/**
 * GET /api/v1/me/heatmap?bbox=minLon,minLat,maxLon,maxLat
 *
 * Persönliche Heatmap der eigenen Routen (inkl. Strava-Importe). Erfordert
 * Bearer-Auth (eigene Daten). Gleiche JSON-Form wie GET /api/v1/heatmap.
 */
final class MeHeatmapController
{
    public function __construct(private readonly PersonalHeatmapService $heatmap) {}

    public function me(Request $req): void
    {
        $u = $req->user;
        $userId = $u !== null ? (int)($u->internal_id ?? 0) : 0;
        if ($userId <= 0) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }

        $bbox = null;
        $raw = $req->query['bbox'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $bbox = self::parseBbox($raw);
            if ($bbox === null) {
                Response::error('validation_error',
                    'bbox muss "minLon,minLat,maxLon,maxLat" mit gültigen Koordinaten sein.', 422);
            }
        }

        Response::json($this->heatmap->queryForUser($userId, $bbox));
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
