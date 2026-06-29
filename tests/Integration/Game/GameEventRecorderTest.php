<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameEventRecorder;
use App\Game\GameEventRepository;
use App\Game\GameRepository;
use Tests\IntegrationTestCase;

/**
 * Phase A Teil 1: Materialisierung des Ereignis-Stroms (GAME_EVENTS_BACKEND.md).
 * Prüft die Emission von edge_taken / pioneer_joined / edge_new sowie die
 * Idempotenz (Re-Ingest desselben Tages feuert nicht doppelt).
 */
final class GameEventRecorderTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameEventRepository $events;
    private GameEventRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo     = new GameRepository($this->pdo);
        $this->events   = new GameEventRepository($this->pdo);
        $this->recorder = new GameEventRecorder($this->repo, $this->events);
    }

    /** @return list<array<string,mixed>> */
    private function eventsFor(int $userId): array
    {
        return $this->pdo->query(
            "SELECT type, actor_user_id, edge_id FROM game_event WHERE user_id = {$userId} ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function seedEdge(?int $discovererClaimantId = null): int
    {
        $osm = random_int(1, 2_000_000_000);
        $this->pdo->prepare('INSERT INTO game_node (osm_node_id, lat, lon) VALUES (?, 49.5, 8.5)')->execute([$osm]);
        $a = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO game_node (osm_node_id, lat, lon) VALUES (?, 49.51, 8.51)')->execute([$osm + 1]);
        $b = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO game_edge
                (way_id, node_a_id, node_b_id, length_m, geom_geojson, min_lat, min_lon, max_lat, max_lon, discoverer_claimant_id)
             VALUES (?, ?, ?, 100.0, ?, 49.5, 8.5, 49.51, 8.51, ?)'
        )->execute([$osm, $a, $b, json_encode([[8.5, 49.5], [8.51, 49.51]]), $discovererClaimantId]);
        return (int)$this->pdo->lastInsertId();
    }

    private function seedPass(int $edgeId, int $userId, int $claimantId, int $routeId, string $riddenOn): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_edge_pass (edge_id, claimant_id, user_id, route_id, ridden_on, ridden_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$edgeId, $claimantId, $userId, $routeId, $riddenOn, $riddenOn . ' 08:00:00.000']);
    }

    public function testEdgeTakenEmittedAndSuppressesPioneerForSameRecipient(): void
    {
        $owner = $this->createUser('owner');
        $actor = $this->createUser('taker');
        $c1 = $this->repo->riderClaimantId($owner);
        $c2 = $this->repo->riderClaimantId($actor);
        $edge = $this->seedEdge($c1); // owner ist Erstbefahrer
        $this->seedPass($edge, $owner, $c1, 100, '2026-06-18'); // früherer Besitz-Pass
        $this->seedPass($edge, $actor, $c2, 200, '2026-06-29'); // aktuelle Übernahme-Fahrt

        $written = $this->recorder->record(
            [$edge => $c1], [$edge => $c2], [$edge], $actor, 200, '2026-06-29',
        );

        $rows = $this->eventsFor($owner);
        $this->assertCount(1, $rows, 'nur edge_taken, kein zusätzliches pioneer_joined');
        $this->assertSame('edge_taken', $rows[0]['type']);
        $this->assertSame((string)$actor, (string)$rows[0]['actor_user_id']);
        $this->assertSame($edge, (int)$rows[0]['edge_id']);
        $this->assertSame(1, $written);
    }

    public function testPioneerJoinedEmittedWithoutTakeover(): void
    {
        $pioneer = $this->createUser('pioneer');
        $visitor = $this->createUser('visitor');
        $c1 = $this->repo->riderClaimantId($pioneer);
        $c2 = $this->repo->riderClaimantId($visitor);
        $edge = $this->seedEdge($c1);
        $this->seedPass($edge, $pioneer, $c1, 100, '2026-06-18'); // Erstbefahrung
        $this->seedPass($edge, $visitor, $c2, 200, '2026-06-29'); // neuer Fahrer, keine Übernahme

        // Kein Besitzwechsel (prev == new == c1).
        $written = $this->recorder->record(
            [$edge => $c1], [$edge => $c1], [$edge], $visitor, 200, '2026-06-29',
        );

        $rows = $this->eventsFor($pioneer);
        $this->assertCount(1, $rows);
        $this->assertSame('pioneer_joined', $rows[0]['type']);
        $this->assertSame((string)$visitor, (string)$rows[0]['actor_user_id']);
        $this->assertSame(1, $written);
    }

    public function testEdgeNewEmittedForBrandNewEdge(): void
    {
        $pioneer = $this->createUser('first');
        $c1 = $this->repo->riderClaimantId($pioneer);
        $edge = $this->seedEdge($c1);
        $this->seedPass($edge, $pioneer, $c1, 200, '2026-06-29'); // nur die aktuelle Fahrt

        $written = $this->recorder->record(
            [$edge => null], [$edge => $c1], [$edge], $pioneer, 200, '2026-06-29',
        );

        $rows = $this->eventsFor($pioneer);
        $this->assertCount(1, $rows);
        $this->assertSame('edge_new', $rows[0]['type']);
        $this->assertSame(1, $written);
    }

    public function testReingestIsIdempotent(): void
    {
        $owner = $this->createUser('owner');
        $actor = $this->createUser('taker');
        $c1 = $this->repo->riderClaimantId($owner);
        $c2 = $this->repo->riderClaimantId($actor);
        $edge = $this->seedEdge($c1);
        $this->seedPass($edge, $owner, $c1, 100, '2026-06-18');
        $this->seedPass($edge, $actor, $c2, 200, '2026-06-29');

        $first  = $this->recorder->record([$edge => $c1], [$edge => $c2], [$edge], $actor, 200, '2026-06-29');
        $second = $this->recorder->record([$edge => $c1], [$edge => $c2], [$edge], $actor, 200, '2026-06-29');

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'zweiter Lauf schreibt nichts (Idempotenz)');
        $count = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM game_event WHERE user_id = {$owner} AND type = 'edge_taken'"
        )->fetchColumn();
        $this->assertSame(1, $count);
    }
}
