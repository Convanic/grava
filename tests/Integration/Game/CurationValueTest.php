<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameRepository;
use Tests\IntegrationTestCase;

/**
 * Integration: Kuratierungs-Signal einer Kante (Gamification_Territory_Concept.md
 * §5.3) — zählt verschiedene Nutzer mit positivem Hinweis im Kanten-Umkreis.
 */
final class CurationValueTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private int $edgeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->edgeId = $this->seedEdge(49.5, 8.5);
    }

    public function testCountsDistinctPositiveHintUsersInRange(): void
    {
        // u1 + u2: positiver Hinweis IN der Kanten-Bbox → zählen.
        $this->seedHint($this->createRouteFor('u1'), 'positive', 49.5, 8.5);
        $this->seedHint($this->createRouteFor('u2'), 'positive', 49.5, 8.5);
        // Negativer Hinweis (u3) → ignoriert.
        $this->seedHint($this->createRouteFor('u3'), 'negative', 49.5, 8.5);
        // Positiver Hinweis weit außerhalb (u4) → ignoriert.
        $this->seedHint($this->createRouteFor('u4'), 'positive', 50.4, 9.4);

        // Erwartet: nur u1 + u2 = 2.
        $this->assertSame(2, $this->repo->curationForEdge($this->edgeId, 30.0));
    }

    public function testSameUserMultiplePositiveHintsCountOnce(): void
    {
        $routeId = $this->createRouteFor('solo');
        $this->seedHint($routeId, 'positive', 49.5, 8.5);
        $this->seedHint($routeId, 'positive', 49.5001, 8.5001);
        $this->assertSame(1, $this->repo->curationForEdge($this->edgeId, 30.0));
    }

    public function testNoHintsIsZero(): void
    {
        $this->assertSame(0, $this->repo->curationForEdge($this->edgeId, 30.0));
    }

    // --- Seed-Helfer -----------------------------------------------------

    private function seedEdge(float $lat, float $lon): int
    {
        $a = $this->seedNode($lat, $lon);
        $b = $this->seedNode($lat + 0.0002, $lon + 0.0002);
        $geo = '{"type":"LineString","coordinates":[[' . $lon . ',' . $lat . '],[' . ($lon + 0.0002) . ',' . ($lat + 0.0002) . ']]}';
        $this->pdo->prepare(
            'INSERT INTO game_edge (way_id, node_a_id, node_b_id, length_m, geom_geojson,
                                    min_lat, min_lon, max_lat, max_lon)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([111, $a, $b, 25.0, $geo, $lat, $lon, $lat + 0.0002, $lon + 0.0002]);
        return (int)$this->pdo->lastInsertId();
    }

    private int $nodeSeq = 1;

    private function seedNode(float $lat, float $lon): int
    {
        $this->pdo->prepare(
            'INSERT INTO game_node (osm_node_id, lat, lon) VALUES (?, ?, ?)'
        )->execute([900000 + $this->nodeSeq++, $lat, $lon]);
        return (int)$this->pdo->lastInsertId();
    }

    private function createRouteFor(string $handle): int
    {
        $publicId = $this->createRoute($this->createUser($handle));
        $stmt = $this->pdo->prepare('SELECT id FROM routes WHERE public_id = ?');
        $stmt->execute([$publicId]);
        return (int)$stmt->fetchColumn();
    }

    private function seedHint(int $routeId, string $sentiment, float $lat, float $lon): void
    {
        $this->pdo->prepare(
            'INSERT INTO route_hints (route_id, client_hint_uuid, reason_key, sentiment, label, lat, lon)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$routeId, self::uuid4(), 'great_view', $sentiment, 'Aussicht', $lat, $lon]);
    }
}
