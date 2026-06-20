<?php
declare(strict_types=1);

namespace App\Game;

use PDO;

/**
 * Server-justierbare Spiel-Parameter (Spec §3.5). Liest die key/value-
 * Tabelle game_config einmal lazy und cached sie im Objekt. Fehlt ein
 * Key, greift der eingebaute Default — so bleibt das System lauffaehig,
 * auch wenn ein neuer Parameter noch nicht geseedet ist.
 */
final class GameConfig
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    /** @var array<string,string> */
    private const DEFAULTS = [
        'presence_window_days' => '90',
        'presence_decay'       => 'linear',
        'hysteresis_factor'    => '1.15',
        'pioneer_p0'           => '100',
        'pioneer_k'            => '12',
        'pioneer_s'            => '4',
        'popularity_c'         => '30',
        'value_combine'        => 'max',
        'curation_per_hint'    => '5',
        'curation_per_like'    => '2',
        'auth_min_speed_kmh'   => '5',
        'auth_max_hacc_m'      => '30',
        'auth_require_motion'  => '1',
        'start_buffer_m'       => '0',
    ];

    public function __construct(private readonly PDO $pdo) {}

    private function raw(string $key): string
    {
        if ($this->cache === null) {
            $this->cache = [];
            try {
                $rows = $this->pdo->query('SELECT config_key, config_value FROM game_config')
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
                $this->cache = is_array($rows) ? $rows : [];
            } catch (\PDOException) {
                $this->cache = [];
            }
        }
        return $this->cache[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    public function string(string $key): string
    {
        return $this->raw($key);
    }

    public function float(string $key): float
    {
        return (float)$this->raw($key);
    }

    public function int(string $key): int
    {
        return (int)$this->raw($key);
    }

    public function bool(string $key): bool
    {
        return in_array(strtolower(trim($this->raw($key))), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return array<string,string> Alle effektiven Werte (DB ueber Default). */
    public function all(): array
    {
        $out = self::DEFAULTS;
        foreach (self::DEFAULTS as $k => $_) {
            $out[$k] = $this->raw($k);
        }
        return $out;
    }
}
