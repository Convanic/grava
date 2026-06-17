<?php
declare(strict_types=1);

namespace App\Engagement;

use App\Database\Db;

/**
 * M4: zentrale Sichtbarkeits-Auflösung für Engagement-Interaktionen.
 *
 * Likes und Comments dürfen nur auf Routen entstehen, die der Viewer
 * tatsächlich sehen darf. Diese Regeln spiegeln die M2/M3-Privacy:
 *
 *  - Route muss existieren und darf nicht soft-deleted sein.
 *  - `public` ist für jeden sichtbar; `private`/`unlisted` nur für
 *    den Owner selbst.
 *  - Bidirektionale Blocks (Owner blockt Viewer ODER umgekehrt)
 *    machen die Route unsichtbar — wir liefern 404, nie 403, damit
 *    aus dem Status-Code keine Block-Beziehung ablesbar ist.
 *
 * Liefert bei Erfolg `{route_id, owner_id, visibility}` (interne IDs),
 * sonst wirft es EngagementException(404).
 */
final class RouteVisibility
{
    /**
     * @return array{route_id:int, owner_id:int, visibility:string}
     */
    public static function resolveVisibleOrThrow(string $publicId, ?int $viewerUserId): array
    {
        if ($publicId === '') {
            throw new EngagementException('not_found', 'Route existiert nicht.', 404);
        }
        $stmt = Db::pdo()->prepare(
            'SELECT id, user_id, visibility
               FROM routes
              WHERE public_id = ? AND deleted_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$publicId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new EngagementException('not_found', 'Route existiert nicht.', 404);
        }

        $routeId = (int)$row['id'];
        $ownerId = (int)$row['user_id'];
        $vis     = (string)$row['visibility'];

        // Nicht-public Routen sind nur für den Owner sichtbar.
        if ($vis !== 'public' && $viewerUserId !== $ownerId) {
            throw new EngagementException('not_found', 'Route existiert nicht.', 404);
        }

        // Bidirektionaler Block (nur relevant, wenn ein eingeloggter
        // Viewer != Owner agiert).
        if ($viewerUserId !== null && $viewerUserId !== $ownerId
            && self::isBlockedEither($viewerUserId, $ownerId)) {
            throw new EngagementException('not_found', 'Route existiert nicht.', 404);
        }

        return ['route_id' => $routeId, 'owner_id' => $ownerId, 'visibility' => $vis];
    }

    public static function isBlockedEither(int $a, int $b): bool
    {
        $stmt = Db::pdo()->prepare(
            'SELECT 1 FROM user_blocks
              WHERE (blocker_id = ? AND blocked_id = ?)
                 OR (blocker_id = ? AND blocked_id = ?)
              LIMIT 1'
        );
        $stmt->execute([$a, $b, $b, $a]);
        return (bool)$stmt->fetchColumn();
    }
}
