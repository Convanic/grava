<?php
declare(strict_types=1);

namespace App\Game\Faction;

use App\Game\Admin\GameAuditService;
use App\Game\Crew\CrewRepository;
use App\Game\Crew\CrewService;
use App\Game\GameConfig;
use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Fraktions-Logik (Stufe 3, GAME_STAGE3_BACKEND.md):
 *  - Beitritt/Wechsel/Verlassen einer Fraktion (nur Captain, mit Cooldown).
 *  - Meta-Karte: pro Zelle gewinnt die Fraktion mit der meisten gehaltenen
 *    Kantenlänge.
 *  - Standings: Gesamtstand je Fraktion.
 *
 * Fraktionswechsel ändern den Kantenbesitz NICHT → KEIN Edge-Recompute
 * (Spec §2). Die Meta-Karte wird beim Lesen aus aktuellem Besitz berechnet.
 */
final class FactionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CrewRepository $crews,
        private readonly FactionRepository $factions,
        private readonly CrewService $crewService,
        private readonly GameConfig $config,
        private readonly GameAuditService $audit,
    ) {}

    /**
     * Captain setzt/wechselt die Fraktion seiner Crew.
     *
     * @return array<string,mixed> Crew-Payload (mit Fraktion)
     */
    public function setFaction(int $userId, string $slug, string $factionKey, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::nowUtc();
        $factionKey = strtolower(trim($factionKey));
        $faction = $this->factions->byKey($factionKey);
        if ($faction === null) {
            throw FactionException::validation('Unbekannte Fraktion.');
        }

        return $this->transactional(function () use ($userId, $slug, $faction, $now): array {
            $crew = $this->captainCrew($userId, $slug);
            $this->ensureChangeAllowed($crew, $now);

            $this->factions->setCrewFaction((int)$crew['id'], $faction['id'], $now->format('Y-m-d H:i:s.v'));
            $this->audit->record($userId, 'crew_faction', (string)$crew['slug'], ['faction' => $faction['key']]);
            return $this->crewService->me($userId);
        });
    }

    /**
     * Captain setzt die Crew zurück auf neutral. Unterliegt demselben
     * Cooldown wie ein Wechsel (verhindert Cooldown-Umgehung über den Umweg
     * "neutral → andere Fraktion"). Setzt faction_joined_at = now, damit ein
     * direkt folgender Beitritt ebenfalls gesperrt ist.
     *
     * @return array<string,mixed>
     */
    public function clearFaction(int $userId, string $slug, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::nowUtc();
        return $this->transactional(function () use ($userId, $slug, $now): array {
            $crew = $this->captainCrew($userId, $slug);
            if ($crew['faction_id'] === null) {
                // bereits neutral → idempotent, kein Cooldown
                return $this->crewService->me($userId);
            }
            $this->ensureChangeAllowed($crew, $now);
            $this->factions->setCrewFaction((int)$crew['id'], null, $now->format('Y-m-d H:i:s.v'));
            $this->audit->record($userId, 'crew_faction', (string)$crew['slug'], ['faction' => null]);
            return $this->crewService->me($userId);
        });
    }

    /**
     * Meta-Karte: pro Zelle gewinnt die Fraktion mit der meisten gehaltenen
     * Kantenlänge. Zellen ohne fraktions-gebundene Kanten werden weggelassen.
     *
     * @param float|null $gridOverride Optionale Gitterweite (Grad), sonst der
     *        Config-Default. Erlaubt es dem Client, bei weiten Zooms gröbere
     *        Zellen anzufordern (weniger Payload).
     * @return array{cells:list<array<string,mixed>>}
     */
    public function map(float $minLon, float $minLat, float $maxLon, float $maxLat, ?float $gridOverride = null): array
    {
        $grid  = ($gridOverride !== null && $gridOverride > 0.0) ? $gridOverride : $this->gridSize();
        $edges = $this->factions->edgesWithFaction($minLon, $minLat, $maxLon, $maxLat);
        $cells = [];
        foreach ($this->aggregateCells($edges, $grid) as $cell) {
            $cells[] = [
                'lat'      => $cell['lat'],
                'lon'      => $cell['lon'],
                'grid'     => $grid,
                'faction'  => $cell['winner_key'],
                'color'    => $cell['winner_color'],
                'strength' => $cell['strength'],
            ];
        }
        return ['cells' => $cells];
    }

    /**
     * Gesamtstand je Fraktion (öffentlich, für ein Leaderboard).
     *
     * @return array{factions:list<array<string,mixed>>}
     */
    public function standings(): array
    {
        $counts   = $this->factions->crewMemberCounts();
        $allEdges = $this->factions->edgesWithFaction();
        $grid     = $this->gridSize();

        // Länge je Fraktion + Zellen-Gewinne je Fraktion.
        $lengthByFid = [];
        foreach ($allEdges as $e) {
            $lengthByFid[$e['faction_id']] = ($lengthByFid[$e['faction_id']] ?? 0.0) + $e['length_m'];
        }
        $cellsByFid = [];
        foreach ($this->aggregateCells($allEdges, $grid) as $cell) {
            $cellsByFid[$cell['winner_id']] = ($cellsByFid[$cell['winner_id']] ?? 0) + 1;
        }

        $out = [];
        foreach ($this->factions->all() as $f) {
            $fid = $f['id'];
            $out[] = [
                'key'           => $f['key'],
                'name'          => $f['name'],
                'color'         => $f['color'],
                'crews'         => $counts[$fid]['crews']   ?? 0,
                'members'       => $counts[$fid]['members'] ?? 0,
                'held_length_m' => round($lengthByFid[$fid] ?? 0.0, 1),
                'cells'         => $cellsByFid[$fid] ?? 0,
            ];
        }
        return ['factions' => $out];
    }

    // ----------------------------------------------------------------
    // intern
    // ----------------------------------------------------------------

    /** @return array<string,mixed> Crew-Row, wenn $userId Captain der Slug-Crew ist. */
    private function captainCrew(int $userId, string $slug): array
    {
        $crew = $this->crews->crewBySlug(trim($slug));
        if ($crew === null) {
            throw FactionException::notFound('Crew nicht gefunden.');
        }
        $membership = $this->crews->membershipOf($userId);
        if ($membership === null || $membership['crew_id'] !== (int)$crew['id']) {
            throw FactionException::forbidden('Du bist nicht Mitglied dieser Crew.');
        }
        if ($membership['role'] !== 'captain') {
            throw FactionException::forbidden();
        }
        return $crew;
    }

    /** Wirft 409 faction_cooldown, wenn der letzte Wechsel noch zu jung ist. */
    private function ensureChangeAllowed(array $crew, DateTimeImmutable $now): void
    {
        $allowedAt = self::changeAllowedAt(
            $crew['faction_joined_at'] !== null ? (string)$crew['faction_joined_at'] : null,
            $this->config->int('faction_switch_cooldown_days'),
        );
        if ($allowedAt !== null && $now < $allowedAt) {
            throw FactionException::cooldown(Clock::toIso8601($allowedAt->format('Y-m-d H:i:s')));
        }
    }

    /**
     * Zeitpunkt, ab dem ein Wechsel wieder erlaubt ist — oder null, wenn nie
     * gewechselt wurde (Erstwahl jederzeit). Public für die Crew-JSON.
     */
    public static function changeAllowedAt(?string $joinedAtMysql, int $cooldownDays): ?DateTimeImmutable
    {
        if ($joinedAtMysql === null || $joinedAtMysql === '') {
            return null;
        }
        return (new DateTimeImmutable($joinedAtMysql, new DateTimeZone('UTC')))
            ->modify("+{$cooldownDays} days");
    }

    private function gridSize(): float
    {
        $grid = $this->config->float('faction_map_grid');
        return $grid > 0.0 ? $grid : 0.05;
    }

    /**
     * Aggregiert Kanten in Heatmap-artige Zellen und bestimmt je Zelle die
     * stärkste Fraktion (mehr Kantenlänge). Gleichstand: niedrigere
     * faction_id gewinnt (deterministisch, green vor blue).
     *
     * @param list<array{length_m:float,lat:float,lon:float,key:string,color:string,faction_id:int}> $edges
     * @return list<array{lat:float,lon:float,strength:array<string,float>,winner_key:string,winner_color:string,winner_id:int}>
     */
    private function aggregateCells(array $edges, float $grid): array
    {
        /** @var array<string,array{lat:float,lon:float,byFid:array<int,float>,key:array<int,string>,color:array<int,string>}> $cells */
        $cells = [];
        foreach ($edges as $e) {
            $clat = round($e['lat'] / $grid) * $grid;
            $clon = round($e['lon'] / $grid) * $grid;
            $key  = $clat . '|' . $clon;
            if (!isset($cells[$key])) {
                $cells[$key] = ['lat' => $clat, 'lon' => $clon, 'byFid' => [], 'key' => [], 'color' => []];
            }
            $fid = $e['faction_id'];
            $cells[$key]['byFid'][$fid] = ($cells[$key]['byFid'][$fid] ?? 0.0) + $e['length_m'];
            $cells[$key]['key'][$fid]   = $e['key'];
            $cells[$key]['color'][$fid] = $e['color'];
        }

        $out = [];
        foreach ($cells as $cell) {
            $winnerFid = null;
            $winnerLen = -1.0;
            // ksort → niedrigere faction_id zuerst ⇒ Gleichstand: green vor blue.
            ksort($cell['byFid']);
            foreach ($cell['byFid'] as $fid => $len) {
                if ($len > $winnerLen) {
                    $winnerLen = $len;
                    $winnerFid = $fid;
                }
            }
            $strength = [];
            foreach ($cell['byFid'] as $fid => $len) {
                $strength[$cell['key'][$fid]] = round($len, 1);
            }
            $out[] = [
                'lat'          => round((float)$cell['lat'], 6),
                'lon'          => round((float)$cell['lon'], 6),
                'strength'     => $strength,
                'winner_key'   => $cell['key'][$winnerFid],
                'winner_color' => $cell['color'][$winnerFid],
                'winner_id'    => (int)$winnerFid,
            ];
        }
        return $out;
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function transactional(callable $fn): mixed
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $fn();
            if ($own) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
