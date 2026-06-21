<?php
declare(strict_types=1);

namespace App\Push;

use App\Database\Db;
use PDO;

/**
 * Persistenz der APNs-Device-Token (siehe backend/PUSH_BACKEND.md §1).
 *
 * Token ist global eindeutig; ein Upsert auf den Token erlaubt den
 * Besitzerwechsel (Re-Install / Geräte-Weitergabe). Statisch über
 * {@see Db::pdo()} wie der Rest der Engagement-Schicht.
 */
final class PushDeviceRepository
{
    /** Legt das Token an oder aktualisiert Besitzer/Plattform/Environment. */
    public function upsert(int $userId, string $token, string $platform, string $environment): void
    {
        Db::pdo()->prepare(
            'INSERT INTO push_devices (user_id, token, platform, environment)
                  VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                  user_id     = VALUES(user_id),
                  platform    = VALUES(platform),
                  environment = VALUES(environment),
                  updated_at  = CURRENT_TIMESTAMP(3)'
        )->execute([$userId, $token, $platform, $environment]);
    }

    /** Löscht ein Token des Nutzers (Logout). true, wenn etwas gelöscht wurde. */
    public function deleteForUser(int $userId, string $token): bool
    {
        $stmt = Db::pdo()->prepare(
            'DELETE FROM push_devices WHERE user_id = ? AND token = ?'
        );
        $stmt->execute([$userId, $token]);
        return $stmt->rowCount() > 0;
    }

    /** Entfernt ein Token unabhängig vom Besitzer (APNs 410 / Unregistered). */
    public function deleteByToken(string $token): void
    {
        Db::pdo()->prepare('DELETE FROM push_devices WHERE token = ?')
            ->execute([$token]);
    }

    /**
     * Alle Geräte eines Empfängers.
     *
     * @return list<array{token:string,environment:string,platform:string}>
     */
    public function forUser(int $userId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT token, environment, platform FROM push_devices WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'token'       => (string)$r['token'],
                'environment' => (string)$r['environment'],
                'platform'    => (string)$r['platform'],
            ];
        }
        return $out;
    }
}
