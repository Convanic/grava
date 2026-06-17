<?php
declare(strict_types=1);

namespace App\Integrations\Strava;

use App\Database\Db;
use App\Routes\RouteService;
use App\Support\Clock;
use App\Support\Crypto;

/**
 * M4e: Orchestriert den Strava-OAuth-Flow und den Activity-Import.
 *
 * Tokens werden über {@see Crypto} AES-256-GCM-verschlüsselt
 * persistiert. Der eigentliche HTTP-Verkehr läuft über den injizierten
 * {@see StravaClient} (Real oder Fake — siehe Dev-Seam).
 *
 * Import-Idempotenz: jede Strava-Activity bekommt eine deterministische
 * client_route_uuid (md5("strava:{id}") als UUID formatiert). Existiert
 * dafür schon eine Route des Users, wird die Activity übersprungen —
 * Re-Import erzeugt also keine Duplikate.
 */
final class StravaService
{
    public function __construct(
        private readonly StravaClient $client,
        private readonly Crypto $crypto,
        private readonly RouteService $routes,
        private readonly string $clientId,
        private readonly string $redirectUri,
        private readonly bool $fakeMode,
        private readonly string $appUrl,
    ) {}

    public function isConfigured(): bool
    {
        return $this->fakeMode || ($this->clientId !== '');
    }

    /**
     * Erzeugt einen single-use State und liefert die Authorize-URL.
     * Im Fake-Modus zeigt sie direkt auf den eigenen Callback (mit
     * Dummy-Code), damit der Flow ohne Strava testbar ist.
     */
    public function authorizeUrl(int $userId): string
    {
        $state = bin2hex(random_bytes(32));
        Db::pdo()->prepare(
            'INSERT INTO oauth_states (state, user_id, provider, created_at)
             VALUES (?, ?, "strava", ?)'
        )->execute([$state, $userId, Clock::nowUtcString()]);

        if ($this->fakeMode) {
            return rtrim($this->appUrl, '/') . '/auth/strava/callback'
                . '?state=' . $state . '&code=fake-auth-code';
        }

        $params = http_build_query([
            'client_id'       => $this->clientId,
            'redirect_uri'    => $this->redirectUri,
            'response_type'   => 'code',
            'approval_prompt' => 'auto',
            'scope'           => 'read,activity:read',
            'state'           => $state,
        ]);
        return 'https://www.strava.com/oauth/authorize?' . $params;
    }

    /**
     * Verifiziert + konsumiert den State, tauscht den Code gegen Tokens
     * und legt/aktualisiert die Connection (verschlüsselt). Liefert die
     * user_id für den Redirect.
     */
    public function handleCallback(string $state, string $code, ?int $expectedUserId = null): int
    {
        if ($state === '' || $code === '') {
            throw new StravaException('oauth_invalid', 'Ungültige OAuth-Antwort.', 400);
        }
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT user_id FROM oauth_states WHERE state = ? AND provider = "strava" LIMIT 1');
        $stmt->execute([$state]);
        $userId = $stmt->fetchColumn();
        if ($userId === false) {
            throw new StravaException('oauth_state_invalid', 'OAuth-State unbekannt oder abgelaufen.', 400);
        }
        $userId = (int)$userId;
        // Single-use: sofort konsumieren.
        $pdo->prepare('DELETE FROM oauth_states WHERE state = ?')->execute([$state]);

        // Session-Binding (CSRF-Schutz): Der Callback wird im Browser des
        // eingeloggten Users aufgerufen. Stimmt der State-Owner nicht mit
        // der aktiven Session überein, könnte ein Angreifer seinen eigenen
        // Strava-Account an einen fremden Account koppeln. Wir lehnen ab.
        if ($expectedUserId !== null && $expectedUserId !== $userId) {
            throw new StravaException('oauth_state_invalid', 'OAuth-State gehört zu einer anderen Sitzung.', 400);
        }

        $tokens = $this->client->exchangeCode($code);
        $this->persistConnection($userId, $tokens);
        return $userId;
    }

