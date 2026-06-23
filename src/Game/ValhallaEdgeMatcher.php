<?php
declare(strict_types=1);

namespace App\Game;

use App\Heatmap\ValhallaClient;
use App\Routes\ParsedRoute;

/**
 * Echt-Adapter: nutzt den bestehenden ValhallaClient (trace_attributes) und
 * leitet daraus MatchedSegment inkl. Auth-Aggregate ab.
 *
 * Knoten-Identität: Valhalla liefert keine OSM-Node-IDs im Default-Response.
 * Wir bilden einen stabilen Integer-Knoten-Ref aus den gerundeten
 * Endkoordinaten (crc32) — zwei Kanten am selben Knoten teilen so denselben
 * Ref. Surrogat für Stufe 1; Details in backend/VALHALLA_SETUP.md.
 */
final class ValhallaEdgeMatcher implements EdgeMatcher
{
    public function __construct(private readonly ValhallaClient $client) {}

    public function match(ParsedRoute $route): array
    {
        $points = [];
        foreach ($route->points as $p) {
            $points[] = ['lat' => $p->lat, 'lon' => $p->lon];
        }
        $match = $this->client->matchTrace($points);
        if ($match === null) {
            throw new MatchUnavailableException('Valhalla-Match fehlgeschlagen oder nicht erreichbar.');
        }

        $hasMotion = $route->startedAt !== null;
        $segments = [];
        foreach ($match->edges as $j => $edge) {
            if ($edge->wayId === null || count($edge->geometry) < 2) {
                continue;
            }
            $geom = $edge->geometry;
            $first = $geom[0];
            $last = $geom[count($geom) - 1];

            $idxs = [];
            foreach ($match->matchedPoints as $i => $mp) {
                if (($mp['edgeIndex'] ?? -1) === $j) {
                    $idxs[] = $i;
                }
            }

            $riddenAt = $route->startedAt ?? \App\Support\Clock::nowUtc();
            $maxHacc = null;
            $firstTs = null;
            $lastTs = null;
            foreach ($idxs as $i) {
                $pt = $route->points[$i] ?? null;
                if ($pt === null) {
                    continue;
                }
                if ($pt->timestamp !== null) {
                    $firstTs ??= $pt->timestamp;
                    $lastTs = $pt->timestamp;
                }
                if ($pt->horizontalAccuracyM !== null) {
                    $maxHacc = $maxHacc === null ? $pt->horizontalAccuracyM : max($maxHacc, $pt->horizontalAccuracyM);
                }
            }
            if ($firstTs !== null) {
                $riddenAt = $firstTs;
            }

            $avgSpeedKmh = null;
            if ($firstTs !== null && $lastTs !== null) {
                $dt = $lastTs->getTimestamp() - $firstTs->getTimestamp();
                if ($dt > 0) {
                    $avgSpeedKmh = ($edge->lengthM / $dt) * 3.6;
                }
            }

            $segments[] = new MatchedSegment(
                wayId: $edge->wayId,
                nodeARef: $this->nodeRef($first[0], $first[1]),
                nodeBRef: $this->nodeRef($last[0], $last[1]),
                lengthM: $edge->lengthM,
                geometry: $geom,
                surface: $edge->surface,
                avgSpeedKmh: $avgSpeedKmh,
                maxHaccM: $maxHacc,
                hasMotion: $hasMotion,
                riddenAt: $riddenAt,
            );
        }
        return $segments;
    }

    private function nodeRef(float $lon, float $lat): int
    {
        $key = round($lat, 5) . ':' . round($lon, 5);
        return (int)sprintf('%u', crc32($key));
    }
}
