<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use App\Media\AvatarException;
use App\Media\AvatarService;

/**
 * M4d: Avatar-Upload/-Delete/-Serving.
 *
 *   POST   /api/v1/users/me/avatar   Bearer+Verified; multipart (Feld "avatar")
 *   DELETE /api/v1/users/me/avatar   Bearer; 204
 *   GET    /u/{handle}/avatar        public; image/* (oder Placeholder-PNG)
 *
 * Upload ist POST (nicht PUT): PHP befüllt $_FILES nur bei
 * multipart/form-data über POST.
 */
final class AvatarController
{
    public function __construct(private readonly AvatarService $avatars) {}

    public function upload(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        $upload = $req->file('avatar');
        if ($upload === null) {
            Response::error('avatar_required', 'Bilddatei ist erforderlich (Form-Feld "avatar").', 422);
        }
        try {
            $rel = $this->avatars->store($userId, $upload);
        } catch (AvatarException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::json(['ok' => true, 'avatar_path' => $rel]);
    }

    public function delete(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        $this->avatars->delete($userId);
        Response::noContent();
    }

    /**
     * Öffentliches Serving. Liefert das gespeicherte Bild oder — falls
     * kein Avatar gesetzt — ein deterministisches Placeholder-PNG.
     * Immer 200 (auch Placeholder), damit <img> nie bricht; nur bei
     * komplett unbekanntem/ungültigem Handle 404.
     */
    public function serve(Request $req): void
    {
        $handle = (string)($req->routeParams['handle'] ?? '');
        $found  = $this->avatars->resolveForHandle($handle);

        if ($found !== null) {
            header('Content-Type: ' . $found['mime']);
            header('Cache-Control: public, max-age=86400');
            header('Content-Length: ' . (string)filesize($found['path']));
            readfile($found['path']);
            exit;
        }

        // Placeholder (auch für nicht existente Handles — verrät keine
        // Existenz, ist aber konsistent als <img>-Quelle nutzbar).
        $png = $this->avatars->placeholderPng($handle !== '' ? $handle : '?');
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        header('Content-Length: ' . (string)strlen($png));
        echo $png;
        exit;
    }
}
