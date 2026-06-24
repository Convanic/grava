<?php
declare(strict_types=1);

namespace App\Engagement;

use App\Database\Db;
use PDO;

/**
 * Per-Typ-Push-Präferenzen (NOTIFICATION_PREFERENCES_BACKEND.md, S9).
 *
 * Steuert ausschließlich den APNs-Versand; der In-App-Eintrag bleibt immer
 * erhalten. Fehlt eine Zeile, gelten alle Typen als aktiviert (Default true,
 * lazy-create beim ersten PUT). Unbekannte Typen sind forward-compat ebenfalls
 * „an" (neue Typen werden erst durch ein additives Schema gated).
 */
final class NotificationPreferenceRepository
{
    /** Gated Typen. Erweiterbar (Welle 2: territory_taken, crew_invite). */
    public const TYPES = ['follow', 'like', 'comment', 'rush'];

    /**
     * Effektive Präferenzen des Nutzers (Default true, wenn keine Zeile).
     *
     * @return array{follow:bool,like:bool,comment:bool,rush:bool}
     */
    public function get(int $userId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT `follow`, `like`, `comment`, `rush` FROM user_notification_pref WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['follow' => true, 'like' => true, 'comment' => true, 'rush' => true];
        }
        return [
            'follow'  => (bool)(int)$row['follow'],
            'like'    => (bool)(int)$row['like'],
            'comment' => (bool)(int)$row['comment'],
            'rush'    => (bool)(int)$row['rush'],
        ];
    }

    /**
     * Upsert. Fehlende Felder bleiben unverändert (bzw. Default true, wenn noch
     * keine Zeile existiert). Liefert die effektiven Präferenzen.
     *
     * @param array<string,bool> $prefs Teilmenge von self::TYPES
     * @return array{follow:bool,like:bool,comment:bool,rush:bool}
     */
    public function upsert(int $userId, array $prefs): array
    {
        $current = $this->get($userId);
        foreach (self::TYPES as $t) {
            if (array_key_exists($t, $prefs)) {
                $current[$t] = (bool)$prefs[$t];
            }
        }
        Db::pdo()->prepare(
            'INSERT INTO user_notification_pref (user_id, `follow`, `like`, `comment`, `rush`)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `follow` = VALUES(`follow`),
                                     `like`   = VALUES(`like`),
                                     `comment`= VALUES(`comment`),
                                     `rush`   = VALUES(`rush`)'
        )->execute([
            $userId,
            $current['follow'] ? 1 : 0,
            $current['like'] ? 1 : 0,
            $current['comment'] ? 1 : 0,
            $current['rush'] ? 1 : 0,
        ]);
        return $current;
    }

    /**
     * Darf für diesen Empfänger eine Push dieses Typs versendet werden?
     * Die drei Rush-Push-Typen (rush_invite/reminder/result) hängen am
     * gemeinsamen Schalter `rush`. Unbekannte Typen → true (forward-compat).
     */
    public function isPushEnabled(int $userId, string $type): bool
    {
        if (str_starts_with($type, 'rush_')) {
            $type = 'rush';
        }
        if (!in_array($type, self::TYPES, true)) {
            return true;
        }
        return $this->get($userId)[$type];
    }
}
