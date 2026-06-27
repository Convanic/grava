<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Database\Db;
use App\Discovery\BlockService;
use App\Discovery\FollowService;
use App\Discovery\SocialException;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;

/**
 * M3 Phase 6: POST-Handler für Follow / Unfollow / Block / Unblock
 * aus dem Web-UI. Jeder Endpoint redirected nach erfolgreichem
 * Aufruf zurück zur Profile-Page (`/u/{handle}`) und legt eine
 * Flash-Message zur Bestätigung an.
 *
 * Auth-required + CSRF — die Routen-Bindings in `public/index.php`
 * setzen das schon, dieser Controller verlässt sich darauf.
 */
final class SocialPagesController
{
    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly FollowService $follow,
        private readonly BlockService $block,
    ) {}

    public function follow(Request $req): void
    {
        $handle = (string)($req->routeParams['handle'] ?? '');
        [$viewer, $target] = $this->resolvePair($handle);
        try {
            $isNew = $this->follow->follow($viewer, $target);
            $this->flash($isNew ? "Du folgst jetzt @{$handle}." : "Du folgst @{$handle} bereits.");
        } catch (SocialException $e) {
            $this->flash('Fehler: ' . $e->getMessage());
        }
        Response::redirect('/u/' . $handle);
    }

    public function unfollow(Request $req): void
    {
        $handle = (string)($req->routeParams['handle'] ?? '');
        [$viewer, $target] = $this->resolvePair($handle);
        $this->follow->unfollow($viewer, $target);
        $this->flash("Du folgst @{$handle} nicht mehr.");
        Response::redirect('/u/' . $handle);
    }

    public function block(Request $req): void
    {
        $handle = (string)($req->routeParams['handle'] ?? '');
        // Block muss auch funktionieren, wenn der Target schon den
        // Viewer blockt — siehe SocialController-Kommentar.
        [$viewer, $target] = $this->resolvePair($handle);
        try {
            $isNew = $this->block->block($viewer, $target);
            $this->flash($isNew ? "@{$handle} ist jetzt blockiert." : "@{$handle} war bereits blockiert.");
        } catch (SocialException $e) {
            $this->flash('Fehler: ' . $e->getMessage());
        }
        // Nach Block ist /u/{handle} 404 — also lieber zurück nach
        // /discover schicken, sonst sieht der User eine 404-Page.
        Response::redirect('/discover');
    }

    public function unblock(Request $req): void
    {
        $handle = (string)($req->routeParams['handle'] ?? '');
        [$viewer, $target] = $this->resolvePair($handle);
        $this->block->unblock($viewer, $target);
        $this->flash("@{$handle} ist nicht mehr blockiert.");
        Response::redirect('/u/' . $handle);
    }

    /**
     * @return array{0:int, 1:int}  [viewer_id, target_id]
     */
    private function resolvePair(string $handle): array
    {
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/auth/web-refresh?next=' . rawurlencode('/discover'));
        }
        $viewer = $ctx['user_id'];

        if ($handle === '' || preg_match('/^[a-z0-9_]{2,30}$/', $handle) !== 1) {
            $this->flash('Handle ist ungültig.');
            Response::redirect('/discover');
        }
        $stmt = Db::pdo()->prepare(
            "SELECT id FROM users WHERE public_handle = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$handle]);
        $target = $stmt->fetchColumn();
        if ($target === false) {
            $this->flash('Profil existiert nicht.');
            Response::redirect('/discover');
        }
        return [$viewer, (int)$target];
    }

    private function flash(string $msg): void
    {
        Csrf::ensureStarted();
        $_SESSION['flash'] = $msg;
    }
}
