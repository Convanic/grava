<?php
declare(strict_types=1);

namespace App\Privacy;

use PDO;

/**
 * Persistenz der Privatzonen (user_privacy_zone). Nur low-level CRUD —
 * Geofence-Logik liegt im {@see PrivacyZone}, Orchestrierung im
 * {@see PrivacyZoneService}.
 *
 * lat/lon sind hochsensibel: Lese-Methoden liefern sie ausschließlich für
 * den Besitzer selbst zurück; {@see ownerZoneForRoute()} gibt das Objekt
 * nur server-intern zum Filtern heraus, nie an einen Response.
 */
final class PrivacyZoneRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Rohzeile der Zone eines Nutzers (auch wenn disabled) oder null.
     *
     * @return array{lat:float,lon:float,radius_m:int,enabled:bool}|null
     */
    public function find(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT lat, lon, radius_m, enabled FROM user_privacy_zone WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'lat'      => (float)$row['lat'],
            'lon'      => (float)$row['lon'],
            'radius_m' => (int)$row['radius_m'],
            'enabled'  => (bool)(int)$row['enabled'],
        ];
    }

    /**
     * Legt die Zone an oder aktualisiert sie (eine Zeile pro Nutzer).
     *
     * @return array{lat:float,lon:float,radius_m:int,enabled:bool}
     */
    public function upsert(int $userId, float $lat, float $lon, int $radiusM, bool $enabled): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_privacy_zone (user_id, lat, lon, radius_m, enabled)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE lat = VALUES(lat), lon = VALUES(lon),
                                     radius_m = VALUES(radius_m), enabled = VALUES(enabled)'
        );
        $stmt->execute([$userId, $lat, $lon, $radiusM, $enabled ? 1 : 0]);
        return ['lat' => $lat, 'lon' => $lon, 'radius_m' => $radiusM, 'enabled' => $enabled];
    }

    public function delete(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM user_privacy_zone WHERE user_id = ?')->execute([$userId]);
    }

    /** Aktive Zone eines Nutzers als Wertobjekt (für Enforcement) oder null. */
    public function enabledZoneForUser(int $userId): ?PrivacyZone
    {
        $row = $this->find($userId);
        if ($row === null || !$row['enabled']) {
            return null;
        }
        return new PrivacyZone($row['lat'], $row['lon'], $row['radius_m']);
    }

    /**
     * Alle aktiven Zonen als Map user_id => PrivacyZone (für den Heatmap-Rebuild).
     *
     * @return array<int,PrivacyZone>
     */
    public function enabledZonesByUser(): array
    {
        $rows = $this->pdo
            ->query('SELECT user_id, lat, lon, radius_m FROM user_privacy_zone WHERE enabled = 1')
            ->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row['user_id']] = new PrivacyZone(
                (float)$row['lat'], (float)$row['lon'], (int)$row['radius_m']
            );
        }
        return $out;
    }

    /**
     * Eigentümer-ID + aktive Zone einer Route (über public_id). Wird beim
     * Ausliefern an Fremde zum Trimmen benutzt. Liefert null, wenn die Route
     * unbekannt ist oder der Eigentümer keine aktive Zone hat.
     *
     * @return array{owner_id:int,zone:PrivacyZone}|null
     */
    public function ownerZoneForRoute(string $routePublicId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.user_id, z.lat, z.lon, z.radius_m
               FROM routes r
               JOIN user_privacy_zone z ON z.user_id = r.user_id AND z.enabled = 1
              WHERE r.public_id = ?'
        );
        $stmt->execute([$routePublicId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'owner_id' => (int)$row['user_id'],
            'zone'     => new PrivacyZone((float)$row['lat'], (float)$row['lon'], (int)$row['radius_m']),
        ];
    }
}
