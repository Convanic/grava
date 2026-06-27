<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Game\Crew\CrewLogoException;
use App\Game\Crew\CrewLogoService;
use App\Http\Request;
use App\Http\Response;

/**
 * Crew-Logo-Endpunkte (GAME_CREW_LOGO_BACKEND.md). Spiegel des Avatar-
 * Controllers, nur pro Crew:
 *
 *   POST   /api/v1/game/crews/{slug}/logo   Bearer, Captain; multipart (Feld "logo")
 *   DELETE /api/v1/game/crews/{slug}/logo   Bearer, Captain; 204
 *   GET    /game/crews/{slug}/logo          public; image/jpeg oder 404
 *
 * Upload ist POST (nicht PUT): PHP befüllt $_FILES nur bei
 * multipart/form-data über POST.
 */
final class CrewLogoController
{
    public function __construct(private readonly CrewLogoService $logos) {}

    public function upload(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        $slug   = trim((string)($req->routeParams['slug'] ?? ''));
        $upload = $req->file('logo');
        if ($upload === null) {
            Response::error('logo_required', 'Bilddatei ist erforderlich (Form-Feld "logo").', 422);
        }
        try {
            $res = $this->logos->store($slug, $userId, $upload);
        } catch (CrewLogoException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::json(['ok' => true, 'logo_path' => $res['logo_path']]);
    }

    public function delete(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        $slug   = trim((string)($req->routeParams['slug'] ?? ''));
        try {
            $this->logos->delete($slug, $userId);
        } catch (CrewLogoException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }
        Response::noContent();
    }

    /**
     * Öffentliches Serving. Liefert die JPEG-Bytes des Crew-Logos oder 404,
     * falls kein Logo gesetzt / Slug unbekannt ist (die App zeigt dann ihren
     * eigenen Platzhalter). Der ?v-Query-Parameter ist nur ein Cache-Buster
     * und wird serverseitig ignoriert.
     */
    public function serve(Request $req): void
    {
        $slug  = (string)($req->routeParams['slug'] ?? '');
        $found = $this->logos->resolveForSlug($slug);
        if ($found === null) {
            Response::error('not_found', 'Kein Logo.', 404);
        }

        $etag = '"' . substr(md5((string)@filemtime($found['path']) . '|' . $found['path']), 0, 16) . '"';
        if (trim((string)($req->header('If-None-Match') ?? '')) === $etag) {
            http_response_code(304);
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=31536000, immutable');
            exit;
        }

        header('Content-Type: ' . $found['mime']);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header('Content-Length: ' . (string)filesize($found['path']));
        readfile($found['path']);
        exit;
    }
}
