<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameRepository;
use Tests\IntegrationTestCase;

/**
 * Integration: Pionier-Showcase (§7) — zuletzt erschlossene Kanten eines
 * Claimants, neueste zuerst; fremde Erschließungen bleiben außen vor.
 */
final class PioneeredShowcaseTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private int $nodeSeq = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
    }

    public function testReturnsOwnPioneeredEdgesNewestFirst(): void
    {
        $mine  = $this->seedClaimant('armin');
        $other = $this->seedClaimant('max');

        $eAlt = $this->seedPioneeredEdge($mine, '2026-06-01 10:00:00.000', 49.50, 8.50);
        $eNeu = $this->seedPioneeredEdge($mine, '2026-06-10 10:00:00.000', 49.51, 8.51);
        $this->seedPioneeredEdge($other, '2026-06-11 10:00:00.000', 49.52, 8.52); // fremd → nicht enthalten

        $rows = $this->repo->recentPioneeredEdges($mine, 10);

        $this->assertCount(2, $rows);
        $this->assertSame($eNeu, $rows[0]['id'], 'neueste Erschließung zuerst');
        $this->assertSame($eAlt, $rows[1]['id']);
        $this->assertStringContainsString('LineString', $rows[0]['geom']);
        $this->assertNotNull($rows[0]['discovered_at']);
    }

    public function testRespectsLimit(): void
    {
        $mine = $this->seedClaimant('solo');
        for ($i = 0; $i < 5; $i++) {
            $this->seedPioneeredEdge($mine, sprintf('2026-06-%02d 10:00:00.000', $i + 1), 49.5 + $i * 0.01, 8.5);
        }
        $this->assertCount(3, $this->repo->recentPioneeredEdges($mine, 3));
    }

    // --- Seed-Helfer -----------------------------------------------------

    private function seedClaimant(string $handle): int
    {
        $uid = $this->createUser($handle);
        $this->pdo->prepare('INSERT INTO game_claimant (type, user_id) VALUES (\'rider\', ?)')->execute([$uid]);
        return (int)$this->pdo->lastInsertId();
    }

    private function seedNode(float $lat, float $lon): int
    {
        $this->pdo->prepare('INSERT INTO game_node (osm_node_id, lat, lon) VALUES (?, ?, ?)')
            ->execute([900000 + $this->nodeSeq++, $lat, $lon]);
        return (int)$this->pdo->lastInsertId();
    }

    private function seedPioneeredEdge(int $claimantId, string $discoveredAt, float $lat, float $lon): int
    {
        $a = $this->seedNode($lat, $lon);
        $b = $this->seedNode($lat + 0.0002, $lon + 0.0002);
        $geo = '{"type":"LineString","coordinates":[[' . $lon . ',' . $lat . '],[' . ($lon + 0.0002) . ',' . ($lat + 0.0002) . ']]}';
        $this->pdo->prepare(
            'INSERT INTO game_edge (way_id, node_a_id, node_b_id, length_m, geom_geojson,
                                    min_lat, min_lon, max_lat, max_lon, discoverer_claimant_id, discovered_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int)($lat * 1000), $a, $b, 25.0, $geo,
            $lat, $lon, $lat + 0.0002, $lon + 0.0002, $claimantId, $discoveredAt,
        ]);
        return (int)$this->pdo->lastInsertId();
    }
}
