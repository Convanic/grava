<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameRecomputeService;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Routes\GeometryParser;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

final class GameRecomputeTest extends IntegrationTestCase
{
    public function testFullRecomputeMatchesLivePath(): void
    {
        $repo = new GameRepository($this->pdo);
        $config = new GameConfig($this->pdo);
        $recalc = new EdgeRecalculator($repo, $config);
        $now = new DateTimeImmutable('2026-06-20T08:00:00Z', new DateTimeZone('UTC'));

        $u1 = $this->createUser('armin');
        $route = (new GeometryParser())->parse(
            '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13],[9.67,47.14]]}'
        );
        $segs = [
            new MatchedSegment(1001, 10, 11, 120.0, [[9.65, 47.12], [9.66, 47.13]], 'gravel', 18.0, 8.0, true, $now),
            new MatchedSegment(1002, 11, 12, 120.0, [[9.66, 47.13], [9.67, 47.14]], 'gravel', 18.0, 8.0, true, $now),
        ];
        $ingest = new GameIngestionService(new FakeEdgeMatcher($segs), $repo, $recalc, $config, $this->pdo);
        $ingest->ingest(1, $u1, $route, true, $now);

        $live = $this->snapshot();

        (new GameRecomputeService($repo, $recalc))->recomputeAll($now);
        $recomputed = $this->snapshot();

        $this->assertSame($live, $recomputed, 'Voller Recompute muss bit-identisch zum Live-Pfad sein.');
    }

    /** @return array<int,array{owner:?int,value:string,fresh:string,n:int}> */
    private function snapshot(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, owner_claimant_id, value_cached, freshness_cached, distinct_riders_total
               FROM game_edge ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = [
                'owner' => $r['owner_claimant_id'] !== null ? (int)$r['owner_claimant_id'] : null,
                'value' => (string)$r['value_cached'],
                'fresh' => (string)$r['freshness_cached'],
                'n'     => (int)$r['distinct_riders_total'],
            ];
        }
        return $out;
    }
}
