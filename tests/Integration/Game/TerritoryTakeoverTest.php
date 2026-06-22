<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\Admin\GameAuditService;
use App\Game\Crew\CrewRepository;
use App\Game\Crew\CrewService;
use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameRepository;
use App\Game\TerritoryTakeoverNotifier;
use App\Engagement\NotificationService;
use Tests\IntegrationTestCase;

final class TerritoryTakeoverTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private NotificationService $notif;
    private TerritoryTakeoverNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->notif = new NotificationService(); // ohne Push
        $this->notifier = new TerritoryTakeoverNotifier($this->repo, $this->notif);
    }

    public function testPreviousRiderOwnerGetsNotified(): void
    {
        $a = $this->createUser('alpha');
        $b = $this->createUser('bravo');
        $cA = $this->repo->riderClaimantId($a);
        $cB = $this->repo->riderClaimantId($b);

        $count = $this->notifier->notify([100 => $cA], [100 => $cB], $b);

        $this->assertSame(1, $count);
        $this->assertSame(1, $this->notif->unreadCount($a));
        $this->assertSame(0, $this->notif->unreadCount($b), 'Übernehmer bekommt nichts.');
        $list = $this->notif->list($a);
        $this->assertSame('territory_taken', $list['notifications'][0]['type']);
        $this->assertSame('bravo', $list['notifications'][0]['actor']['handle']);
    }

    public function testAggregatesMultipleLostEdgesIntoOneNotification(): void
    {
        $a = $this->createUser('owner');
        $b = $this->createUser('taker');
        $cA = $this->repo->riderClaimantId($a);
        $cB = $this->repo->riderClaimantId($b);

        // Drei Kanten desselben Verlierers in EINEM Upload.
        $count = $this->notifier->notify(
            [1 => $cA, 2 => $cA, 3 => $cA],
            [1 => $cB, 2 => $cB, 3 => $cB],
            $b,
        );

        $this->assertSame(1, $count, 'Pro Verlierer genau eine Benachrichtigung.');
        $this->assertSame(1, $this->notif->unreadCount($a));
    }

    public function testAllCrewMembersNotified(): void
    {
        $cap   = $this->createUser('cap');
        $mate  = $this->createUser('mate');
        $taker = $this->createUser('taker2');

        $crews = new CrewService(
            $this->pdo, new CrewRepository($this->pdo), $this->repo,
            new EdgeRecalculator($this->repo, new GameConfig($this->pdo)),
            new GameConfig($this->pdo), new GameAuditService($this->pdo),
        );
        $crew = $crews->create($cap, 'Owls');
        $crews->join($mate, $crew['join_code']);
        $crewClaimant = (int)$crew['claimant_id'];
        $cTaker = $this->repo->riderClaimantId($taker);

        $count = $this->notifier->notify([7 => $crewClaimant], [7 => $cTaker], $taker);

        $this->assertSame(2, $count, 'Beide Crew-Mitglieder werden benachrichtigt.');
        $this->assertSame(1, $this->notif->unreadCount($cap));
        $this->assertSame(1, $this->notif->unreadCount($mate));
    }

    public function testNoChangeOrNullOwnerDoesNotNotify(): void
    {
        $a = $this->createUser('x');
        $b = $this->createUser('y');
        $cA = $this->repo->riderClaimantId($a);

        $this->assertSame(0, $this->notifier->notify([1 => $cA], [1 => $cA], $b), 'Kein Wechsel.');
        $this->assertSame(0, $this->notifier->notify([1 => null], [1 => $cA], $b), 'Vorher herrenlos.');
        $this->assertSame(0, $this->notifier->notify([1 => $cA], [1 => null], $b), 'Nachher herrenlos.');
        $this->assertSame(0, $this->notif->unreadCount($a));
    }

    public function testActorExcludedFromOwnCrewLoss(): void
    {
        $cap  = $this->createUser('capx');
        $mate = $this->createUser('matex');

        $crews = new CrewService(
            $this->pdo, new CrewRepository($this->pdo), $this->repo,
            new EdgeRecalculator($this->repo, new GameConfig($this->pdo)),
            new GameConfig($this->pdo), new GameAuditService($this->pdo),
        );
        $crew = $crews->create($cap, 'Foxes');
        $crews->join($mate, $crew['join_code']);
        $crewClaimant = (int)$crew['claimant_id'];
        $cMate = $this->repo->riderClaimantId($mate);

        // cap löst die Übernahme aus → cap ausgeschlossen, nur mate (sofern
        // mate noch zum Verlierer-Claimant zählt). Hier verliert die Crew an cap.
        $count = $this->notifier->notify([9 => $crewClaimant], [9 => $cMate], $cap);
        // mate ist Crew-Mitglied (Verlierer), cap ist Actor → nur mate.
        $this->assertSame(1, $count);
        $this->assertSame(0, $this->notif->unreadCount($cap));
        $this->assertSame(1, $this->notif->unreadCount($mate));
    }
}
