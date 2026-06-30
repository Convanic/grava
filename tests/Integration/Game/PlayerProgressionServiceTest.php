<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameConfig;
use App\Game\GameRepository;
use App\Game\PlayerProgressionService;
use Tests\IntegrationTestCase;

/**
 * Integration: AP/Rang-Berechnung + Abzeichen-Persistenz (RankBadges_Concept.md).
 * Prüft die DB-Pfade (Distanz-Summe, Übernahme-Dedupe) und die „Höchststand/
 * unverlierbar"-Regel (§13.4) durchgängig.
 */
final class PlayerProgressionServiceTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private PlayerProgressionService $svc;
    private int $u1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->svc  = new PlayerProgressionService($this->repo, new GameConfig($this->pdo));
        $this->u1   = $this->createUser('armin');

        // 700 km gefahrene Distanz (Kondition + AP-km).
        $this->createRoute($this->u1);
        $this->pdo->prepare('UPDATE routes SET distance_m = 700000 WHERE user_id = ?')
            ->execute([$this->u1]);

        // Zwei Übernahmen; eine davon mit Empfänger-Fan-out (2 Verlierer) →
        // muss als EINE Übernahme zählen (Dedupe über (edge_id, ridden_on)).
        // game_event.user_id hat einen FK auf users → echte Verlierer anlegen.
        $loserA = $this->createUser('loserA');
        $loserB = $this->createUser('loserB');
        $loserC = $this->createUser('loserC');
        $this->insertTakeover(edgeId: 10, riddenOn: '2026-06-20', loserUserId: $loserA);
        $this->insertTakeover(edgeId: 10, riddenOn: '2026-06-20', loserUserId: $loserB); // selbe Kante+Tag
        $this->insertTakeover(edgeId: 11, riddenOn: '2026-06-21', loserUserId: $loserC);
    }

    private function insertTakeover(int $edgeId, string $riddenOn, int $loserUserId): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_event (type, user_id, actor_user_id, edge_id, ridden_on)
             VALUES (\'edge_taken\', ?, ?, ?, ?)'
        )->execute([$loserUserId, $this->u1, $edgeId, $riddenOn]);
    }

    public function testApRankAndBadges(): void
    {
        $r = $this->svc->forMe($this->u1, pioneeredEdges: 1600, heldLengthM: 450000.0, longestStreakWeeks: 30, recordsHeld: 6);

        // AP = 1600·1 (pioneer) + 2·3 (takeover, dedupe!) + 700·1 (km) + 30·10 (streak)
        $this->assertSame(2606, $r['ap_total']);
        $this->assertSame(2, $this->repo->takeoverCount($this->u1), 'Fan-out muss dedupliziert werden');

        // AP 2606 → Rang 5 (Schwellen …,2500,5000); kein Gate unter R6.
        $this->assertSame(5, $r['rank']);
        $this->assertSame(6, $r['next_rank']['rank']);
        $this->assertSame(2394, $r['next_rank']['ap_remaining']);

        // Stufen: erschliesser/revierhalter/stammfahrer = Gold, kondition/schnellster = Silber.
        $tiers = [];
        foreach ($r['badges'] as $b) {
            $tiers[$b['family']] = $b['tier'];
        }
        $this->assertSame(2, $tiers['erschliesser']); // 1600 ≥ 1500
        $this->assertSame(2, $tiers['revierhalter']); // 450 km ≥ 400
        $this->assertSame(1, $tiers['kondition']);     // 700 km ≥ 500
        $this->assertSame(2, $tiers['stammfahrer']);   // 30 Wo ≥ 26
        $this->assertSame(1, $tiers['schnellster']);   // 6 ≥ 5
    }

    public function testEarnedTierSurvivesValueDrop(): void
    {
        // Erst Gold auf Revierhalter erreichen (450 km).
        $this->svc->forMe($this->u1, 0, 450000.0, 0, 0);

        // Später bricht der Besitz ein (50 km) — die Gold-Stufe muss bleiben (§13.4).
        $r = $this->svc->forMe($this->u1, 0, 50000.0, 0, 0);

        $byFamily = [];
        foreach ($r['badges'] as $b) {
            $byFamily[$b['family']] = $b;
        }
        $this->assertSame(2, $byFamily['revierhalter']['tier'], 'Peak-Stufe bleibt trotz Wertverlust');
        $this->assertEqualsWithDelta(50.0, $byFamily['revierhalter']['value'], 0.1, 'Live-Wert spiegelt den Einbruch');
    }
}
