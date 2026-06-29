<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameReadService;
use App\Game\GameRepository;
use App\Privacy\PrivacyZoneRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/**
 * GAME_IN_REACH_BACKEND.md: additives Feld `in_reach` pro Kante in
 * GET /game/edges. true, wenn die Kante nicht dem Viewer gehört und ein
 * einziger weiterer Pass (Gewicht 1,0) die Übernahme-Schwelle des Besitzers
 * (P(Besitzer) × Hysterese 1,15) überschreiten würde. Heimatzone respektiert,
 * ohne Bearer kein Feld.
 */
final class GameEdgesInReachTest extends IntegrationTestCase
{
    private const BBOX = '9.6,47.1,9.7,47.2';

    private GameRepository $repo;
    private GameConfig $config;
    private EdgeRecalculator $recalc;
    private PrivacyZoneRepository $zones;
    private GameReadService $read;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo   = new GameRepository($this->pdo);
        $this->config = new GameConfig($this->pdo);
        $this->recalc = new EdgeRecalculator($this->repo, $this->config);
        $this->zones  = new PrivacyZoneRepository($this->pdo);
        $this->read   = new GameReadService($this->repo, $this->config, null, $this->zones);
        $this->now    = new DateTimeImmutable('2026-06-20T12:00:00Z', new DateTimeZone('UTC'));
    }

    private function seedEdge(int $key, float $lat, float $lon): int
    {
        $a = $this->repo->upsertNode($key * 2, $lat, $lon);
        $b = $this->repo->upsertNode($key * 2 + 1, $lat + 0.001, $lon + 0.001);
        $geom = json_encode([
            'type' => 'LineString',
            'coordinates' => [[$lon, $lat], [$lon + 0.001, $lat + 0.001]],
        ]);
        return $this->repo->upsertEdge($key, $a, $b, 120.0, $geom, null,
            $lat, $lon, $lat + 0.001, $lon + 0.001);
    }

    private function ride(int $edgeId, int $userId, string $day, string $at): void
    {
        $claimant = $this->repo->riderClaimantId($userId);
        $this->repo->insertPassIfAbsent($edgeId, $claimant, $userId, $edgeId * 1000 + $userId, $day, $at);
        $this->repo->refreshEdgeDiscovery($edgeId);
        $this->recalc->recalculate($edgeId, $this->now);
    }

    /** @return array<int,array<string,mixed>> indexiert nach Kanten-ID */
    private function edgesById(?int $viewerClaimant, ?int $viewerUserId): array
    {
        $out = [];
        foreach ($this->read->edgesInBbox(self::BBOX, $viewerClaimant, $this->now, 1000, $viewerUserId) as $e) {
            $out[(int)$e['id']] = $e;
        }
        return $out;
    }

    public function testInReachFlagsForFreeOwnAndForeignEdges(): void
    {
        $viewer = $this->createUser('reach-viewer');
        $viewerClaimant = $this->repo->riderClaimantId($viewer);

        // 1) Freie/herrenlose Kante, kein Pass → erster Pass genügt (AC3).
        $free = $this->seedEdge(7001, 47.11, 9.61);

        // 2) Eigene Kante (Viewer ist Besitzer) → immer false (AC1).
        $own = $this->seedEdge(7002, 47.12, 9.62);
        $this->ride($own, $viewer, '2026-06-19', '2026-06-19 08:00:00.000');

        // 3) Fremde Kante, Besitzer mit einem 30 Tage alten Pass:
        //    P(owner)=1-30/90=0.667 → ×1.15=0.767 < 1 → ein Pass genügt (AC2 true).
        $ownerA = $this->createUser('reach-owner-a');
        $inReach = $this->seedEdge(7003, 47.13, 9.63);
        $this->ride($inReach, $ownerA, '2026-05-21', '2026-05-21 12:00:00.000');

        // 4) Fremde Kante, Besitzer mit zwei frischen Pässen:
        //    P(owner)≈1.99 → ×1.15≈2.29 ≫ 1 → außer Reichweite (AC2 false).
        $ownerB = $this->createUser('reach-owner-b');
        $strong = $this->seedEdge(7004, 47.14, 9.64);
        $this->ride($strong, $ownerB, '2026-06-20', '2026-06-20 09:00:00.000');
        $this->ride($strong, $ownerB, '2026-06-19', '2026-06-19 09:00:00.000');

        $byId = $this->edgesById($viewerClaimant, $viewer);

        $this->assertTrue($byId[$free]['in_reach'], 'Freie Kante: erster Pass genügt.');
        $this->assertFalse($byId[$own]['in_reach'], 'Eigene Kante nie in Reichweite.');
        $this->assertTrue($byId[$own]['owner_is_me'], 'Eigene Kante: owner_is_me=true.');
        $this->assertTrue($byId[$inReach]['in_reach'], 'Schwacher Besitzer: ein Pass fehlt → true.');
        $this->assertFalse($byId[$strong]['in_reach'], 'Starker Besitzer: außer Reichweite → false.');
    }

    public function testForeignEdgeBecomesInReachWhenViewerAlreadyHasAPass(): void
    {
        // Besitzer mit zwei frischen Pässen (P≈1.99, Schwelle ≈2.29). Der Viewer
        // hat bereits einen frischen Pass (P=1.0) → P+1=2.0 < 2.29 → noch nicht.
        // Mit einem weiteren eigenen frischen Pass (P=1.99) → P+1=2.99 > 2.29 → true.
        $viewer = $this->createUser('reach-climber');
        $viewerClaimant = $this->repo->riderClaimantId($viewer);
        $owner = $this->createUser('reach-holder');

        $edge = $this->seedEdge(7010, 47.15, 9.65);
        $this->ride($edge, $owner, '2026-06-20', '2026-06-20 09:00:00.000');
        $this->ride($edge, $owner, '2026-06-19', '2026-06-19 09:00:00.000');

        // Viewer mit einem Pass: noch außer Reichweite.
        $vClaimant = $this->repo->riderClaimantId($viewer);
        $this->repo->insertPassIfAbsent($edge, $vClaimant, $viewer, 999001, '2026-06-18', '2026-06-18 09:00:00.000');
        $this->recalc->recalculate($edge, $this->now);
        $byId = $this->edgesById($viewerClaimant, $viewer);
        $this->assertFalse($byId[$edge]['in_reach'], 'Ein Viewer-Pass reicht gegen starken Besitzer noch nicht.');

        // Zweiter Viewer-Pass → jetzt würde der dritte die Schwelle reißen.
        $this->repo->insertPassIfAbsent($edge, $vClaimant, $viewer, 999002, '2026-06-17', '2026-06-17 09:00:00.000');
        $this->recalc->recalculate($edge, $this->now);
        $byId = $this->edgesById($viewerClaimant, $viewer);
        $this->assertTrue($byId[$edge]['in_reach'], 'Mit zwei Viewer-Pässen ist die Übernahme einen Pass entfernt.');
    }

    public function testHomeZoneMaskedEdgeIsNeverInReach(): void
    {
        $viewer = $this->createUser('reach-masked');
        $viewerClaimant = $this->repo->riderClaimantId($viewer);
        $ownerA = $this->createUser('reach-owner-mask');

        $edge = $this->seedEdge(7020, 47.13, 9.63);
        $this->ride($edge, $ownerA, '2026-05-21', '2026-05-21 12:00:00.000');

        // Ohne Zone: in Reichweite.
        $this->assertTrue($this->edgesById($viewerClaimant, $viewer)[$edge]['in_reach']);

        // Heimatzone des Viewers über der Kante → maskiert (AC4).
        $this->zones->upsert($viewer, 47.13, 9.63, 500, true);
        $this->assertFalse(
            $this->edgesById($viewerClaimant, $viewer)[$edge]['in_reach'],
            'Kante in der Heimatzone des Viewers ist nie in Reichweite.',
        );
    }

    public function testAnonymousRequestHasNoInReachField(): void
    {
        $owner = $this->createUser('reach-anon-owner');
        $edge = $this->seedEdge(7030, 47.13, 9.63);
        $this->ride($edge, $owner, '2026-05-21', '2026-05-21 12:00:00.000');

        $edges = $this->read->edgesInBbox(self::BBOX, null, $this->now, 1000, null);
        $this->assertNotEmpty($edges);
        foreach ($edges as $e) {
            $this->assertArrayNotHasKey('in_reach', $e, 'Ohne Bearer kein Personenbezug (AC5).');
        }
    }
}
