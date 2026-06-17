<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\WebSession;
use App\Engagement\CommentService;
use App\Engagement\EngagementException;
use App\Engagement\LikeService;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;

/**
 * M4: POST-Handler für Engagement-Aktionen aus dem Web-UI (Like/
 * Unlike + Kommentar anlegen/löschen). Jeder Endpoint ist Auth +
 * CSRF (via Routen-Binding in public/index.php) und redirected
 * zurück auf die Routen-Detail-Seite `/u/{handle}/r/{id}`.
 */
final class EngagementPagesController
{
    public function __construct(
        private readonly WebSession $webSession,
        private readonly LikeService $likes,
        private readonly CommentService $comments,
    ) {}

    public function like(Request $req): void
    {
        [$viewer, $handle, $pid] = $this->resolve($req);
        try {
            $this->likes->like($pid, $viewer);
            $this->flash('Route geliked.');
        } catch (EngagementException $e) {
            $this->flash('Fehler: ' . $e->getMessage());
        }
        Response::redirect($this->backTo($handle, $pid));
    }

    public function unlike(Request $req): void
    {
        [$viewer, $handle, $pid] = $this->resolve($req);
        try {
            $this->likes->unlike($pid, $viewer);
            $this->flash('Like entfernt.');
        } catch (EngagementException $e) {
            $this->flash('Fehler: ' . $e->getMessage());
        }
        Response::redirect($this->backTo($handle, $pid));
    }

    public function comment(Request $req): void
    {
        [$viewer, $handle, $pid] = $this->resolve($req);
        $body = (string)($req->post['body'] ?? '');
        try {
            $this->comments->create($pid, $viewer, $body);
            $this->flash('Kommentar gepostet.');
        } catch (EngagementException $e) {
            $this->flash('Fehler: ' . $e->getMessage());
        }
        Response::redirect($this->backTo($handle, $pid));
    }

    public function commentDelete(Request $req): void
    {
        [$viewer, $handle, $pid] = $this->resolve($req);
        $cid = (int)($req->routeParams['cid'] ?? 0);
        try {
            $this->comments->delete($pid, $cid, $viewer);
            $this->flash('Kommentar gelöscht.');
        } catch (EngagementException $e) {
            $this->flash('Fehler: ' . $e->getMessage());
        }
        Response::redirect($this->backTo($handle, $pid));
    }

    /**
     * @return array{0:int, 1:string, 2:string}  [viewer_id, handle, route_public_id]
     */
    private function resolve(Request $req): array
    {
        $handle = (string)($req->routeParams['handle'] ?? '');
        $pid    = (string)($req->routeParams['id'] ?? '');
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/auth/web-refresh?next=' . rawurlencode($this->backTo($handle, $pid)));
        }
        return [(int)$ctx['user_id'], $handle, $pid];
    }

    private function backTo(string $handle, string $pid): string
    {
        if ($handle !== '' && $pid !== '') {
            return '/u/' . rawurlencode($handle) . '/r/' . rawurlencode($pid);
        }
        return '/discover';
    }

    private function flash(string $msg): void
    {
        Csrf::ensureStarted();
        $_SESSION['flash'] = $msg;
    }
}
