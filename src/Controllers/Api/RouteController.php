<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;
use App\Routes\GeometryParseException;
use App\Routes\RouteNotFoundException;
use App\Routes\RouteService;
use App\Routes\ShareTokenService;
use App\Support\Validator;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Owner-API für Routen — alle Endpoints brauchen ein gültiges
 * Bearer-Token (siehe Routing in {@see public/index.php}).
 *
 * Endpoints:
 *  - POST   /api/v1/routes                       Upload (multipart oder JSON+base64)
 *  - GET    /api/v1/routes                       Liste der eigenen Routen
 *  - GET    /api/v1/routes/{id}                  Einzelne Route
 *  - PATCH  /api/v1/routes/{id}                  Metadaten patchen
 *  - DELETE /api/v1/routes/{id}                  Soft-Delete
 *  - GET    /api/v1/routes/{id}/payload          Geometrie als File (head oder ?version=N)
 *  - POST   /api/v1/routes/{id}/shares           Share-Link erzeugen
 *  - GET    /api/v1/routes/{id}/shares           Share-Liste
 *  - DELETE /api/v1/routes/{id}/shares/{shareId} Share revoken
 */
final class RouteController
{
    public function __construct(
        private readonly RouteService $routes,
        private readonly ShareTokenService $shares,
        private readonly Config $config,
    ) {}

    public function upload(Request $req): void
    {
        $userId = $this->userId($req);

        $v = new Validator();
        $payload = '';

        if ($req->isMultipart()) {
            $upload = $req->file('payload');
            if ($upload === null) {
                Response::error('validation_error', 'Bitte überprüfe deine Eingaben.', 422, [
                    'payload' => ['Datei ist erforderlich (Form-Feld "payload").'],
                ]);
            }
            $maxBytes = $this->config->int('REQUEST_MAX_UPLOAD_BYTES', 26_214_400);
            if ($upload['size'] > $maxBytes) {
                Response::error('payload_too_large', 'Datei ist zu groß.', 413);
            }
            $contents = @file_get_contents($upload['tmp_name']);
            if ($contents === false) {
                Response::error('server_error', 'Datei konnte nicht gelesen werden.', 500);
            }
            $payload = $contents;
            $title       = $v->routeTitle('title', $req->input('title'));
            $description = $v->optionalString('description', $req->input('description'));
            $visibility  = $v->visibility('visibility', $req->input('visibility'));
            $source      = $v->routeSource('source', $req->input('source'));
            $clientUuid  = $v->uuidOptional('client_route_uuid', $req->input('client_route_uuid'));
            $tags        = $v->tags('tags', $req->input('tags'));
        } else {
            // JSON-Pfad mit base64-Payload.
            $payloadB64 = $req->input('payload_base64');
            if (!is_string($payloadB64) || $payloadB64 === '') {
                $v->add('payload_base64', 'Pflichtfeld.');
            } else {
                $decoded = base64_decode($payloadB64, true);
                if ($decoded === false) {
                    $v->add('payload_base64', 'Ist kein gültiges Base64.');
                } else {
                    $maxBytes = $this->config->int('REQUEST_MAX_UPLOAD_BYTES', 26_214_400);
                    if (strlen($decoded) > $maxBytes) {
                        Response::error('payload_too_large', 'Payload ist zu groß.', 413);
                    }
                    $payload = $decoded;
                }
            }
            $title       = $v->routeTitle('title', $req->input('title'));
            $description = $v->optionalString('description', $req->input('description'));
            $visibility  = $v->visibility('visibility', $req->input('visibility'));
            $source      = $v->routeSource('source', $req->input('source'));
            $clientUuid  = $v->uuidOptional('client_route_uuid', $req->input('client_route_uuid'));
            $tags        = $v->tags('tags', $req->input('tags'));
        }

        if ($v->fails()) {
            Response::error('validation_error', 'Bitte überprüfe deine Eingaben.', 422, $v->errors());
        }
        if ($title === null || $payload === '') {
            Response::error('validation_error', 'Bitte überprüfe deine Eingaben.', 422, $v->errors());
        }

        try {
            $result = $this->routes->createOrAddVersion(
                userId: $userId,
                title: $title,
                description: $description,
                visibility: $visibility,
                source: $source,
                clientRouteUuid: $clientUuid,
                payload: $payload,
                tags: $tags,
            );
        } catch (GeometryParseException $e) {
            Response::error('validation_error', 'Bitte überprüfe deine Eingaben.', 422, [
                'payload' => [$e->getMessage()],
            ]);
        } catch (Throwable $e) {
            // RouteService wirft generische RuntimeExceptions für
            // bekannte Validierungsfehler (Title-Länge etc.). Die
            // sollten alle vorher vom Validator gefangen sein —
            // wenn nicht, geben wir trotzdem 422 statt 500 zurück.
            Response::error('validation_error', $e->getMessage(), 422);
        }

        $action = $result['action'];
        header('X-Route-Action: ' . $action);
        Response::json(
            [
                'route'   => $result['route'],
                'version' => $result['version'],
                'action'  => $action,
            ],
            $action === 'created' ? 201 : 200,
        );
    }

