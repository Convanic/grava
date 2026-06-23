<?php
declare(strict_types=1);

namespace App\Game;

use App\Routes\GeometryStats;
use App\Routes\ParsedPoint;

/**
 * Teilt eine lange Route in überlappende Stücke entlang der KUMULIERTEN
 * Distanz — Voraussetzung fürs gechunkte Map-Matching langer Fahrten
 * (Valhalla-Punktlimit / Robustheit, siehe GAME_INGEST_CHUNKING_BACKEND).
 *
 * - Schnittgröße: `chunkSizeM` (Default-Config 50 km).
 * - Naht-Überlappung: `overlapM` (Default 500 m) — der nächste Chunk beginnt
 *   ~`overlapM` VOR dem letzten Schnitt, damit keine Grenz-Kante zwischen zwei
 *   Chunks verloren geht. Doppelt gematchte Naht-Kanten sind unkritisch:
 *   Kanten sind über die OSM-Edge-ID eindeutig, Pässe über den Tages-Deckel.
 *
 * Liefert inklusive Index-Bereiche `[startIndex, endIndex]` in die übergebene
 * Punktliste. Rein, ohne I/O → unmittelbar unit-testbar.
 */
final class RouteChunker
{
    /**
     * @param list<ParsedPoint> $points
     * @return list<array{0:int,1:int}> inklusive [start,end]-Index-Bereiche
     */
    public static function chunk(array $points, float $chunkSizeM, float $overlapM): array
    {
        $n = count($points);
        if ($n === 0) {
            return [];
        }
        if ($n === 1) {
            return [[0, 0]];
        }
        // Kein/ungültiger Schnitt → ein Chunk über die ganze Route.
        if ($chunkSizeM <= 0.0) {
            return [[0, $n - 1]];
        }
        $overlapM = max(0.0, $overlapM);

        $chunks = [];
        $i = 0;
        while (true) {
            // Vorwärts akkumulieren, bis chunkSizeM erreicht ist oder das Ende.
            $dist = 0.0;
            $j = $i;
            while ($j < $n - 1 && $dist < $chunkSizeM) {
                $dist += self::dist($points[$j], $points[$j + 1]);
                $j++;
            }
            $chunks[] = [$i, $j];
            if ($j >= $n - 1) {
                break;
            }
            // Nahtüberlappung: vom Schnitt $j um ~overlapM zurückgehen.
            $back = $j;
            $od = 0.0;
            while ($back > $i + 1 && $od < $overlapM) {
                $od += self::dist($points[$back - 1], $points[$back]);
                $back--;
            }
            // Fortschritt garantieren (mind. +1), sonst Endlosschleife.
            $i = max($i + 1, $back);
        }
        return $chunks;
    }

    private static function dist(ParsedPoint $a, ParsedPoint $b): float
    {
        return GeometryStats::haversine($a->lat, $a->lon, $b->lat, $b->lon);
    }
}
