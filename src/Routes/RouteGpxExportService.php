<?php
declare(strict_types=1);

namespace App\Routes;

use App\Privacy\PrivacyZone;
use App\Privacy\PrivacyZoneRepository;
use App\Privacy\RoutePrivacyTrimmer;
use App\Routes\RouteNotFoundException;

/**
 * Baut ein privatzonen-bereinigtes GPX aus einer Cloud-Route für den
 * Strava-Upload (STRAVA_SHARE_BACKEND.md §3.2). Die Heimatzone des
 * Eigentümers wird immer getrimmt — auch beim Upload durch den Eigentümer
 * selbst (Start/Ende in der Zone werden gekürzt).
 */
final class RouteGpxExportService
{
    public function __construct(
        private readonly RouteService $routes,
        private readonly RouteGeoJson $geo,
        private readonly RoutePrivacyTrimmer $trimmer,
        private readonly PrivacyZoneRepository $privacyZones,
    ) {}

    /**
     * @return array{gpx:string, title:string, visibility:string}
     */
    public function exportForStrava(int $userId, string $publicId): array
    {
        $route = $this->routes->get($userId, $publicId);
        if ($route === null) {
            throw new RouteNotFoundException();
        }

        $loaded = $this->routes->loadPayload($userId, $publicId, null);
        $fc = $this->geo->toFeatureCollection($loaded['payload']);

        $zoneRow = $this->privacyZones->find($userId);
        if ($zoneRow !== null && $zoneRow['enabled']) {
            $fc = $this->trimmer->trim($fc, new PrivacyZone(
                $zoneRow['lat'],
                $zoneRow['lon'],
                $zoneRow['radius_m'],
            ));
        }

        $points = self::collectPoints($fc);
        if (count($points) < 2) {
            throw new RouteExportException('Diese Route hat keinen Track zum Hochladen.');
        }

        return [
            'gpx'        => RouteGpxBuilder::build($points, (string)$route['title']),
            'title'      => (string)$route['title'],
            'visibility' => (string)$route['visibility'],
        ];
    }

    /**
     * @param array<string,mixed> $fc
     * @return list<array{lat:float,lon:float,ele:?float}>
     */
    private static function collectPoints(array $fc): array
    {
        $out = [];
        foreach ($fc['features'] ?? [] as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            $geom = $feature['geometry'] ?? null;
            if (!is_array($geom) || ($geom['type'] ?? null) !== 'LineString') {
                continue;
            }
            foreach ($geom['coordinates'] ?? [] as $c) {
                if (!is_array($c) || count($c) < 2) {
                    continue;
                }
                $ele = isset($c[2]) ? (float)$c[2] : null;
                $out[] = ['lon' => (float)$c[0], 'lat' => (float)$c[1], 'ele' => $ele];
            }
        }
        return $out;
    }
}
