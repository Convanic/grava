<?php
declare(strict_types=1);

namespace Tests\Integration\Game\Admin;

use App\Game\Admin\GameModerationService;
use App\Game\GameConfig;
use App\Game\GameRepository;
use Tests\IntegrationTestCase;

final class GameModerationServiceTest extends IntegrationTestCase
{
    public function testHighVolumeRidersFlagsUserAboveThreshold(): void
    {
        // Override VOR GameConfig-Konstruktion (cached on first read).
        $this->pdo->prepare(
            'INSERT INTO game_config (config_key, config_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        )->execute(['mod_max_passes_per_day', '2']);

        $uid = $this->createUser('vielfahrer');
        $repo = new GameRepository($this->pdo);
        $cid = $repo->riderClaimantId($uid);

        // 3 verschiedene Kanten → 3 Pässe am selben Tag (Tages-Deckel je (edge,user)).
        $day = '2026-06-20';
        $at = '2026-06-20 08:00:00.000';
        for ($i = 0; $i < 3; $i++) {
            $a = $repo->upsertNode(100 + $i * 2, 47.10 + $i * 0.01, 9.60 + $i * 0.01);
            $b = $repo->upsertNode(101 + $i * 2, 47.11 + $i * 0.01, 9.61 + $i * 0.01);
            $geom = json_encode([
                'type' => 'LineString',
                'coordinates' => [[9.60 + $i * 0.01, 47.10 + $i * 0.01], [9.61 + $i * 0.01, 47.11 + $i * 0.01]],
            ]);
            $eid = $repo->upsertEdge(2000 + $i, $a, $b, 120.0, $geom, 'gravel',
                47.10 + $i * 0.01, 9.60 + $i * 0.01, 47.11 + $i * 0.01, 9.61 + $i * 0.01);
            $created = $repo->insertPassIfAbsent($eid, $cid, $uid, 1, $day, $at);
            $this->assertTrue($created, 'Pass auf separater Kante am selben Tag → angelegt');
        }

        $svc = new GameModerationService($this->pdo, new GameConfig($this->pdo));
        $flagged = $svc->highVolumeRiders();

        $this->assertNotEmpty($flagged);
        $match = null;
        foreach ($flagged as $row) {
            if ($row['user_id'] === $uid) {
                $match = $row;
                break;
            }
        }
        $this->assertNotNull($match, 'Vielfahrer muss in der Review-Queue erscheinen');
        $this->assertGreaterThanOrEqual(3, $match['passes_that_day']);
        $this->assertSame('vielfahrer', $match['handle']);
        $this->assertSame($day, $match['ridden_on']);
    }

    public function testSuspiciousSpeedIsEmpty(): void
    {
        $svc = new GameModerationService($this->pdo, new GameConfig($this->pdo));
        $this->assertSame([], $svc->suspiciousSpeed());
    }
}
