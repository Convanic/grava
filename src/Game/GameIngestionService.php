<?php
declare(strict_types=1);

namespace App\Game;

use App\Privacy\PrivacyZoneRepository;
use App\Routes\ParsedRoute;
use App\Routes\RadarTrafficData;
use App\Support\Clock;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Orchestriert die Spiel-Ingestion einer Route (Spec §4–§5):
 * Match → Authentizitäts-Filter → Pass (idempotent, tagesgedeckelt) →
 * Pionier-Buchung → Live-Recompute der berührten Kanten.
 *
 * Wirft der Matcher (Valhalla-Ausfall), propagiert die Exception nach
 * oben — der nicht-blockierende Upload-Hook fängt sie (Spec §10.9).
 */
final class GameIngestionService
{
    /**
     * Routen-Quellen, die als authentisch gelten und den Motion-Authentizitäts-
     * Filter (§ `auth_require_motion`) umgehen dürfen: Strava-/GPX-Importe haben
     * keine Motion-Samples, gelten vorerst aber als „echt" und sollen Besitz
     * beanspruchen können. (Cheat-Schutz folgt später.)
     */
    public const TRUSTED_SOURCES = ['strava', 'import'];

    public static function isTrustedSource(?string $source): bool
    {
        return $source !== null && in_array($source, self::TRUSTED_SOURCES, true);
    }

    public function __construct(
        private readonly EdgeMatcher $matcher,
        private readonly GameRepository $repo,
        private readonly EdgeRecalculator $recalc,
        private readonly GameConfig $config,
        private readonly PDO $pdo,
        private readonly ?TerritoryTakeoverNotifier $takeovers = null,
        private readonly ?PrivacyZoneRepository $privacyZones = null,
    ) {}

