<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Http\Request;
use App\Http\Response;
use App\Push\PushDeviceRepository;

/**
 * APNs-Geräteverwaltung (siehe backend/PUSH_BACKEND.md §2), alle
 * auth-required (Bearer).
 *
 *   POST   /api/v1/notifications/devices        Upsert eines Tokens (204)
 *   DELETE /api/v1/notifications/devices/{token} Token entfernen (204)
 */
final class PushDeviceController
{
    public function __construct(private readonly PushDeviceRepository $devices) {}

    public function register(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);

        $token       = trim((string)($req->json['token'] ?? ''));
        $platform    = strtolower(trim((string)($req->json['platform'] ?? 'ios')));
        $environment = strtolower(trim((string)($req->json['environment'] ?? '')));

        if ($token === '' || strlen($token) > 200 || preg_match('/^[A-Fa-f0-9]+$/', $token) !== 1) {
            Response::error('invalid_token', 'Ungültiges Device-Token.', 422);
        }
        if (!in_array($platform, ['ios'], true)) {
            Response::error('invalid_platform', 'Plattform wird nicht unterstützt.', 422);
        }
        if (!in_array($environment, ['development', 'production'], true)) {
            Response::error('invalid_environment', 'environment muss development oder production sein.', 422);
        }

        $this->devices->upsert($userId, $token, $platform, $environment);
        Response::noContent();
    }

    public function unregister(Request $req): void
    {
        $userId = (int)($req->user->internal_id ?? 0);
        $token  = trim((string)($req->routeParams['token'] ?? ''));
        if ($token !== '') {
            $this->devices->deleteForUser($userId, $token);
        }
        Response::noContent();
    }
}
