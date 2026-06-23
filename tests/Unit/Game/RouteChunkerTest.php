<?php
declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\RouteChunker;
use App\Routes\GeometryStats;
use App\Routes\ParsedPoint;
use PHPUnit\Framework\TestCase;

final class RouteChunkerTest extends TestCase
{
    /** Punkte entlang eines Meridians (lon fix), ~stepLat Grad Abstand. */
    private function line(int $n, float $stepLat = 0.009): array
    {
        $pts = [];
        for ($k = 0; $k < $n; $k++) {
            $pts[] = new ParsedPoint(lat: 47.0 + $k * $stepLat, lon: 9.0, elevationM: null, timestamp: null);
        }
        return $pts;
    }

    public function testEmptyAndSingle(): void
    {
        $this->assertSame([], RouteChunker::chunk([], 50000, 500));
        $this->assertSame([[0, 0]], RouteChunker::chunk($this->line(1), 50000, 500));
    }

    public function testShortRouteIsOneChunk(): void
    {
        // 3 Punkte ~1 km Abstand → ~2 km Gesamt < 50 km → ein Chunk über alles.
        $chunks = RouteChunker::chunk($this->line(3), 50000, 500);
        $this->assertSame([[0, 2]], $chunks);
    }

    public function testZeroChunkSizeIsOneChunk(): void
    {
        $this->assertSame([[0, 9]], RouteChunker::chunk($this->line(10), 0, 500));
    }

    public function testLongRouteSplitsWithOverlapAndFullCoverage(): void
    {
        // ~130 km (130 Punkte je ~1 km) bei 50-km-Chunks → mehrere Stücke.
        $n = 130;
        $pts = $this->line($n);
        $chunks = RouteChunker::chunk($pts, 50000, 500);

        $this->assertGreaterThanOrEqual(3, count($chunks), 'erwartet >= 3 Chunks bei ~130 km / 50 km');

        // Lückenlose Abdeckung: Start bei 0, Ende bei n-1.
        $this->assertSame(0, $chunks[0][0]);
        $this->assertSame($n - 1, $chunks[count($chunks) - 1][1]);

        $prevEnd = null;
        foreach ($chunks as [$s, $e]) {
            $this->assertLessThanOrEqual($e, $s);
            if ($prevEnd !== null) {
                // Fortschritt UND Überlappung: nächster Start liegt VOR dem
                // letzten Schnitt (Naht), aber echt weiter als der vorige Start.
                $this->assertLessThan($prevEnd, $s, 'Chunks müssen sich an der Naht überlappen');
            }
            $prevEnd = $e;
        }

        // Jeder Chunk (außer ggf. dem letzten Rest) ist ~50 km lang.
        [$s0, $e0] = $chunks[0];
        $len0 = 0.0;
        for ($k = $s0; $k < $e0; $k++) {
            $len0 += GeometryStats::haversine($pts[$k]->lat, $pts[$k]->lon, $pts[$k + 1]->lat, $pts[$k + 1]->lon);
        }
        $this->assertGreaterThanOrEqual(50000.0, $len0);
        $this->assertLessThan(52000.0, $len0, 'erster Chunk ~50 km (eine Kante Übergröße toleriert)');
    }

    public function testOverlapKeepsSeamEdge(): void
    {
        // Bei Überlappung muss der Punkt am Schnitt in BEIDEN Chunks liegen.
        $pts = $this->line(120);
        $chunks = RouteChunker::chunk($pts, 50000, 1000);
        $this->assertGreaterThanOrEqual(2, count($chunks));
        [, $end0] = $chunks[0];
        [$start1] = $chunks[1];
        $this->assertLessThan($end0, $start1, 'Chunk 2 beginnt vor dem Ende von Chunk 1 (Naht-Überlappung)');
    }
}
