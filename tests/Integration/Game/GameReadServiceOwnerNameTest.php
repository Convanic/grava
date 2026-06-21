<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameRepository;
use Tests\IntegrationTestCase;

final class GameReadServiceOwnerNameTest extends IntegrationTestCase
{
    public function testRiderClaimantInfoHasName(): void
    {
        $repo = new GameRepository($this->pdo);
        $u = $this->createUser('armin');
        $this->pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute(['Armin L', $u]);
        $cid = $repo->riderClaimantId($u);

        $info = $repo->claimantInfo($cid);
        $this->assertSame('rider', $info['type']);
        $this->assertSame('armin', $info['handle']);
        $this->assertSame('Armin L', $info['name']);
    }

    public function testGroupClaimantInfoUsesCrewSlugAndName(): void
    {
        $repo = new GameRepository($this->pdo);
        $u = $this->createUser('cap');
        $this->pdo->prepare('INSERT INTO game_claimant (type, user_id) VALUES ("group", NULL)')->execute();
        $claimantId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO game_crew (claimant_id, name, slug, owner_user_id, join_code)
             VALUES (?, "Waldrudel", "waldrudel", ?, "JOINCDE2")'
        )->execute([$claimantId, $u]);

        $info = $repo->claimantInfo($claimantId);
        $this->assertSame('group', $info['type']);
        $this->assertSame('waldrudel', $info['handle']);
        $this->assertSame('Waldrudel', $info['name']);
    }
}
