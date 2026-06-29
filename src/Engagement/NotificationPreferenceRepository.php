<?php
declare(strict_types=1);

namespace App\Engagement;

use App\Database\Db;
use PDO;

/**
 * Per-Typ-Push-Präferenzen (NOTIFICATION_PREFERENCES_BACKEND.md S9 +
 * GAME_PUSH_BACKEND.md). Steuert ausschließlich den APNs-Versand; der In-App-
 * Eintrag bleibt immer erhalten. Fehlt eine Zeile, gelten die Defaults
 * (alles an außer game_pioneer). Unbekannte Typen sind forward-compat „an".
 *
 * Spiel-Schalter:
 *   game_takeover — Übernahmen/Rückeroberungen (edge_taken/edge_reclaimed), default an
 *   game_record   — gebrochene Rekorde (record_beaten), default an
 *   game_pioneer  — fremde Erstbefahrung eigener Pionier-Kanten, default AUS (Opt-in)
 */
final class NotificationPreferenceRepository
{
    /** Gated Typen (= Spalten in user_notification_pref). */
    public const TYPES = ['follow', 'like', 'comment', 'rush', 'game_takeover', 'game_record', 'game_pioneer'];

    /** Defaults bei fehlender Zeile. game_pioneer ist Opt-in (aus). */
    private const DEFAULTS = [
        'follow'        => true,
        'like'          => true,
        'comment'       => true,
        'rush'          => true,
        'game_takeover' => true,
        'game_record'   => true,
        'game_pioneer'  => false,
    ];

    /**
     * Effektive Präferenzen des Nutzers (Defaults, wenn keine Zeile).
     *
     * @return array<string,bool>
     */
    public function get(int $userId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT `follow`, `like`, `comment`, `rush`, game_takeover, game_record, game_pioneer
               FROM user_notification_pref WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return self::DEFAULTS;
        }
        $out = [];
        foreach (self::TYPES as $t) {
            $out[$t] = (bool)(int)$row[$t];
        }
        return $out;
    }

    /**
     * Upsert. Fehlende Felder bleiben unverändert (bzw. Default, wenn noch
     * keine Zeile existiert). Liefert die effektiven Präferenzen.
     *
     * @param array<string,bool> $prefs Teilmenge von self::TYPES
     * @return array<string,bool>
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
            'INSERT INTO user_notification_pref
                (user_id, `follow`, `like`, `comment`, `rush`, game_takeover, game_record, game_pioneer)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `follow` = VALUES(`follow`),
                                     `like`   = VALUES(`like`),
                                     `comment`= VALUES(`comment`),
                                     `rush`   = VALUES(`rush`),
                                     game_takeover = VALUES(game_takeover),
                                     game_record   = VALUES(game_record),
                                     game_pioneer  = VALUES(game_pioneer)'
        )->execute([
            $userId,
            $current['follow'] ? 1 : 0,
            $current['like'] ? 1 : 0,
            $current['comment'] ? 1 : 0,
            $current['rush'] ? 1 : 0,
            $current['game_takeover'] ? 1 : 0,
            $current['game_record'] ? 1 : 0,
            $current['game_pioneer'] ? 1 : 0,
        ]);
        return $current;
    }

    /**
     * Darf für diesen Empfänger eine Push dieses Typs versendet werden?
     * Die Rush-Push-Typen hängen am Schalter `rush`; die Spiel-Ereignis-Typen
     * an den drei game_*-Schaltern. Unbekannte Typen → true (forward-compat).
     */
    public function isPushEnabled(int $userId, string $type): bool
    {
        $type = self::prefKeyFor($type);
        if (!in_array($type, self::TYPES, true)) {
            return true;
        }
        return $this->get($userId)[$type];
    }

    /** Bildet einen Notification-Typ auf seine Pref-Spalte ab. */
    private static function prefKeyFor(string $type): string
    {
        if (str_starts_with($type, 'rush_')) {
            return 'rush';
        }
        return match ($type) {
            'edge_taken', 'edge_lost', 'edge_reclaimed', 'territory_taken' => 'game_takeover',
            'record_beaten' => 'game_record',
            'pioneer_joined' => 'game_pioneer',
            default => $type,
        };
    }
}
