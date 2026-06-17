<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Discovery\ProfileService;
use App\Http\Request;
use App\Http\Response;

/**
 * M3 Phase 3: GET /api/v1/users/by-handle/{handle}(/routes).
 *
 * Beide Endpoints anonym OK; OptionalBearer-Middleware setzt
 * `request->user` nur bei gültigem Token. Block-Verhältnisse
 * (Viewer↔Owner) führen zu 404 — kein Profil-Probing.
 */
final class ProfileController
{
    public function __construct(private readonly ProfileService $profile) {}

    public function show(Request $req): void
    {
        $handle = (string)($req->route['handle'] ?? '');
        $viewerId = $this->viewerId($req);

        $profile = $this->profile->getProfile($handle, $viewerId);
        if ($profile === null) {
            Response::error('not_found', 'Profil existiert nicht.', 404);
        }
        Response::json(['user' => $profile]);
    }

    public function routes(Request $req): void
    {
        $handle = (string)($req->route['handle'] ?? '');
        $viewerId = $this->viewerId($req);

        $filters = [
            'limit'  => (int)($req->query['limit']  ?? 20),
            'offset' => (int)($req->query['offset'] ?? 0),
            'sort'   => $this->sortParam((string)($req->query['sort'] ?? 'newest')),
        ];
        $q = $req->query['q'] ?? null;
        if (is_string($q) && trim($q) !== '') {
            $filters['q'] = substr(trim($q), 0, 100);
        }

        $res = $this->profile->getProfileRoutes($handle, $viewerId, $filters);
        if ($res === null) {
            Response::error('not_found', 'Profil existiert nicht.', 404);
        }
        Response::json($res);
    }

    private function viewerId(Request $req): ?int
    {
        $id = isset($req->user) ? (int)($req->user->internal_id ?? 0) : 0;
        return $id > 0 ? $id : null;
    }

    private function sortParam(string $v): string
    {
        $allowed = ['newest', 'oldest', 'distance_asc', 'distance_desc'];
        if (!in_array($v, $allowed, true)) {
            Response::error('validation_error', 'sort muss einer von newest, oldest, distance_asc, distance_desc sein.', 422);
        }
        return $v;
    }
}
