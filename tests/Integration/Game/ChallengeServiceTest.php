<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\Challenges\ChallengeService;
use App\Support\Clock;
use Tests\IntegrationTestCase;

/**
 * Aufgaben/Challenges (GAME_CHALLENGES_BACKEND.md §4): Fortschritt live aus dem
 * Ereignis-Strom der laufenden ISO-Woche, Lokalisierung, points_total.
 */
final class ChallengeServiceTest extends IntegrationTestCase
{
    private ChallengeService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ChallengeService($this->pdo);
    }

    private function today(): string
    {
        return Clock::nowUtc()->format('Y-m-d');
    }

    private function lastWeek(): string
    {
        return Clock::nowUtc()->modify('-7 days')->format('Y-m-d');
    }

    private function insertEvent(string $type, int $userId, ?int $actorId, ?int $edgeId, string $riddenOn): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_event (type, user_id, actor_user_id, edge_id, ridden_on)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$type, $userId, $actorId, $edgeId, $riddenOn]);
    }

    /** @return array<string,array<string,mixed>> indexiert nach challenge id */
    private function byId(array $resp): array
    {
        $out = [];
        foreach ($resp['challenges'] as $c) {
            $out[$c['id']] = $c;
        }
        return $out;
    }

    public function testProgressFromEventStreamGermanDefault(): void
    {
        $me = $this->createUser('me');
        $owner = $this->createUser('owner');
        // 2 neue Kanten diese Woche.
        $this->insertEvent('edge_new', $me, $me, 101, $this->today());
        $this->insertEvent('edge_new', $me, $me, 102, $this->today());
        // 3 Übernahmen als Akteur diese Woche.
        $this->insertEvent('edge_taken', $owner, $me, 201, $this->today());
        $this->insertEvent('edge_taken', $owner, $me, 202, $this->today());
        $this->insertEvent('edge_taken', $owner, $me, 203, $this->today());

        $resp = $this->svc->forUser($me, 'de-DE');
        $byId = $this->byId($resp);

        $this->assertSame(2, $byId['weekly_new_edges']['progress']);
        $this->assertSame(5, $byId['weekly_new_edges']['target']);
        $this->assertSame('Erschließe 5 neue Kanten', $byId['weekly_new_edges']['title']);
        $this->assertSame('weekly', $byId['weekly_new_edges']['period']);

        $this->assertSame(3, $byId['weekly_capture']['progress']);
        $this->assertSame(3, $byId['weekly_capture']['target']);
        // capture erfüllt (3/3) ⇒ 30 Punkte; new_edges (2/5) nicht.
        $this->assertSame(30, $resp['points_total']);
    }

    public function testEnglishLocalization(): void
    {
        $me = $this->createUser('me');
        $resp = $this->svc->forUser($me, 'en-US,en;q=0.9');
        $byId = $this->byId($resp);
        $this->assertSame('Discover 5 new edges', $byId['weekly_new_edges']['title']);
        $this->assertSame('Capture 3 edges', $byId['weekly_capture']['title']);
        $this->assertSame('Explorer', $byId['weekly_new_edges']['badge']);
    }

    public function testEventsBeforeThisWeekAreExcluded(): void
    {
        $me = $this->createUser('me');
        $this->insertEvent('edge_new', $me, $me, 301, $this->lastWeek());

        $byId = $this->byId($this->svc->forUser($me, 'de'));
        $this->assertSame(0, $byId['weekly_new_edges']['progress']);
    }

    public function testProgressCapsAtTargetAndCountsDistinctEdges(): void
    {
        $me = $this->createUser('me');
        $owner = $this->createUser('owner');
        // Dieselbe Kante an zwei Tagen übernommen ⇒ distinct = 1.
        $this->insertEvent('edge_taken', $owner, $me, 500, $this->today());
        $this->insertEvent('edge_taken', $owner, $me, 500, $this->lastWeek()); // Vorwoche, zählt nicht
        // Genug neue Kanten für Überschreitung des Ziels (6 > target 5).
        foreach (range(601, 606) as $edge) {
            $this->insertEvent('edge_new', $me, $me, $edge, $this->today());
        }

        $byId = $this->byId($this->svc->forUser($me, 'de'));
        $this->assertSame(1, $byId['weekly_capture']['progress']);
        $this->assertSame(5, $byId['weekly_new_edges']['progress'], 'Anzeige nie über dem Ziel');
    }
}
