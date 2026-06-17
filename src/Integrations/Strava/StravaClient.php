<?php
declare(strict_types=1);

namespace App\Integrations\Strava;

/**
 * M4e Dev-Seam: kapselt alle HTTP-Calls zur Strava-API hinter einem
 * Interface. {@see RealStravaClient} spricht das echte Strava-API,
 * {@see FakeStravaClient} liefert Fixtures — so ist der komplette
 * Import-Pfad ohne Netz/Credentials smoke-testbar.
 *
 * Alle Token-Werte sind Klartext; Verschlüsselung passiert eine
 * Ebene höher im StravaService.
 */
interface StravaClient
{
    /**
     * Tauscht einen Authorization-Code gegen Tokens + Athleten-Info.
     *
     * @return array{
     *   access_token:string, refresh_token:string, expires_at:int,
     *   athlete_id:string, athlete_username:?string, scope:?string
     * }
     */
    public function exchangeCode(string $code): array;

    /**
     * Erneuert ein abgelaufenes Access-Token.
     *
     * @return array{access_token:string, refresh_token:string, expires_at:int}
     */
    public function refreshToken(string $refreshToken): array;

    /**
     * Listet die letzten Activities des verbundenen Athleten.
     *
     * @return list<array{id:string, name:string, type:string, start_date:?string}>
     */
    public function listActivities(string $accessToken, int $perPage = 30): array;

    /**
     * Liefert die GPS-Spur einer Activity.
     *
     * @return array{latlng:list<array{0:float,1:float}>, altitude:list<float>}
     */
    public function getActivityStreams(string $accessToken, string $activityId): array;
}