    /**
     * Importiert Activities mit GPS-Spur als private Routen.
     *
     * @return array{imported:int, skipped:int, total:int}
     */
    public function import(int $userId): array
    {
        $conn = $this->connectionRow($userId);
        if ($conn === null) {
            throw new StravaException('not_connected', 'Keine Strava-Verbindung.', 409);
        }

        $accessToken = $this->freshAccessToken($userId, $conn);
        $activities  = $this->client->listActivities($accessToken, 30);

        $imported = 0;
        $skipped  = 0;
        foreach ($activities as $act) {
            $activityId = (string)($act['id'] ?? '');
            if ($activityId === '') {
                $skipped++;
                continue;
            }
            $clientUuid = self::activityUuid($activityId);

            // Schon importiert? → skip (idempotent).
            if ($this->routeExists($userId, $clientUuid)) {
                $skipped++;
                continue;
            }

            $streams = $this->client->getActivityStreams($accessToken, $activityId);
            $latlng  = $streams['latlng'] ?? [];
            if (count($latlng) < 2) {
                $skipped++; // keine brauchbare GPS-Spur
                continue;
            }

            $payload = self::buildGeoJson($latlng, $streams['altitude'] ?? []);
            $title   = trim((string)($act['name'] ?? 'Strava-Aktivität'));
            if ($title === '') {
                $title = 'Strava-Aktivität';
            }
            $title = mb_substr($title, 0, 140);

            try {
                $this->routes->createOrAddVersion(
                    userId: $userId,
                    title: $title,
                    description: 'Importiert aus Strava (Activity ' . $activityId . ').',
                    visibility: 'private',
                    source: 'strava',
                    clientRouteUuid: $clientUuid,
                    payload: $payload,
                    tags: ['strava'],
                );
                $imported++;
            } catch (\Throwable $e) {
                error_log('StravaService::import: Activity ' . $activityId . ' übersprungen: ' . $e->getMessage());
                $skipped++;
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'total' => count($activities)];
    }

    public function disconnect(int $userId): void
    {
        Db::pdo()->prepare('DELETE FROM oauth_connections WHERE user_id = ? AND provider = "strava"')
            ->execute([$userId]);
        Db::pdo()->prepare('DELETE FROM oauth_states WHERE user_id = ? AND provider = "strava"')
            ->execute([$userId]);
    }

    /**
     * @return array{connected:bool, athlete_id:?string, scope:?string, connected_at:?string}
     */
    public function status(int $userId): array
    {
        $conn = $this->connectionRow($userId);
        if ($conn === null) {
            return ['connected' => false, 'athlete_id' => null, 'scope' => null, 'connected_at' => null];
        }
        return [
            'connected'    => true,
            'athlete_id'   => (string)$conn['provider_user_id'],
            'scope'        => $conn['scope'] === null ? null : (string)$conn['scope'],
            'connected_at' => str_replace(' ', 'T', (string)$conn['created_at']) . 'Z',
        ];
    }

    // -----------------------------------------------------------------
    // intern
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $tokens */
    private function persistConnection(int $userId, array $tokens): void
    {
        $now = Clock::nowUtcString();
        $expiresAt = isset($tokens['expires_at'])
            ? gmdate('Y-m-d H:i:s', (int)$tokens['expires_at'])
            : null;

        $accessEnc  = $this->crypto->encrypt((string)$tokens['access_token']);
        $refreshEnc = $this->crypto->encrypt((string)$tokens['refresh_token']);

        // Upsert auf (user_id, provider). Re-Connect aktualisiert Tokens.
        $stmt = Db::pdo()->prepare(
            'INSERT INTO oauth_connections
                (user_id, provider, provider_user_id, access_token_enc, refresh_token_enc, scope, expires_at, created_at, updated_at)
             VALUES (?, "strava", ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                provider_user_id = VALUES(provider_user_id),
                access_token_enc = VALUES(access_token_enc),
                refresh_token_enc = VALUES(refresh_token_enc),
                scope = VALUES(scope),
                expires_at = VALUES(expires_at),
                updated_at = VALUES(updated_at)'
        );
        $stmt->bindValue(1, $userId, \PDO::PARAM_INT);
        $stmt->bindValue(2, (string)($tokens['athlete_id'] ?? ''));
        $stmt->bindValue(3, $accessEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(4, $refreshEnc, \PDO::PARAM_LOB);
        $stmt->bindValue(5, $tokens['scope'] ?? null);
        $stmt->bindValue(6, $expiresAt);
        $stmt->bindValue(7, $now);
        $stmt->bindValue(8, $now);
        $stmt->execute();
    }

    /** @return array<string,mixed>|null */
    private function connectionRow(int $userId): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT provider_user_id, access_token_enc, refresh_token_enc, scope, expires_at, created_at
               FROM oauth_connections WHERE user_id = ? AND provider = "strava" LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $conn */
    private function freshAccessToken(int $userId, array $conn): string
    {
        $expiresAt = $conn['expires_at'] !== null ? strtotime((string)$conn['expires_at'] . ' UTC') : 0;
        // 60s Puffer.
        if ($expiresAt > time() + 60) {
            return $this->crypto->decrypt((string)$conn['access_token_enc']);
        }
        // Refresh nötig.
        $refresh = $this->crypto->decrypt((string)$conn['refresh_token_enc']);
        $new = $this->client->refreshToken($refresh);
        $this->persistConnection($userId, [
            'athlete_id'    => $conn['provider_user_id'],
            'access_token'  => $new['access_token'],
            'refresh_token' => $new['refresh_token'],
            'scope'         => $conn['scope'] ?? null,
            'expires_at'    => $new['expires_at'],
        ]);
        return $new['access_token'];
    }

    private function routeExists(int $userId, string $clientUuid): bool
    {
        $stmt = Db::pdo()->prepare(
            'SELECT 1 FROM routes WHERE user_id = ? AND client_route_uuid = ? LIMIT 1'
        );
        $stmt->execute([$userId, $clientUuid]);
        return (bool)$stmt->fetchColumn();
    }

    /** Deterministische UUID (CHAR(36)-konform) aus der Activity-ID. */
    public static function activityUuid(string $activityId): string
    {
        $hex = md5('strava:' . $activityId);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }

    /**
     * Baut ein GeoJSON-LineString-Feature aus latlng + altitude.
     *
     * @param list<array{0:float,1:float}> $latlng  [[lat,lon],…]
     * @param list<float> $altitude
     */
    private static function buildGeoJson(array $latlng, array $altitude): string
    {
        $coords = [];
        foreach ($latlng as $i => $pair) {
            $lat = (float)$pair[0];
            $lon = (float)$pair[1];
            // GeoJSON ist [lon, lat, (alt)].
            if (isset($altitude[$i])) {
                $coords[] = [$lon, $lat, (float)$altitude[$i]];
            } else {
                $coords[] = [$lon, $lat];
            }
        }
        return json_encode([
            'type' => 'Feature',
            'properties' => new \stdClass(),
            'geometry' => [
                'type' => 'LineString',
                'coordinates' => $coords,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
