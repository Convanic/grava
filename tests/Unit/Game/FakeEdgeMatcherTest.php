<?php
declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\FakeEdgeMatcher;
use App\Game\MatchedSegment;
use App\Routes\GeometryParser;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class FakeEdgeMatcherTest extends TestCase
{
    public function testReturnsConfiguredSegments(): void
    {
        $seg = new MatchedSegment(
            wayId: 1001,
            nodeARef: 10,
            nodeBRef: 11,
            lengthM: 120.0,
            geometry: [[9.65, 47.12], [9.66, 47.13]],
            surface: 'gravel',
            avgSpeedKmh: 18.0,
            maxHaccM: 8.0,
            hasMotion: true,
            riddenAt: new DateTimeImmutable('2026-06-20T08:00:00Z'),
        );
        $matcher = new FakeEdgeMatcher([$seg]);
        $parsed = (new GeometryParser())->parse(
            '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13]]}'
        );
        $out = $matcher->match($parsed);
        $this->assertCount(1, $out);
        $this->assertSame(1001, $out[0]->wayId);
        $this->assertSame('gravel', $out[0]->surface);
    }
}
