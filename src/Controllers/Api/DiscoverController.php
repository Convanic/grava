<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Discovery\DiscoveryService;
use App\Http\Request;
use App\Http\Response;

/**
 * M3 Phase 2: GET /discover/routes + /discover/users.
 *
 * Beide Endpoints sind anonym OK (OptionalBearer-Middleware setzt
 * `request->user` nur, wenn ein gültiger Token mitgekommen ist).
 * Alle Filter sind optional; ungültige BBox-/Distance-Werte
 * werden als 422 zurückgewiesen.
 */
final class DiscoverController
{
    public function __construct(private readonly DiscoveryService $discovery) {}

    public function routes(Request $req): void
    {
        $filters = [
            'limit'  => $this->intParam($req, 'limit',  20),
            'offset' => $this->intParam($req, 'offset', 0),
            'sort'   => $this->sortParam($req),
        ];

        $bbox = $this->bboxParam($req);
        if ($bbox !== null) {
            $filters['bbox'] = $bbox;
        }

        $tags = $req->query['tag'] ?? null;
        if ($tags !== null) {
            // ?tag=foo&tag=bar landet in PHP-Requests als Array; ein
            // einziges ?tag=foo wäre String. Beides normalisieren.
            $tags = is_array($tags) ? $tags : [$tags];
            $clean = [];
            foreach ($tags as $t) {
                if (!is_string($t)) { continue; }
                $t = trim(strtolower($t));
                if ($t !== '' && preg_match('/^[a-z0-9-]{1,32}$/', $t) === 1) {
                    $clean[] = $t;
                }
            }
            if ($clean !== []) {
                $filters['tags'] = array_values(array_unique($clean));
            }
        }

        $minKm = $this->floatParam($req, 'min_distance_km');
        $maxKm = $this->floatParam($req, 'max_distance_km');
        if ($minKm !== null) { $filters['min_distance_m'] = (int)round($minKm * 1000); }
        if ($maxKm !== null) { $filters['max_distance_m'] = (int)round($maxKm * 1000); }

        $q = $req->query['q'] ?? null;
        if (is_string($q) && trim($q) !== '') {
            // Längen-Cap, damit ein Bot nicht ein 100k-Pattern reinjagt.
            $filters['q'] = substr(trim($q), 0, 100);
        }

        $viewerId = isset($req->user) ? (int)($req->user->internal_id ?? 0) : null;
        if ($viewerId === 0) { $viewerId = null; }

        $result = $this->discovery->searchRoutes($filters, $viewerId);

        // owner-Mapping war schon im Repo eingebaut; hier filtern wir
        // nur internal-Felder raus, damit der Public-Response sauber
        // bleibt.
        $clean = [];
        foreach ($result['routes'] as $r) {
            unset($r['_internal']);
            $clean[] = $r;
        }
        Response::json([
            'routes'     => $clean,
            'pagination' => $result['pagination'],
        ]);
    }

    public function users(Request $req): void
    {
        $filters = [
            'limit'  => $this->intParam($req, 'limit',  20),
            'offset' => $this->intParam($req, 'offset', 0),
        ];
        $q = $req->query['q'] ?? null;
        if (is_string($q) && trim($q) !== '') {
            $filters['q'] = substr(trim($q), 0, 100);
        }

        $viewerId = isset($req->user) ? (int)($req->user->internal_id ?? 0) : null;
        if ($viewerId === 0) { $viewerId = null; }

        Response::json($this->discovery->searchUsers($filters, $viewerId));
    }

    // ─── helpers ──────────────────────────────────────────────────

    private function intParam(Request $req, string $key, int $default): int
    {
        $v = $req->query[$key] ?? null;
        if ($v === null || $v === '') { return $default; }
        return (int)$v;
    }

    private function floatParam(Request $req, string $key): ?float
    {
        $v = $req->query[$key] ?? null;
        if ($v === null || $v === '') { return null; }
        if (!is_numeric($v)) {
            Response::error('validation_error', "{$key} muss eine Zahl sein.", 422);
        }
        return (float)$v;
    }

    private function sortParam(Request $req): string
    {
        $v = (string)($req->query['sort'] ?? 'newest');
        $allowed = ['newest', 'oldest', 'distance_asc', 'distance_desc'];
        if (!in_array($v, $allowed, true)) {
            Response::error('validation_error', 'sort muss einer von newest, oldest, distance_asc, distance_desc sein.', 422);
        }
        return $v;
    }

    /**
     * Format: `bbox=minLat,minLon,maxLat,maxLon`. Liefert ein Array
     * oder null bei nicht gesetztem Filter; 422 bei ungültigem Wert.
     *
     * @return array{min_lat: float, min_lon: float, max_lat: float, max_lon: float}|null
     */
    private function bboxParam(Request $req): ?array
    {
        $raw = $req->query['bbox'] ?? null;
        if ($raw === null || $raw === '') { return null; }
        if (!is_string($raw)) {
            Response::error('validation_error', 'bbox muss ein String sein.', 422);
        }
        $parts = explode(',', (string)$raw);
        if (count($parts) !== 4) {
            Response::error('validation_error', 'bbox muss 4 Werte enthalten: minLat,minLon,maxLat,maxLon.', 422);
        }
        foreach ($parts as $p) {
            if (!is_numeric(trim($p))) {
                Response::error('validation_error', 'bbox-Werte müssen numerisch sein.', 422);
            }
        }
        $minLat = (float)$parts[0];
        $minLon = (float)$parts[1];
        $maxLat = (float)$parts[2];
        $maxLon = (float)$parts[3];
        if ($minLat < -90 || $minLat > 90 || $maxLat < -90 || $maxLat > 90) {
            Response::error('validation_error', 'bbox lat muss zwischen -90 und 90 liegen.', 422);
        }
        if ($minLon < -180 || $minLon > 180 || $maxLon < -180 || $maxLon > 180) {
            Response::error('validation_error', 'bbox lon muss zwischen -180 und 180 liegen.', 422);
        }
        if ($minLat > $maxLat || $minLon > $maxLon) {
            Response::error('validation_error', 'bbox: min muss <= max sein.', 422);
        }
        return [
            'min_lat' => $minLat,
            'min_lon' => $minLon,
            'max_lat' => $maxLat,
            'max_lon' => $maxLon,
        ];
    }
}
