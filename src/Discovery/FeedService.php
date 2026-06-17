<?php
declare(strict_types=1);

namespace App\Discovery;

use App\Routes\RouteRepository;

/**
 * M3 Phase 5: Activity-Feed.
 *
 * Liefert public Routen aller User, denen der Viewer folgt —
 * chronologisch absteigend nach `routes.created_at`. Block-
 * Verhältnisse werden über DiscoveryService::blockedUserIds
 * herausgefiltert (theoretisch sollte der Viewer keinen Follow
 * zu einem geblockten User haben, aber Defense in Depth schadet
 * nicht).
 *
 * Was M3 hier bewusst NICHT bietet:
 *  - Cursor-based Pagination — wir starten mit offset/limit, weil
 *    Feeds mit < 1000 Items pro User in den ersten Monaten
 *    realistisch sind. Cursor (z. B. „last seen route_id") ist
 *    eine M4-Optimierung, falls der Feed-Endpoint zum Hot-Path wird.
 *  - Activity-Events außer „neue Route" — Follow/Unfollow,
 *    Patches, Tag-Änderungen werden NICHT gespiegelt. Das ist die
 *    bewusste M3-§9-Einschränkung.
 */
final class FeedService
{
    public function __construct(
        private readonly RouteRepository $routes,
        private readonly DiscoveryService $discovery,
    ) {}

    /**
     * @return array{
     *     routes: list<array<string,mixed>>,
     *     pagination: array{limit:int, offset:int, total:int, has_more:bool}
     * }
     */
    public function getFeed(int $viewerUserId, int $limit = 20, int $offset = 0): array
    {
        $limit  = max(1, min(50, $limit));
        $offset = max(0, $offset);

        $excluded = $this->discovery->blockedUserIds($viewerUserId);
        $res = $this->routes->feedFor($viewerUserId, $excluded, $limit, $offset);

        // _internal-Felder rausfiltern für Public-Form
        $clean = [];
        foreach ($res['routes'] as $r) {
            unset($r['_internal']);
            $clean[] = $r;
        }
        return [
            'routes'     => $clean,
            'pagination' => [
                'limit'    => $limit,
                'offset'   => $offset,
                'total'    => $res['total'],
                'has_more' => ($offset + $limit) < $res['total'],
            ],
        ];
    }
}
