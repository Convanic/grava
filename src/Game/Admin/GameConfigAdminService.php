<?php
declare(strict_types=1);
namespace App\Game\Admin;

use App\Game\GameConfig;
use PDO;

/** Validiert + persistiert game_config-Änderungen aus dem Admin-Dashboard und auditiert sie. */
final class GameConfigAdminService
{
    /** @var list<string> */
    private const NUMERIC_KEYS = [
        'presence_window_days','hysteresis_factor','pioneer_p0','pioneer_k','pioneer_s',
        'popularity_c','curation_per_hint','curation_per_like','auth_min_speed_kmh',
        'auth_max_hacc_m','start_buffer_m','auth_max_speed_kmh','mod_max_new_edges_per_min',
        'mod_max_passes_per_day',
    ];
    /** @var array<string,list<string>> */
    private const ENUM_KEYS = [
        'presence_decay' => ['linear'],
        'value_combine'  => ['max','sum'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly GameConfig $config,
        private readonly GameAuditService $audit,
    ) {}

    /**
     * Validiert ALLE Werte; schreibt nur, wenn KEINE Fehler. Auditiert je geänderten Key.
     * @param array<string,string> $values
     * @return array<string,string> Fehler je key (leer = ok, alles geschrieben)
     */
    public function update(int $adminUserId, array $values): array
    {
        $errors = [];
        $clean = [];
        foreach ($values as $key => $value) {
            $value = trim((string)$value);
            if (in_array($key, self::NUMERIC_KEYS, true)) {
                if (!is_numeric($value) || (float)$value < 0) {
                    $errors[$key] = 'muss eine Zahl >= 0 sein';
                    continue;
                }
                if ($key === 'presence_window_days' && (int)$value < 1) {
                    $errors[$key] = 'muss >= 1 sein';
                    continue;
                }
                $clean[$key] = $value;
            } elseif (isset(self::ENUM_KEYS[$key])) {
                if (!in_array($value, self::ENUM_KEYS[$key], true)) {
                    $errors[$key] = 'ungueltiger Wert';
                    continue;
                }
                $clean[$key] = $value;
            } elseif ($key === 'auth_require_motion') {
                if (!in_array(strtolower($value), ['0','1','true','false','yes','no','on','off'], true)) {
                    $errors[$key] = 'muss boolesch sein';
                    continue;
                }
                $clean[$key] = $value;
            }
            // unbekannte Keys werden ignoriert
        }
        if ($errors !== []) {
            return $errors;
        }
        foreach ($clean as $key => $value) {
            $before = $this->config->string($key);
            if ($before === $value) {
                continue;
            }
            $this->pdo->prepare(
                'INSERT INTO game_config (config_key, config_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
            )->execute([$key, $value]);
            $this->audit->record($adminUserId, 'config_update', 'config:' . $key, ['before' => $before, 'after' => $value]);
        }
        return [];
    }
}
