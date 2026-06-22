<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use App\Privacy\PrivacyZone;
use App\Privacy\PrivacyZoneService;

/**
 * HTTP-Adapter für /me/privacy-zone (PRIVACY_ZONE_BACKEND.md).
 * Reine Account-Operation: alle Endpunkte erfordern Bearer (nur eigener
 * Account). lat/lon werden ausschließlich an den Besitzer zurückgegeben.
 */
final class PrivacyZoneController
{
    public function __construct(private readonly PrivacyZoneService $zones) {}

    public function show(Request $req): void
    {
        $uid = $this->userId($req);
        $zone = $this->zones->get($uid);
        Response::json(['zone' => $zone === null ? null : $this->shape($zone)]);
    }

    public function put(Request $req): void
    {
        $uid = $this->userId($req);

        $lat = $this->num($req->input('lat'));
        $lon = $this->num($req->input('lon'));
        $errors = [];
        if ($lat === null || $lat < -90.0 || $lat > 90.0) {
            $errors['lat'] = ['Breitengrad zwischen -90 und 90 erforderlich.'];
        }
        if ($lon === null || $lon < -180.0 || $lon > 180.0) {
            $errors['lon'] = ['Längengrad zwischen -180 und 180 erforderlich.'];
        }
        if ($errors !== []) {
            Response::error('validation_error', 'Bitte überprüfe deine Eingaben.', 422, $errors);
        }

        // radius_m optional (Default 500); server-seitiges Clamping im Service.
        $radiusRaw = $req->input('radius_m');
        $radiusM = $radiusRaw === null || $radiusRaw === ''
            ? PrivacyZone::RADIUS_DEFAULT_M
            : (int)$radiusRaw;
        // enabled optional, Default true.
        $enabled = $req->input('enabled');
        $enabled = $enabled === null ? true : self::asBool($enabled);

        $saved = $this->zones->put($uid, (float)$lat, (float)$lon, $radiusM, $enabled);
        Response::json(['zone' => $this->shape($saved)]);
    }

    public function delete(Request $req): void
    {
        $uid = $this->userId($req);
        $this->zones->delete($uid);
        Response::noContent();
    }

    /** @param array{lat:float,lon:float,radius_m:int,enabled:bool} $z */
    private function shape(array $z): array
    {
        return [
            'lat'      => $z['lat'],
            'lon'      => $z['lon'],
            'radius_m' => $z['radius_m'],
            'enabled'  => $z['enabled'],
        ];
    }

    private function num(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float)$v;
        }
        if (is_string($v) && is_numeric(trim($v))) {
            return (float)$v;
        }
        return null;
    }

    private static function asBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v === 1;
        }
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    private function userId(Request $req): int
    {
        $u = $req->user;
        $uid = $u !== null ? (int)($u->internal_id ?? 0) : 0;
        if ($uid <= 0) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }
        return $uid;
    }
}