    public function listForUser(Request $req): void
    {
        $userId = $this->userId($req);

        $limit  = (int)($req->query['limit']  ?? 50);
        $offset = (int)($req->query['offset'] ?? 0);
        $items  = $this->routes->listForUser($userId, $limit, $offset);

        Response::json([
            'routes'     => $items,
            'pagination' => [
                'limit'    => max(1, min(200, $limit)),
                'offset'   => max(0, $offset),
                'has_more' => count($items) === max(1, min(200, $limit)),
            ],
        ]);
    }

    public function show(Request $req): void
    {
        $userId   = $this->userId($req);
        $publicId = (string)($req->routeParams['id'] ?? '');
        $route    = $this->routes->get($userId, $publicId);
        if ($route === null) {
            Response::error('not_found', 'Route nicht gefunden.', 404);
        }
        Response::json(['route' => $route]);
    }

    public function patch(Request $req): void
    {
        $userId   = $this->userId($req);
        $publicId = (string)($req->routeParams['id'] ?? '');

        $v = new Validator();
        $patch = [];
        if ($req->input('title') !== null) {
            $title = $v->routeTitle('title', $req->input('title'));
            if ($title !== null) { $patch['title'] = $title; }
        }
        // description: explizit vorhanden im Body? Auch null akzeptieren
        // (== entferne Beschreibung). Wir prüfen über input vs default.
        $bodyHasDesc = array_key_exists('description', $req->json) || array_key_exists('description', $req->post);
        if ($bodyHasDesc) {
            $raw = $req->input('description');
            $patch['description'] = $raw === null ? null : $v->optionalString('description', $raw);
        }
        if ($req->input('visibility') !== null) {
            $patch['visibility'] = $v->visibility('visibility', $req->input('visibility'));
        }
        if ($req->input('tags') !== null) {
            $patch['tags'] = $v->tags('tags', $req->input('tags'));
        }
        if ($v->fails()) {
            Response::error('validation_error', 'Bitte überprüfe deine Eingaben.', 422, $v->errors());
        }

        try {
            $route = $this->routes->updateMeta($userId, $publicId, $patch);
        } catch (RouteNotFoundException) {
            Response::error('not_found', 'Route nicht gefunden.', 404);
        } catch (Throwable $e) {
            Response::error('validation_error', $e->getMessage(), 422);
        }
        Response::json(['route' => $route]);
    }

    public function softDelete(Request $req): void
    {
        $userId   = $this->userId($req);
        $publicId = (string)($req->routeParams['id'] ?? '');
        try {
            $this->routes->softDelete($userId, $publicId);
        } catch (RouteNotFoundException) {
            Response::error('not_found', 'Route nicht gefunden.', 404);
        }
        Response::noContent();
    }

