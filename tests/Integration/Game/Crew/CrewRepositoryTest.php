<?php
declare(strict_types=1);

namespace Tests\Integration\Game\Crew;

use App\Game\GameConfig;
use App\Game\GameRepository;
use Tests\IntegrationTestCase;

final class CrewRepositoryTest extends IntegrationTestCase
{
    public function testCrewConfigDefaults(): void
    {
        $cfg = new GameConfig($this->pdo);
        $this->assertSame(1.5, $cfg->float('group_ride_bonus'));
        $this->assertSame(3, $cfg->int('group_ride_min_members'));
        $this->assertSame(0, $cfg->int('crew_max_members'));
    }

    public function testEffectiveClaimantMapFallsBackToRiderWhenSolo(): void
    {
        $repo = new GameRepository($this->pdo);
        $u = $this->createUser('solo');
        $rider = $repo->riderClaimantId($u);

        $map = $repo->effectiveClaimantMap([$u]);

        $this->assertSame($rider, $map[$u]['claimant_id']);
        $this->assertFalse($map[$u]['is_group']);
    }

    public function testEffectiveClaimantMapUsesCrewWhenMember(): void
    {
        $repo = new GameRepository($this->pdo);
        $u = $this->createUser('member');
        $repo->riderClaimantId($u);

        $this->pdo->prepare('INSERT INTO game_claimant (type, user_id) VALUES ("group", NULL)')->execute();
        $groupClaimant = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO game_crew (claimant_id, name, slug, owner_user_id, join_code)
             VALUES (?, "Crew", "crew-x", ?, "JOINCDE1")'
        )->execute([$groupClaimant, $u]);
        $crewId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO game_crew_member (user_id, crew_id, role) VALUES (?, ?, "captain")')
            ->execute([$u, $crewId]);

        $map = $repo->effectiveClaimantMap([$u]);

        $this->assertSame($groupClaimant, $map[$u]['claimant_id']);
        $this->assertTrue($map[$u]['is_group']);
    }

    public function testEffectiveClaimantMapEmptyInput(): void
    {
        $repo = new GameRepository($this->pdo);
        $this->assertSame([], $repo->effectiveClaimantMap([]));
    }
}
