<?php
declare(strict_types=1);

namespace Tests\Integration\Game\Admin;

use App\Game\Admin\GameAuditService;
use Tests\IntegrationTestCase;

final class GameAuditServiceTest extends IntegrationTestCase
{
    public function testRecordAndRecent(): void
    {
        $svc = new GameAuditService($this->pdo);
        $svc->record(7, 'config_update', 'config:hysteresis_factor', ['before' => '1.15', 'after' => '1.2']);

        $rows = $svc->recent(10);
        $this->assertNotEmpty($rows);
        $row = $rows[0];
        $this->assertSame('config_update', $row['action']);
        $this->assertSame('config:hysteresis_factor', $row['target']);
        $this->assertSame('1.2', $row['detail']['after']);
    }
}
