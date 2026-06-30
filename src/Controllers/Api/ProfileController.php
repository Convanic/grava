<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Discovery\ProfileService;
use App\Game\Admin\AdminGuard;
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
    public function __construct(
        private readonly ProfileService $profile,
        // Optional: erkennt Admins (E-Mail in ADMIN_EMAILS), die auch
        // nicht-öffentliche Routen eines Profils sehen dürfen. Nullable,
        // damit bestehende Aufrufer/Tests ohne Guard konstruieren können.
        private readonly ?AdminGuard $adminGuard = null,
    ) {}

    public function show(Request $req): void
    {
        $handle = (string)($req->routeParams['handle'] ?? '');
        $viewerId = $this->viewerId($req);

        $profile = $this->profile->getProfile($handle, $viewerId);
        if ($profile === null) {
            Response::error('not_found', 'Profil existiert nicht.', 404);
        }
        Response::json(['user' => $profile]);
    }

    public function routes(Request $req): void
    {
        $handle = (string)($req->routeParams['handle'] ?? '');
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

        $res = $this->profile->getProfileRoutes($handle, $viewerId, $filters, $this->viewerIsAdmin($req));
        if ($res === null) {
            Response::error('not_found', 'Profil existiert nicht.', 404);
        }
        Response::json($res);
    }

    /**
     * Personensuche: GET /api/v1/users/search?q=&limit=&offset=
     *
     * Anonym OK (OptionalBearer ergänzt nur is_self/is_followed_by_viewer).
     * Antwortform deckungsgleich mit /followers/following (UserListEnvelope).
     * Leeres/zu kurzes q → leere Liste (kein 4xx), Mindestlänge prüft der
     * Service.
     */
    public function search(Request $req): void
    {
        $viewerId = $this->viewerId($req);

        $filters = [
            'limit'  => (int)($req->query['limit']  ?? 30),
            'offset' => (int)($req->query['offset'] ?? 0),
        ];
        $q = $req->query['q'] ?? null;
        if (is_string($q) && trim($q) !== '') {
            // Längen-Cap, damit kein 100k-Pattern reingejagt wird (analog Discovery).
            $filters['q'] = substr(trim($q), 0, 100);
        }

        Response::json($this->profile->searchProfiles($viewerId, $filters));
    }

    public function followers(Request $req): void
    {
        $this->followList($req, 'followers');
    }

    public function following(Request $req): void
    {
        $this->followList($req, 'following');
    }

    private function followList(Request $req, string $direction): void
    {
        $handle = (string)($req->routeParams['handle'] ?? '');
        $viewerId = $this->viewerId($req);

        $res = $this->profile->getProfileFollowList($handle, $viewerId, $direction, [
            'limit'  => (int)($req->query['limit']  ?? 50),
            'offset' => (int)($req->query['offset'] ?? 0),
        ]);
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

    /**
     * True, wenn der eingeloggte Viewer Admin ist (E-Mail in ADMIN_EMAILS).
     * Anonyme Viewer oder fehlender Guard → false.
     */
    private function viewerIsAdmin(Request $req): bool
    {
        if ($this->adminGuard === null || !isset($req->user)) {
            return false;
        }
        return $this->adminGuard->isAdminEmail((string)($req->user->email ?? ''));
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
