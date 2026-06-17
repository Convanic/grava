<?php
declare(strict_types=1);

namespace App\Engagement;

use App\Database\Db;
use PDO;

/**
 * M4a: Likes auf Routen.
 *
 * Idempotent (INSERT … ON DUPLICATE = no-op), block-/privacy-aware
 * über {@see RouteVisibility}. Kein denormalisierter Counter; der
 * Like-Count kommt per COUNT(*) über idx_route_likes_route.
 *
 * Self-Like (Owner liked eigene Route) ist erlaubt — wir verbieten
 * es nicht, filtern es aber in M4c aus den Notifications.
 */
final class LikeService
{
    /**
     * @return bool true = neu angelegt, false = war schon geliked
     */
    public function like(string $routePublicId, int $viewerUserId): bool
    {
        $route = RouteVisibility::resolveVisibleOrThrow($routePublicId, $viewerUserId);

        try {
            Db::pdo()->prepare(
                'INSERT INTO route_likes (user_id, route_id) VALUES (?, ?)'
            )->execute([$viewerUserId, $route['route_id']]);
            return true;
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                return false; // bereits geliked → idempotent
            }
            throw $e;
        }
    }

    public function unlike(string $routePublicId, int $viewerUserId): void
    {
        // Sichtbarkeit prüfen wir auch beim Unlike — wer die Route nicht
        // (mehr) sehen darf, bekommt 404 statt eines stillen No-ops.
        $route = RouteVisibility::resolveVisibleOrThrow($routePublicId, $viewerUserId);
        Db::pdo()->prepare(
            'DELETE FROM route_likes WHERE user_id = ? AND route_id = ?'
        )->execute([$viewerUserId, $route['route_id']]);
    }

    /**
     * Like-Summary für eine Route: Gesamtzahl, ob der Viewer geliked
     * hat, und die letzten N Liker-Handles (nur solche mit Handle).
     *
     * @return array{count:int, liked_by_viewer:bool, recent:list<string>}
     */
    public function summary(string $routePublicId, ?int $viewerUserId, int $recentLimit = 5): array
    {
        $route = RouteVisibility::resolveVisibleOrThrow($routePublicId, $viewerUserId);
        $routeId = $route['route_id'];
        $pdo = Db::pdo();

        $count = (int)$pdo->query(
            "SELECT COUNT(*) FROM route_likes WHERE route_id = {$routeId}"
        )->fetchColumn();

        $likedByViewer = false;
        if ($viewerUserId !== null) {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM route_likes WHERE user_id = ? AND route_id = ?'
            );
            $stmt->execute([$viewerUserId, $routeId]);
            $likedByViewer = (bool)$stmt->fetchColumn();
        }

        $recentLimit = max(0, min(20, $recentLimit));
        $recent = [];
        if ($recentLimit > 0) {
            $stmt = $pdo->prepare(
                "SELECT u.public_handle
                   FROM route_likes rl
                   JOIN users u ON u.id = rl.user_id
                  WHERE rl.route_id = ?
                    AND u.public_handle IS NOT NULL
                    AND u.status = 'active'
                  ORDER BY rl.created_at DESC
                  LIMIT ?"
            );
            $stmt->bindValue(1, $routeId, PDO::PARAM_INT);
            $stmt->bindValue(2, $recentLimit, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $h) {
                $recent[] = (string)$h;
            }
        }

        return [
            'count'           => $count,
            'liked_by_viewer' => $likedByViewer,
            'recent'          => $recent,
        ];
    }
}
