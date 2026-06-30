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
        // Gechunktes Map-Matching langer Fahrten (GAME_INGEST_CHUNKING_BACKEND).
        // 0 = kein Chunking (ganze Route in einem Stück matchen).
        'game_chunk_size_m'    => '50000',
        'game_chunk_overlap_m' => '500',
        'auth_max_speed_kmh'        => '80',
        'mod_max_new_edges_per_min' => '30',
        'mod_max_passes_per_day'    => '200',
        'group_ride_bonus'          => '1.5',
        'group_ride_min_members'    => '3',
        'crew_max_members'          => '0',
        // Radar-Verkehr (RADAR_TRAFFIC_BACKEND.md §B4).
        'traffic_t0'                => '5.0',
        'traffic_k'                 => '0.5',
        'traffic_f_min'             => '0.7',
        'traffic_f_max'             => '1.3',
        'traffic_n_prior'           => '3',
        'traffic_match_max_dist_m'  => '30',
        'radar_min_closing_kmh'     => '15',
        // Stufe 3 (Fraktionen, GAME_STAGE3_BACKEND.md §6).
        'faction_switch_cooldown_days' => '30',
        'faction_map_grid'             => '0.05',
        // Besitz-Übersicht für weite Zooms (GameOwnershipOverview_Backend_Spec).
        // Feinste Gitterweite (Grad), unter die der adaptive Default nicht geht;
        // spiegelt den Client-Wert adaptiveGrid(region, minGrid: 0.01).
        'ownership_map_min_grid'       => '0.01',
        // Segment-Speed / Tempo-Wertung (GAME_SEGMENT_SPEED_BACKEND.md).
        'segment_min_length_m'      => '200',
        'segment_min_speed_kmh'     => '5',
        'segment_max_speed_kmh'     => '80',
        'segment_leaderboard_top_n' => '100',
        // Rush / Group-Ride-Übernahme (GAME_RUSH_BACKEND.md §2.4). Leerer Wert
        // bei *_per_rush / *_hysteresis_factor = NULL-Semantik (siehe Helfer).
        'rush_enabled'                 => '1',
        'rush_multiplier'              => '2.0',
        'rush_stacks_with_group_bonus' => '0',
        'rush_min_crew_size'           => '3',
        'rush_window_hours'            => '4',
        'rush_window_hours_max'        => '12',
        'rush_max_edges_per_rush'      => '',
        'rush_cooldown_days'           => '7',
        'rush_requires_announcement'   => '1',
        'rush_require_colocation'      => '0',
        'rush_colocation_radius_m'     => '100',
        'rush_hysteresis_factor'       => '',
        // Live-Aktiv-Zähler (PRESENCE_BACKEND.md).
        'presence_ttl_seconds'         => '180',
        'presence_count_anonymous'     => '1',
        // Kanten in Gefahr (GAME_EDGES_AT_RISK_BACKEND.md).
        'risk_threshold'               => '0.85',
        'fade_threshold'               => '0.2',
        'at_risk_list_limit'           => '10',
        // Segment-Rekorde (GAME_SEGMENT_SPEED_BACKEND.md 2026-06-24).
        'record_max_speed_kmh'         => '70',
        'record_min_edge_length_m'     => '50',
        'record_max_hacc_m'            => '20',
        'record_require_recording'     => '1',
        'edge_records_list_limit'      => '10',
        // Wochen-Serie / Streak (GAME_EVENTS_BACKEND.md Teil 2). Anzahl
        // ausgelassener Wochen je Kalendermonat, die die Serie NICHT brechen
        // ("Streak-Schoner"). 0 = Gnade aus.
        'streak_grace_per_month'       => '1',
        // Spiel-Push-Digest (GAME_PUSH_BACKEND.md). Ab dieser Anzahl
        // gleichartiger Ereignisse je Empfänger wird gebündelt (eine Digest-
        // Mitteilung statt N Pushes). Fenster = max. Wartezeit (Minuten), bis
        // auch unter der Schwelle einzeln zugestellt wird.
        'push_game_digest_threshold'   => '3',
        'push_game_digest_window_min'  => '60',
        // Ränge & Abzeichen (RankBadges_Concept.md §5.2/§6/§13). Als JSON, da
        // game_config.config_value (VARCHAR 64) zu kurz für den Katalog ist —
        // hier als Default editierbar; DB-Override erst nach Spalten-Verbreiterung.
        // AP-Gewichte: NUR monotone Größen (Rang fällt nie, §13.4). Gehaltene
        // Revierlänge/Records bewusst NICHT in AP (können fallen) — sie sind
        // Abzeichen-Familien.
        'progression_ap_weights'       => '{"pioneer":1,"takeover":3,"km":1,"streak_week":10}',
        // AP-Schwelle je Rang 1..10 (Index 0 = Rang 1).
        'progression_rank_ap'          => '[0,100,400,1000,2500,5000,10000,20000,40000,80000]',
        // Abzeichen-Katalog: Familie → core (zählt ins Gate) + 5 Stufenschwellen
        // [Bronze..Onyx]. revierhalter/kondition in km. (§5.2)
        'progression_catalog'          => '{"erschliesser":{"core":true,"tiers":[25,250,1500,6000,25000]},"revierhalter":{"core":true,"tiers":[10,100,400,1000,3000]},"kondition":{"core":true,"tiers":[50,500,2500,10000,40000]},"stammfahrer":{"core":true,"tiers":[2,8,26,52,104]},"schnellster":{"core":false,"tiers":[1,5,20,50,150]},"crew":{"core":false,"tiers":[1,5,20,50,150]}}',
        // Abzeichen-Gate je Rang (§13.2), v1 skaliert auf die 5 verfügbaren
        // Familien (mit mehr Familien später anheben). gold/onyx = Stufenanzahl
        // über alle Familien; allCoreGold = alle Kern-Familien ≥ Gold.
        'progression_rank_gate'        => '{"6":{"gold":1},"7":{"gold":2},"8":{"gold":3},"9":{"gold":4,"onyx":1},"10":{"onyx":2,"allCoreGold":true}}',
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

    /** Wie int(), aber leerer Wert → null (NULL-Semantik, z. B. rush_max_edges_per_rush = unbegrenzt). */
    public function intOrNull(string $key): ?int
    {
        $v = trim($this->raw($key));
        return $v === '' ? null : (int)$v;
    }

    /** Wie float(), aber leerer Wert → null (z. B. rush_hysteresis_factor = erbt STAGE1-Wert). */
    public function floatOrNull(string $key): ?float
    {
        $v = trim($this->raw($key));
        return $v === '' ? null : (float)$v;
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