    public function downloadPayload(Request $req): void
    {
        $userId   = $this->userId($req);
        $publicId = (string)($req->routeParams['id'] ?? '');
        $version  = isset($req->query['version']) ? (int)$req->query['version'] : null;
        if ($version !== null && $version < 1) {
            Response::error('validation_error', 'version muss >= 1 sein.', 422, ['version' => ['ungültig']]);
        }
        try {
            $loaded = $this->routes->loadPayload($userId, $publicId, $version);
        } catch (RouteNotFoundException) {
            Response::error('not_found', 'Route nicht gefunden.', 404);
        }
        $contentType = $loaded['format'] === 'gpx'
            ? 'application/gpx+xml; charset=utf-8'
            : 'application/geo+json; charset=utf-8';
        $filename = sprintf('route-%s-v%d.%s', $publicId, $loaded['version'], $loaded['format']);
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $loaded['payload'];
        exit;
    }

    public function createShare(Request $req): void
    {
        $userId   = $this->userId($req);
        $publicId = (string)($req->routeParams['id'] ?? '');

        $expiresAt = null;
        $rawExp    = $req->input('expires_at');
        if (is_string($rawExp) && $rawExp !== '') {
            $expiresAt = self::parseExpires($rawExp);
            if ($expiresAt === null) {
                Response::error('validation_error', 'expires_at muss ISO-8601 in UTC sein.', 422, [
                    'expires_at' => ['Ungültiges Datumsformat.'],
                ]);
            }
        }

        try {
            $share = $this->shares->create($userId, $publicId, $expiresAt);
        } catch (RouteNotFoundException) {
            Response::error('not_found', 'Route nicht gefunden.', 404);
        }

        $appUrl   = rtrim((string)$this->config->get('APP_URL', ''), '/');
        $apiBase  = rtrim((string)$this->config->get('API_BASE_PATH', '/api/v1'), '/');
        // Vorerst: Share-URL zeigt auf das API. Phase 6 ergänzt eine
        // hübschere Web-Page mit gleichem Token an der gleichen URL.
        $shareUrl = $appUrl . $apiBase . '/share/' . $share['token'];
        Response::json([
            'share_id'   => $share['share_id'],
            'token'      => $share['token'],
            'url'        => $shareUrl,
            'expires_at' => $share['expires_at'],
        ], 201);
    }

    public function listShares(Request $req): void
    {
        $userId   = $this->userId($req);
        $publicId = (string)($req->routeParams['id'] ?? '');
        // listForRoute liefert leere Liste auch bei nicht existierender
        // Route — aus Owner-Sicht ein subtiler Probing-Schutz.
        $shares = $this->shares->listForRoute($userId, $publicId);
        Response::json(['shares' => $shares]);
    }

    public function revokeShare(Request $req): void
    {
        $userId  = $this->userId($req);
        $shareId = (int)($req->routeParams['shareId'] ?? 0);
        if ($shareId <= 0) {
            Response::error('not_found', 'Share nicht gefunden.', 404);
        }
        $this->shares->revoke($userId, $shareId);
        Response::noContent();
    }

    /**
     * Holt die interne BIGINT-User-ID aus dem von RequireBearer
     * gesetzten User-Objekt. {@see TokenService::resolveAccess()}
     * legt `internal_id` direkt auf das User-Array — kein extra
     * SELECT pro Request.
     */
    private function userId(Request $req): int
    {
        $u = $req->user;
        if ($u === null) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }
        $uid = (int)($u->internal_id ?? 0);
        if ($uid <= 0) {
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }
        return $uid;
    }

    private static function parseExpires(string $raw): ?DateTimeImmutable
    {
        // ISO-8601 mit oder ohne 'Z'.
        try {
            $dt = new DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
        $utc = $dt->setTimezone(new DateTimeZone('UTC'));
        // Mindestens 1 Minute in der Zukunft, sonst sinnlos.
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($utc <= $now->modify('+1 minute')) {
            return null;
        }
        return $utc;
    }
}
