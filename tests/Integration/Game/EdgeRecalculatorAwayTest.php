<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameMath;
use App\Game\GameRepository;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/**
 * Auswärts-Multiplikator im Recompute (Konzept §20 / GAME_AWAY_MULTIPLIER_BACKEND.md).
 * Homebase wird datengetrieben aus den Pass-Mittelpunkten abgeleitet; der
 * Multiplikator verstärkt nur die Präsenz auswärtiger Pässe.
 */
final class EdgeRecalculatorAwayTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private int $homeEdge;   // Mittelpunkt ~ (47.0, 8.0)
    private int $farEdge;    // Mittelpunkt ~ (47.0, 10.0) → ~152 km von Home

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $geom = json_encode(['type' => 'LineString', 'coordinates' => [[8.0, 47.0], [8.0, 47.0]]]);
        // Home-Cluster bei (47.0, 8.0)
        $hA = $this->repo->upsertNode(10, 47.0, 7.99);
        $hB = $this->repo->upsertNode(11, 47.0, 8.01);
        $this->homeEdge = $this->repo->upsertEdge(2001, $hA, $hB, 100.0, $geom, null, 46.99, 7.99, 47.01, 8.01);
        // Far-Kante bei (47.0, 10.0)
        $fA = $this->repo->upsertNode(20, 47.0, 9.99);
        $fB = $this->repo->upsertNode(21, 47.0, 10.01);
        $this->farEdge = $this->repo->upsertEdge(2002, $fA, $fB, 100.0, $geom, null, 46.99, 9.99, 47.01, 10.01);
    }

    private function setConfig(string $key, string $value): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_config (config_key, config_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        )->execute([$key, $value]);
    }

    /** Frische GameConfig (cached pro Instanz) → nach setConfig neu bauen. */
    private function recalc(): EdgeRecalculator
    {
        return new EdgeRecalculator($this->repo, new GameConfig($this->pdo));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-20T12:00:00Z', new DateTimeZone('UTC'));
    }

    private function dayBefore(int $d): string
    {
        return $this->now()->modify("-{$d} days")->format('Y-m-d');
    }

    private function pass(int $edge, int $claimant, int $user, int $daysAgo): void
    {
        $on = $this->dayBefore($daysAgo);
        $this->repo->insertPassIfAbsent($edge, $claimant, $user, 1, $on, $on . ' 09:00:00.000');
    }

    public function testVisitorPresenceIsBoostedAndGatedByFlag(): void
    {
        $this->assertGreaterThanOrEqual(150.0, GameMath::haversineKm(47.0, 8.0, 47.0, 10.0),
            'Testaufbau: Far-Kante muss ≥ away_far_km entfernt sein');

        $u1 = $this->createUser('visitor');   // wohnt bei (47,8), fährt auswärts auf far
        $u2 = $this->createUser('localfar');  // wohnt bei (47,10) = an der Far-Kante
        $c1 = $this->repo->riderClaimantId($u1);
        $c2 = $this->repo->riderClaimantId($u2);

        // u1 etabliert Homebase: 20 Pässe auf der Home-Kante (distinct Tage).
        for ($d = 10; $d < 30; $d++) {
            $this->pass($this->homeEdge, $c1, $u1, $d);
        }
        // u1 fährt 5× auf der Far-Kante (auswärts).
        for ($d = 0; $d < 5; $d++) {
            $this->pass($this->farEdge, $c1, $u1, $d);
        }
        // u2 ist lokal an der Far-Kante: 12 Pässe dort → Homebase = (47,10).
        for ($d = 0; $d < 12; $d++) {
            $this->pass($this->farEdge, $c2, $u2, $d);
        }

        // --- away aus: Referenz-Präsenz ---
        $this->setConfig('away_enabled', '0');
        $base = $this->recalc()->presenceByClaimant($this->farEdge, $this->now());
        $baseC1 = $base[$c1];
        $baseC2 = $base[$c2];
        $this->assertGreaterThan(0.0, $baseC1);
        $this->assertGreaterThan(0.0, $baseC2);

        // --- away an: Besucher ×2, Local ×1 ---
        $this->setConfig('away_enabled', '1');
        $on = $this->recalc()->presenceByClaimant($this->farEdge, $this->now());

        $this->assertEqualsWithDelta(2.0, $on[$c1] / $baseC1, 0.02,
            'Auswärts-Fahrer (Homebase ~152 km weg) bekommt ×away_max');
        $this->assertEqualsWithDelta(1.0, $on[$c2] / $baseC2, 0.02,
            'Lokaler Fahrer (Homebase an der Kante) bleibt ×1');
    }

    public function testTooFewRidesNoEstablishedHomeNoBoost(): void
    {
        $u3 = $this->createUser('newbie');
        $c3 = $this->repo->riderClaimantId($u3);
        // 4 Home-Pässe + 5 Far-Pässe = 9 < home_min_rides(10) → keine Homebase.
        for ($d = 20; $d < 24; $d++) {
            $this->pass($this->homeEdge, $c3, $u3, $d);
        }
        for ($d = 0; $d < 5; $d++) {
            $this->pass($this->farEdge, $c3, $u3, $d);
        }

        $this->setConfig('away_enabled', '0');
        $base = $this->recalc()->presenceByClaimant($this->farEdge, $this->now())[$c3];

        $this->setConfig('away_enabled', '1');
        $on = $this->recalc()->presenceByClaimant($this->farEdge, $this->now())[$c3];

        $this->assertEqualsWithDelta(1.0, $on / $base, 0.001,
            'Ohne etablierte Homebase (< home_min_rides) kein Auswärts-Boost');
    }

    public function testCapLimitsCombinedBonus(): void
    {
        // away_max sehr hoch (×5), Deckel 3.0 → effektiver Multiplikator = 3.0
        $u1 = $this->createUser('faraway');
        $c1 = $this->repo->riderClaimantId($u1);
        for ($d = 10; $d < 30; $d++) {
            $this->pass($this->homeEdge, $c1, $u1, $d);
        }
        for ($d = 0; $d < 5; $d++) {
            $this->pass($this->farEdge, $c1, $u1, $d);
        }

        $this->setConfig('away_enabled', '0');
        $base = $this->recalc()->presenceByClaimant($this->farEdge, $this->now())[$c1];

        $this->setConfig('away_enabled', '1');
        $this->setConfig('away_max', '5.0');
        $this->setConfig('tagesbonus_max', '3.0');
        $on = $this->recalc()->presenceByClaimant($this->farEdge, $this->now())[$c1];

        $this->assertEqualsWithDelta(3.0, $on / $base, 0.02,
            'basis·away (1·5=5) wird durch tagesbonus_max=3.0 gedeckelt');
    }
}
