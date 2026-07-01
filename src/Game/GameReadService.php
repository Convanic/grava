<?php
declare(strict_types=1);

namespace App\Game;

use App\Privacy\PrivacyZoneRepository;
use App\Support\Clock;
use App\Support\MapLod;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Baut die JSON-Strukturen der /game-Lesepfade (Spec §6) und rechnet die
 * Frische beim Lesen mit "jetzt" nach (Spec §7), damit lange ungenutzte
 * Kanten nicht zu frisch erscheinen.
 */
final class GameReadService
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameConfig $config,
        private readonly ?EdgeRecordService $records = null,
        private readonly ?PrivacyZoneRepository $zones = null,
        private readonly ?EdgeRecalculator $recalc = null,
    ) {}

    /**
     * @param string $bbox "minLon,minLat,maxLon,maxLat"
     * @param int|null $viewerUserId der anfragende Mensch (für Heimatzone-
     *        Maskierung der in_reach-Markierung); ohne Bearer null.
     * @return list<array<string,mixed>>
     */
    public function edgesInBbox(
        string $bbox,
        ?int $mineClaimantId,
        ?DateTimeImmutable $now,
        int $limit = 500,
        ?int $viewerUserId = null,
        bool $personal = false,
    ): array {
        $now ??= Clock::nowUtc();
        [$minLon, $minLat, $maxLon, $maxLat] = $this->parseBbox($bbox);
        $rows = $this->repo->edgesInBbox($minLon, $minLat, $maxLon, $maxLat, null, $limit);

        // in_reach (GAME_IN_REACH_BACKEND.md) nur personalisiert (mit Bearer).
        // Ohne Viewer-Claimant bleibt das Feld weg (anonyme Kartenansicht, AC5).
        $viewerPresence = [];
        $zone = null;
        if ($mineClaimantId !== null) {
            $edgeIds = array_map(static fn($r) => (int)$r['id'], $rows);
            $members = $this->repo->usersForClaimant($mineClaimantId);
            $windowDays = $this->config->int('presence_window_days');
            foreach ($this->repo->passesForEdgesByUsers($edgeIds, $members) as $p) {
                $w = GameMath::presenceWeight($this->ageDays($p['ridden_at'], $now), $windowDays);
                $eid = $p['edge_id'];
                $viewerPresence[$eid] = ($viewerPresence[$eid] ?? 0.0) + $w;
            }
            if ($viewerUserId !== null && $this->zones !== null) {
                $zone = $this->zones->enabledZoneForUser($viewerUserId);
            }
        }
        // Hysterese-Faktor identisch zur Übernahme-Entscheidung im Recalculator.
        $hysteresis = $this->config->floatOrNull('rush_hysteresis_factor')
            ?? $this->config->float('hysteresis_factor');

        // Persönliche Gefahr-Sicht (opt-in, nur mit Bearer): pro Kante deine
        // individuelle Rider-Präsenz gegen den stärksten ANDEREN Einzelfahrer —
        // egal ob Crew-Kollege oder fremd. `personal_vulnerability` (Nähe des
        // Verfolgers, 0…1) nur, wenn du der stärkste Einzelfahrer bist;
        // `challenger_scope` = 'crew' (Kollege) | 'foreign' (fremd).
        $personalVuln = [];
        $personalScope = [];
        if ($personal && $viewerUserId !== null) {
            $edgeIds = array_map(static fn($r) => (int)$r['id'], $rows);
            $myMembers = $mineClaimantId !== null
                ? array_flip($this->repo->usersForClaimant($mineClaimantId))
                : [$viewerUserId => 0];
            $byEdgeUser = [];
            foreach ($this->repo->allPassesForEdges($edgeIds) as $p) {
                $w = GameMath::presenceWeight($this->ageDays($p['ridden_at'], $now), $windowDays);
                $byEdgeUser[$p['edge_id']][$p['user_id']] = ($byEdgeUser[$p['edge_id']][$p['user_id']] ?? 0.0) + $w;
            }
            foreach ($byEdgeUser as $eid => $perUser) {
                $me = $perUser[$viewerUserId] ?? 0.0;
                $topOther = 0.0;
                $topOtherUser = null;
                foreach ($perUser as $uid => $pres) {
                    if ((int)$uid === $viewerUserId) {
                        continue;
                    }
                    if ($pres > $topOther) {
                        $topOther = $pres;
                        $topOtherUser = (int)$uid;
                    }
                }
                // Nur „persönlich deine" umkämpfte Kante: du bist der stärkste
                // Einzelfahrer UND es gibt einen echten Verfolger.
                if ($me > 0.0 && $me >= $topOther && $topOther > 0.0) {
                    $personalVuln[$eid] = round(min(1.0, $topOther / ($me * $hysteresis)), 3);
                    $personalScope[$eid] = isset($myMembers[$topOtherUser]) ? 'crew' : 'foreign';
                }
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $edge = $this->formatEdge($row, $mineClaimantId, $now);
            if ($mineClaimantId !== null) {
                $edge['in_reach'] = $this->inReach(
                    $row,
                    $edge['owner_is_me'],
                    $viewerPresence[(int)$row['id']] ?? 0.0,
                    $hysteresis,
                    $zone,
                );
            }
            if ($personal && $viewerUserId !== null) {
                $edge['personal_vulnerability'] = $personalVuln[(int)$row['id']] ?? null;
                $edge['challenger_scope'] = $personalScope[(int)$row['id']] ?? null;
            }
            $out[] = $edge;
        }
        return $out;
    }

    /**
     * `in_reach` einer Kante für den Viewer (GAME_IN_REACH_BACKEND.md):
     * true, wenn die Kante nicht dem Viewer gehört und ein einziger weiterer
     * authentischer Pass (Gewicht 1,0) seine Präsenz über die Übernahme-
     * Schwelle des aktuellen Besitzers heben würde:
     *   P(du) + 1,0 > P(Besitzer) × Hysterese
     * Bei freier Kante (owner_presence_cached = 0) genügt der erste Pass.
     * Heimatzonen-maskierte Kanten sind nie in Reichweite (AC4).
     */
    private function inReach(
        array $row,
        bool $ownerIsMe,
        float $viewerPresence,
        float $hysteresis,
        ?\App\Privacy\PrivacyZone $zone,
    ): bool {
        if ($ownerIsMe) {
            return false;
        }
        if ($zone !== null) {
            $geom = json_decode((string)($row['geom_geojson'] ?? ''), true);
            $coords = is_array($geom) ? ($geom['coordinates'] ?? null) : null;
            if (is_array($coords) && $zone->intersectsPolyline($coords)) {
                return false;
            }
        }
        $ownerPresence = isset($row['owner_presence_cached']) ? (float)$row['owner_presence_cached'] : 0.0;
        return ($viewerPresence + 1.0) > ($ownerPresence * $hysteresis);
    }

    private function ageDays(string $mysqlDatetime, DateTimeImmutable $now): float
    {
        $dt = new DateTimeImmutable($mysqlDatetime, new DateTimeZone('UTC'));
        return max(0.0, ($now->getTimestamp() - $dt->getTimestamp()) / 86400.0);
    }

    /**
     * Besitz-Übersicht für weite Zooms (GameOwnershipOverview_Backend_Spec):
     * aggregiert die Spielkanten im Ausschnitt in ein Gitter und liefert pro
     * Zelle die eroberte/fremde/freie Länge + den dominanten Zustand. Pendant
     * zu /game/factions/map und /heatmap?grid=, gedacht für den Bereich, in dem
     * der Client wegen zu vieler Kanten keine Einzellinien mehr zeichnet.
     *
     * @param float|null $gridOverride Gitterweite (Grad) vom Client; fehlt sie,
     *        wählt der Server eine zoom-passende Weite
     *        (`max(min_grid, snap125(span / 40))`, gleiche Logik wie der Client
     *        via {@see MapLod::adaptiveGrid}).
     * @return array{cells:list<array<string,mixed>>}
     */
    public function ownershipMap(
        float $minLon,
        float $minLat,
        float $maxLon,
        float $maxLat,
        ?int $viewerClaimantId,
        ?float $gridOverride = null,
    ): array {
        $minGrid = $this->config->float('ownership_map_min_grid');
        if ($minGrid <= 0.0) {
            $minGrid = 0.01;
        }
        if ($gridOverride !== null && $gridOverride > 0.0) {
            $grid = $gridOverride;
        } else {
            $span = max($maxLon - $minLon, $maxLat - $minLat);
            $grid = MapLod::adaptiveGrid($span > 0.0 ? $span : null, $minGrid);
        }

        $cells = [];
        foreach ($this->repo->ownershipCellsInBbox($minLon, $minLat, $maxLon, $maxLat, $grid, $viewerClaimantId) as $c) {
            $mine   = $c['mine_length_m'];
            $others = $c['others_length_m'];
            $free   = $c['free_length_m'];
            $cells[] = [
                // SW-Eckanker der Zelle (der Client zeichnet das Quadrat aus
                // lat/lon + grid). Zellschlüssel war floor(coord/grid).
                'lat'             => round($c['cy'] * $grid, 6),
                'lon'             => round($c['cx'] * $grid, 6),
                'grid'            => $grid,
                'mine_length_m'   => round($mine, 1),
                'others_length_m' => round($others, 1),
                'free_length_m'   => round($free, 1),
                'dominant'        => self::dominantState($mine, $others, $free),
            ];
        }
        return ['cells' => $cells];
    }

    /**
     * Größter der drei Längenwerte gewinnt; bei Gleichstand Priorität
     * mine > others > free (Spec).
     */
    private static function dominantState(float $mine, float $others, float $free): string
    {
        if ($mine >= $others && $mine >= $free) {
            return 'mine';
        }
        if ($others >= $free) {
            return 'others';
        }
        return 'free';
    }

    /** @return array<string,mixed>|null */
    public function edgeDetail(int $edgeId, ?int $viewerClaimantId, ?DateTimeImmutable $now, ?int $viewerUserId = null): ?array
    {
        $now ??= Clock::nowUtc();
        $row = $this->repo->edgeById($edgeId);
        if ($row === null) {
            return null;
        }
        $base = $this->formatEdge($row, $viewerClaimantId, $now);
        $breakdown = $this->valueBreakdown($row, $now);

        // Auswärts-Multiplikator (§20) des anfragenden Nutzers — reine Anzeige.
        // Nur wenn aktiv und > 1 (Boost vorhanden); sonst Feld weglassen (iOS
        // zeigt dann nichts). Homebase/Distanz werden NICHT ausgeliefert (§20.4).
        $awayMultiplier = null;
        if ($viewerUserId !== null && $this->recalc !== null) {
            $a = $this->recalc->awayMultiplierForUser($edgeId, $viewerUserId, $now);
            if ($a > 1.0) {
                $awayMultiplier = round($a, 2);
            }
        }

        $cohort = [];
        $rank = 1;
        foreach ($this->repo->firstPassPerUser($edgeId, 10) as $c) {
            $cohort[] = [
                'rank'            => $rank++,
                'handle'          => $c['handle'],
                'first_ridden_at' => Clock::toIso8601(substr($c['first_ridden_at'], 0, 19)),
            ];
        }

        return [
            'id'                    => (int)$row['id'],
            'owner'                 => $base['owner'],
            'owner_is_me'           => $base['owner_is_me'],
            'value'                 => $breakdown,
            'distinct_riders_total' => (int)$row['distinct_riders_total'],
            'pioneer_cohort'        => $cohort,
            'freshness'             => $base['freshness'],
            'traffic_factor'        => $base['traffic_factor'],
            'traffic_class'         => $base['traffic_class'],
            'geom'                  => $base['geom'],
            'fastest'               => (object)($this->records !== null ? $this->records->fastestByClass($edgeId) : []),
            'away_multiplier'       => $awayMultiplier,
        ];
    }

    /** @return array<string,mixed> */
    public function me(int $claimantId, ?int $userId = null): array
    {
        $s = $this->repo->meStats($claimantId);
        $recordsHeld = ($userId !== null && $this->records !== null)
            ? $this->records->recordsHeld($userId)
            : 0;
        $out = [
            'held_edges'      => $s['held'],
            'pioneered_edges' => $s['pioneered'],
            'held_length_m'   => $s['held_length_m'],
            'records_held'    => $recordsHeld,
        ];

        // Wochen-Serie (GAME_EVENTS_BACKEND.md Teil 2), additiv. Trägt die Fahrt
        // (user_id, der Mensch), daher nur mit bekanntem User berechenbar; ohne
        // Fahrten = 0/false (iOS-Chip bleibt aus, kein Fehler).
        if ($userId !== null) {
            $streak = StreakCalculator::compute(
                $this->repo->distinctRideWeekMondays($userId),
                Clock::nowUtc(),
                $this->config->int('streak_grace_per_month'),
            );
            $out['streak_weeks']            = $streak['streak_weeks'];
            $out['streak_active_this_week'] = $streak['streak_active_this_week'];
            $out['longest_streak_weeks']    = $streak['longest_streak_weeks'];
            $out['streak_grace_remaining']  = $streak['streak_grace_remaining'];

            // Ränge & Abzeichen (RankBadges_Concept.md), additiv. Nutzt die
            // bereits berechneten Stats/Streak/Records (keine Doppel-Queries),
            // materialisiert neu erreichte Abzeichen-Stufen lazy.
            $progression = new PlayerProgressionService($this->repo, $this->config);
            $out += $progression->forMe(
                $userId,
                (int)$s['pioneered'],
                (float)$s['held_length_m'],
                (int)$streak['longest_streak_weeks'],
                (int)$recordsHeld,
            );
        }

        return $out;
    }

    /**
     * Pionier-Showcase (§7): die zuletzt vom Claimant erschlossenen Kanten mit
     * Geometrie + Erschließungs-Datum, für die Profil-Sektion „Pionier".
     * @return array{edges:list<array<string,mixed>>}
     */
    public function pioneeredShowcase(int $claimantId, int $limit): array
    {
        $edges = [];
        foreach ($this->repo->recentPioneeredEdges($claimantId, $limit) as $e) {
            $edges[] = [
                'id'            => $e['id'],
                'geom'          => json_decode($e['geom'], true),
                'discovered_at' => $e['discovered_at'] !== null
                    ? Clock::toIso8601(substr($e['discovered_at'], 0, 19))
                    : null,
            ];
        }
        return ['edges' => $edges];
    }

    /** @return array<string,mixed> */
    private function formatEdge(array $row, ?int $viewerClaimantId, DateTimeImmutable $now): array
    {
        $ownerId = $row['owner_claimant_id'] !== null ? (int)$row['owner_claimant_id'] : null;
        $owner = null;
        if ($ownerId !== null) {
            $info = $this->repo->claimantInfo($ownerId);
            if ($info !== null) {
                $owner = $info;
            }
        }
        $observations  = (int)($row['traffic_observations'] ?? 0);
        $trafficFactor = isset($row['traffic_factor_cached']) ? (float)$row['traffic_factor_cached'] : 1.0;

        // Gefahr (vulnerability) NUR für eigene Kanten ausliefern (Verteidigungs-
        // Sicht; fremde Gefahrwerte bleiben verborgen, sonst Angriffs-Radar).
        // 0 = sicher … 1 = Verfolger an der Übernahme-Schwelle. `at_risk` = über
        // risk_threshold. Für fremde/anonyme Sicht null → iOS zeigt nichts.
        $ownerIsMe = $ownerId !== null && $ownerId === $viewerClaimantId;
        $vulnerability = ($ownerIsMe && isset($row['vulnerability_cached']))
            ? round((float)$row['vulnerability_cached'], 3)
            : null;
        $atRisk = $vulnerability !== null
            ? ($vulnerability >= $this->config->float('risk_threshold'))
            : null;

        return [
            'id'                    => (int)$row['id'],
            'geom'                  => json_decode((string)$row['geom_geojson'], true),
            'owner'                 => $owner,
            'owner_is_me'           => $ownerIsMe,
            'value'                 => (float)$row['value_cached'],
            'freshness'             => $this->freshnessNow($row, $now),
            'distinct_riders_total' => (int)$row['distinct_riders_total'],
            'surface_character'     => $row['surface_character'] !== null ? (string)$row['surface_character'] : null,
            // Radar-Verkehr (additiv): Faktor + grobe Einstufung. Ohne Daten
            // → factor 1.0 / class "unknown" (bricht den iOS-Decoder nicht).
            'traffic_factor'        => round($trafficFactor, 3),
            'traffic_class'         => self::trafficClass($observations, $trafficFactor),
            'vulnerability'         => $vulnerability,
            'at_risk'               => $atRisk,
        ];
    }

    /**
     * Grobe Verkehrs-Einstufung für die App-Anzeige. Ohne Beobachtungen
     * "unknown"; sonst aus dem Faktor abgeleitet (>1 = leiser/bonus,
     * <1 = mehr Verkehr/malus).
     */
    private static function trafficClass(int $observations, float $factor): string
    {
        if ($observations <= 0) {
            return 'unknown';
        }
        if ($factor >= 1.05) {
            return 'quiet';
        }
        if ($factor <= 0.95) {
            return 'busy';
        }
        return 'moderate';
    }

    private function freshnessNow(array $row, DateTimeImmutable $now): float
    {
        if ($row['last_pass_at'] === null) {
            return 0.0;
        }
        $dt = new DateTimeImmutable((string)$row['last_pass_at'], new DateTimeZone('UTC'));
        $ageDays = ($now->getTimestamp() - $dt->getTimestamp()) / 86400.0;
        // min(1.0, …): future-dated last_pass (Clock-Skew) → ageDays < 0 →
        // presenceWeight > 1.0. Freshness ∈ [0,1] kappen (kosmetisch, iOS #3).
        return min(1.0, GameMath::presenceWeight($ageDays, $this->config->int('presence_window_days')));
    }

    /** @return array{total:float,pioneer:float,popularity:float,curation:float} */
    private function valueBreakdown(array $row, DateTimeImmutable $now): array
    {
        $edgeId = (int)$row['id'];
        $n = (int)$row['distinct_riders_total'];
        $windowDays = $this->config->int('presence_window_days');
        $sinceDate = $now->modify("-{$windowDays} days")->format('Y-m-d');
        $n90 = $this->repo->distinctRidersSince($edgeId, $sinceDate);

        $pioneer = GameMath::pioneer($n, $this->config->float('pioneer_p0'),
            $this->config->float('pioneer_k'), $this->config->float('pioneer_s'));
        $popularity = GameMath::popularity($n90, $this->config->float('popularity_c'));
        // Kuratierung (§5.3) — identisch zur value_cached-Berechnung im
        // EdgeRecalculator, damit die Aufschlüsselung zum Kartenwert passt.
        $curation = $this->repo->curationForEdge($edgeId, $this->config->float('curation_match_radius_m'))
            * $this->config->float('curation_per_hint');
        return [
            'total'      => GameMath::combineValue($pioneer, $popularity, $curation),
            'pioneer'    => $pioneer,
            'popularity' => $popularity,
            'curation'   => $curation,
        ];
    }

    /** @return array{0:float,1:float,2:float,3:float} [minLon,minLat,maxLon,maxLat] */
    private function parseBbox(string $bbox): array
    {
        $parts = array_map('floatval', explode(',', $bbox));
        if (count($parts) !== 4) {
            throw new \InvalidArgumentException('bbox erwartet minLon,minLat,maxLon,maxLat');
        }
        return [$parts[0], $parts[1], $parts[2], $parts[3]];
    }
}