    /**
     * @return array{matched:int,passes_new:int,skipped_day_cap:int,
     *               skipped_auth_speed:int,skipped_auth_hacc:int,skipped_no_motion:int,
     *               skipped_privacy_zone:int,banned:bool,
     *               chunks_total:int,chunks_ok:int,chunks_failed:int,
     *               efforts_new:int,skipped_effort_no_timing:int,
     *               skipped_effort_short:int,skipped_effort_implausible:int}
     */
    public function ingest(
        int $routeId,
        int $userId,
        ParsedRoute $route,
        bool $sourceHasMotion,
        ?DateTimeImmutable $now = null,
        ?RadarTrafficData $radar = null,
        bool $bypassMotionAuth = false,
    ): array {
        $now ??= Clock::nowUtc();
        $summary = [
            'matched' => 0, 'passes_new' => 0, 'skipped_day_cap' => 0,
            'skipped_auth_speed' => 0, 'skipped_auth_hacc' => 0, 'skipped_no_motion' => 0,
            'skipped_privacy_zone' => 0,
            'banned' => false,
            'chunks_total' => 0, 'chunks_ok' => 0, 'chunks_failed' => 0,
            'efforts_new' => 0, 'skipped_effort_no_timing' => 0,
            'skipped_effort_short' => 0, 'skipped_effort_implausible' => 0,
        ];

        if ($this->repo->isUserBanned($userId)) {
            $summary['banned'] = true;
            $this->repo->insertIngestLog($routeId, $userId, 'ok', 0, 0, ['banned' => 1], null, 0);
            return $summary;
        }
        $startedAt = microtime(true);

        // Privacy: Privatzone des Fahrers (§17). Segmente in der Zone werden
        // gar nicht erst zu Kanten/Pässen — wirkt absolut (auch für den
        // Besitzer selbst), da Reviere öffentlich sind.
        $privacyZone = $this->privacyZones?->enabledZoneForUser($userId);

        // Lange Fahrten in ~50-km-Stücke (überlappend) matchen und die Segmente
        // zusammenführen. Dedup passiert automatisch downstream (Edge-Unique +
        // Tages-Deckel), die Naht-Überlappung verhindert verlorene Grenz-Kanten.
        $segments = $this->matchChunked($route, $summary);
        $summary['matched'] = count($segments);
        if ($segments === []) {
            $this->logOk($routeId, $userId, $summary, $startedAt);
            return $summary;
        }

        $claimantId = $this->repo->riderClaimantId($userId);
        $minSpeed = $this->config->float('auth_min_speed_kmh');
        $maxHacc = $this->config->float('auth_max_hacc_m');
        $requireMotion = $this->config->bool('auth_require_motion');

        // Tempo-Wertung (GAME_SEGMENT_SPEED_BACKEND): Schwellen für Efforts.
        $effortMinLength = $this->config->float('segment_min_length_m');
        $effortMinSpeed  = $this->config->float('segment_min_speed_kmh');
        $effortMaxSpeed  = $this->config->float('segment_max_speed_kmh');

        $touched = [];
        $edgeGeoms = []; // [edgeId => list<[lon,lat]>] für das Radar-Map-Matching
        $this->pdo->beginTransaction();
        try {
            foreach ($segments as $seg) {
                // Vertrauenswürdige Quellen (Strava-/GPX-Import) tragen keine
                // Motion-/Surface-Samples, gelten aber vorerst als „echt" und
                // sollen Besitz beanspruchen können — der Motion-Authentizitäts-
                // Filter wird für sie übersprungen (Cheat-Schutz später).
                if ($requireMotion && !$bypassMotionAuth && (!$sourceHasMotion || !$seg->hasMotion)) {
                    $summary['skipped_no_motion']++;
                    continue;
                }
                if ($seg->avgSpeedKmh !== null && $seg->avgSpeedKmh < $minSpeed) {
                    $summary['skipped_auth_speed']++;
                    continue;
                }
                if ($seg->maxHaccM !== null && $seg->maxHaccM > $maxHacc) {
                    $summary['skipped_auth_hacc']++;
                    continue;
                }

                $geom = $seg->geometry;
                if ($privacyZone !== null && $privacyZone->intersectsPolyline($geom)) {
                    $summary['skipped_privacy_zone']++;
                    continue;
                }
                $first = $geom[0];
                $last = $geom[count($geom) - 1];
                $aId = $this->repo->upsertNode($seg->nodeARef, (float)$first[1], (float)$first[0]);
                $bId = $this->repo->upsertNode($seg->nodeBRef, (float)$last[1], (float)$last[0]);
                if ($aId > $bId) {
                    [$aId, $bId] = [$bId, $aId];
                    $geom = array_reverse($geom);
                }
                [$minLat, $minLon, $maxLat, $maxLon] = $this->bbox($geom);
                $geomJson = json_encode(
                    ['type' => 'LineString', 'coordinates' => $geom],
                    JSON_THROW_ON_ERROR,
                );
                $edgeId = $this->repo->upsertEdge(
                    $seg->wayId, $aId, $bId, $seg->lengthM, $geomJson, $seg->surface,
                    $minLat, $minLon, $maxLat, $maxLon,
                );

                $riddenOn = $seg->riddenAt->format('Y-m-d');
                $riddenAt = $seg->riddenAt->format('Y-m-d H:i:s.v');
                if ($this->repo->insertPassIfAbsent($edgeId, $claimantId, $userId, $routeId, $riddenOn, $riddenAt)) {
                    $summary['passes_new']++;
                } else {
                    $summary['skipped_day_cap']++;
                }

                // Tempo-Wertung: jede getimte, plausible Befahrung wird als Effort
                // gebucht — UNABHÄNGIG vom Tages-Deckel des Passes (eine zweite,
                // schnellere Fahrt am selben Tag soll zählen).
                $this->recordEffort(
                    $seg, $edgeId, $claimantId, $userId, $routeId, $riddenAt,
                    $effortMinLength, $effortMinSpeed, $effortMaxSpeed, $summary,
                );

                $touched[$edgeId] = true;
                $edgeGeoms[$edgeId] = $geom;
            }

            // Radar-Verkehr: Vorbeifahrten den befahrenen Kanten zuordnen.
            // Eine Fahrt mit aktivem Radar erzeugt für JEDE befahrene Kante
            // eine Beobachtung (pass_count ggf. 0 = leise) — Quelle der
            // Wahrheit ist game_edge_traffic, der Faktor entsteht im Recompute.
            if ($radar !== null && $radar->hasRadar() && $edgeGeoms !== []) {
                $this->recordTraffic($routeId, $radar, $edgeGeoms);
            }

            // Welle 2 territory_taken: Besitzer VOR dem Recompute merken.
            $edgeIds = array_keys($touched);
            $prevOwners = $this->takeovers !== null ? $this->repo->ownersForEdges($edgeIds) : [];

            foreach ($edgeIds as $edgeId) {
                $this->repo->refreshEdgeDiscovery($edgeId);
                $this->recalc->recalculate($edgeId, $now);
            }

            $newOwners = $this->takeovers !== null ? $this->repo->ownersForEdges($edgeIds) : [];

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // Übernahme-Benachrichtigungen NACH dem Commit (best effort: weder
        // Notification-Insert noch APNs-Versand dürfen die Ingestion abbrechen).
        if ($this->takeovers !== null) {
            try {
                $this->takeovers->notify($prevOwners, $newOwners, $userId);
            } catch (Throwable $e) {
                error_log('territory_taken: ' . $e->getMessage());
            }
        }

        $this->logOk($routeId, $userId, $summary, $startedAt);
        return $summary;
    }

    /**
     * Matcht die Route gechunkt (lange Fahrten) und führt die Segmente
     * dedupliziert zusammen. Pro Chunk ein Matcher-Aufruf; ein einzelner
     * fehlschlagender Chunk (Valhalla nicht erreichbar) verwirft NICHT die
     * ganze Fahrt — nur wenn ALLE Chunks scheitern, gilt die Engine als unten
     * und es wird (retrybar) MatchUnavailableException geworfen.
     *
     * Naht-Überlappung erzeugt doppelt gematchte Grenz-Kanten; die werden hier
     * über (way_id, sortierte Knoten-Refs) zusammengeführt, damit Zähler/Arbeit
     * sauber bleiben (Day-Cap würde sie sonst nur als „skip" verbuchen).
     *
     * @param array{chunks_total:int,chunks_ok:int,chunks_failed:int,...} $summary
     * @return list<MatchedSegment>
     */
    private function matchChunked(ParsedRoute $route, array &$summary): array
    {
        $ranges = RouteChunker::chunk(
            $route->points,
            $this->config->float('game_chunk_size_m'),
            $this->config->float('game_chunk_overlap_m'),
        );
        $summary['chunks_total'] = count($ranges);

        $merged = [];
        $seen = [];
        $failed = 0;
        foreach ($ranges as [$start, $end]) {
            $sub = new ParsedRoute(
                points: array_slice($route->points, $start, $end - $start + 1),
                sourceFormat: $route->sourceFormat,
                startedAt: $route->startedAt,
                endedAt: $route->endedAt,
                elevationGainOverrideM: $route->elevationGainOverrideM,
            );
            try {
                $segs = $this->matcher->match($sub);
            } catch (MatchUnavailableException $e) {
                // Engine für diesen Chunk nicht erreichbar → Teilerfolg: weiter.
                $failed++;
                continue;
            }
            $summary['chunks_ok']++;
            foreach ($segs as $seg) {
                $a = min($seg->nodeARef, $seg->nodeBRef);
                $b = max($seg->nodeARef, $seg->nodeBRef);
                $key = $seg->wayId . ':' . $a . ':' . $b;
                if (isset($seen[$key])) {
                    continue; // doppelt gematchte Naht-Kante
                }
                $seen[$key] = true;
                $merged[] = $seg;
            }
        }
        $summary['chunks_failed'] = $failed;

        // Nur wenn KEIN Chunk durchkam (Engine wirklich unten) → retrybar melden.
        if ($ranges !== [] && $failed === count($ranges)) {
            throw new MatchUnavailableException(
                "Map-Matching fehlgeschlagen: alle {$failed} Chunk(s) nicht matchbar (Engine nicht erreichbar)."
            );
        }
        return $merged;
    }

    /**
     * Bucht einen Segment-Effort (Tempo-Wertung), sofern die Befahrung getimt und
     * plausibel ist. Gates per Config; nur ein Skip-Grund pro Segment. Der Pass
     * hat die Auth-Filter bereits passiert, wenn wir hier sind.
     *
     * @param array<string,mixed> $summary
     */
    private function recordEffort(
        MatchedSegment $seg,
        int $edgeId,
        int $claimantId,
        int $userId,
        int $routeId,
        string $riddenAt,
        float $minLength,
        float $minSpeed,
        float $maxSpeed,
        array &$summary,
    ): void {
        // Ohne Timing (z. B. Strava-/GPX-Import) keine Tempo-Wertung.
        if (!$seg->hasMotion || $seg->avgSpeedKmh === null) {
            $summary['skipped_effort_no_timing']++;
            return;
        }
        if ($seg->lengthM < $minLength) {
            $summary['skipped_effort_short']++;
            return;
        }
        $speed = $seg->avgSpeedKmh;
        if ($speed < $minSpeed || $speed > $maxSpeed) {
            $summary['skipped_effort_implausible']++;
            return;
        }

        // Dauer aus dem Matcher (Ein-/Austritt) oder aus Länge/Tempo abgeleitet.
        $duration = $seg->durationS ?? ($seg->lengthM / ($speed / 3.6));
        if ($duration <= 0.0) {
            $summary['skipped_effort_implausible']++;
            return;
        }

        $this->repo->insertSegmentEffort(
            $edgeId, $claimantId, $userId, $routeId, $riddenAt,
            $duration, $speed, $seg->lengthM,
        );
        $summary['efforts_new']++;
    }

    /** @param array{matched:int,passes_new:int,skipped_day_cap:int,skipped_auth_speed:int,skipped_auth_hacc:int,skipped_no_motion:int,skipped_privacy_zone:int,banned:bool,chunks_total:int,chunks_ok:int,chunks_failed:int,efforts_new:int,skipped_effort_no_timing:int,skipped_effort_short:int,skipped_effort_implausible:int} $summary */
    private function logOk(int $routeId, int $userId, array $summary, float $startedAt): void
    {
        $this->repo->insertIngestLog(
            $routeId,
            $userId,
            'ok',
            $summary['matched'],
            $summary['passes_new'],
            [
                'day_cap'      => $summary['skipped_day_cap'],
                'auth_speed'   => $summary['skipped_auth_speed'],
                'auth_hacc'    => $summary['skipped_auth_hacc'],
                'no_motion'    => $summary['skipped_no_motion'],
                'privacy_zone' => $summary['skipped_privacy_zone'],
                'chunks_total' => $summary['chunks_total'],
                'chunks_ok'    => $summary['chunks_ok'],
                'chunks_failed' => $summary['chunks_failed'],
                'efforts_new'  => $summary['efforts_new'],
                'effort_no_timing'   => $summary['skipped_effort_no_timing'],
                'effort_short'       => $summary['skipped_effort_short'],
                'effort_implausible' => $summary['skipped_effort_implausible'],
            ],
            null,
            (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    /** @param list<array{0:float,1:float}> $geom @return array{0:float,1:float,2:float,3:float} */
    private function bbox(array $geom): array
    {
        $lons = array_map(static fn($c) => (float)$c[0], $geom);
        $lats = array_map(static fn($c) => (float)$c[1], $geom);
        return [min($lats), min($lons), max($lats), max($lons)];
    }

    /**
     * Ordnet jede Vorbeifahrt der nächstgelegenen befahrenen Kante zu (innerhalb
     * der Toleranz) und schreibt pro Kante eine game_edge_traffic-Zeile — auch
     * für Kanten mit 0 zugeordneten Pässen (leise Beobachtung).
     *
     * @param array<int,list<array{0:float,1:float}>> $edgeGeoms [edgeId => [lon,lat]…]
     */
    private function recordTraffic(int $routeId, RadarTrafficData $radar, array $edgeGeoms): void
    {
        $maxDist = $this->config->float('traffic_match_max_dist_m');
        $passCounts = array_fill_keys(array_keys($edgeGeoms), 0);

        foreach ($radar->passes as [$plat, $plon]) {
            $bestEdge = null;
            $bestDist = INF;
            foreach ($edgeGeoms as $edgeId => $geom) {
                $d = self::pointToPolylineMeters($plat, $plon, $geom);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestEdge = $edgeId;
                }
            }
            if ($bestEdge !== null && $bestDist <= $maxDist) {
                $passCounts[$bestEdge]++;
            }
        }

        foreach ($passCounts as $edgeId => $count) {
            $this->repo->upsertEdgeTraffic((int)$edgeId, $routeId, $count);
        }
    }

    /**
     * Kürzeste Distanz (Meter) eines Punkts zu einem Polylinienzug. Lokale
     * equirektanguläre Projektion — für die kleinen Distanzen (< ~100 m) beim
     * Radar-Matching mehr als genau genug.
     *
     * @param list<array{0:float,1:float}> $geom Stützpunkte als [lon, lat]
     */
    private static function pointToPolylineMeters(float $lat, float $lon, array $geom): float
    {
        $n = count($geom);
        if ($n === 0) {
            return INF;
        }
        $mPerDegLat = 111320.0;
        $mPerDegLon = 111320.0 * cos(deg2rad($lat));
        $px = 0.0; // Referenzpunkt = der Pass selbst → (0,0)
        $py = 0.0;
        $project = static function (array $c) use ($lat, $lon, $mPerDegLat, $mPerDegLon): array {
            return [
                ((float)$c[0] - $lon) * $mPerDegLon, // x: lon-Differenz
                ((float)$c[1] - $lat) * $mPerDegLat, // y: lat-Differenz
            ];
        };

        if ($n === 1) {
            [$x, $y] = $project($geom[0]);
            return sqrt($x * $x + $y * $y);
        }

        $best = INF;
        for ($i = 0; $i < $n - 1; $i++) {
            [$ax, $ay] = $project($geom[$i]);
            [$bx, $by] = $project($geom[$i + 1]);
            $best = min($best, self::pointToSegmentMeters($px, $py, $ax, $ay, $bx, $by));
        }
        return $best;
    }

    private static function pointToSegmentMeters(
        float $px, float $py, float $ax, float $ay, float $bx, float $by,
    ): float {
        $dx = $bx - $ax;
        $dy = $by - $ay;
        $lenSq = $dx * $dx + $dy * $dy;
        if ($lenSq <= 0.0) {
            $ex = $px - $ax;
            $ey = $py - $ay;
            return sqrt($ex * $ex + $ey * $ey);
        }
        $t = (($px - $ax) * $dx + ($py - $ay) * $dy) / $lenSq;
        $t = max(0.0, min(1.0, $t));
        $cx = $ax + $t * $dx;
        $cy = $ay + $t * $dy;
        $ex = $px - $cx;
        $ey = $py - $cy;
        return sqrt($ex * $ex + $ey * $ey);
    }
}
