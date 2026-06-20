<?php
declare(strict_types=1);

namespace Tests\Integration\Game\Admin;

use App\Game\Admin\GameAuditService;
use App\Game\Admin\GameConfigAdminService;
use App\Game\GameConfig;
use PDO;
use Tests\IntegrationTestCase;

final class GameConfigAdminServiceTest extends IntegrationTestCase
{
    public function testUpdateValidNumericWritesAndAudits(): void
    {
        // Bekannte Baseline: Default 1.15 sicherstellen (kein vorheriger Override).
        $this->setConfig('hysteresis_factor', '1.15');

        $svc = $this->service();
        $errors = $svc->update(7, ['hysteresis_factor' => '1.2']);

        $this->assertSame([], $errors);
        $this->assertSame('1.2', $this->configValue('hysteresis_factor'));

        $audit = $this->pdo->query(
            "SELECT admin_user_id, action, target, detail_json FROM game_audit WHERE action = 'config_update'"
        )->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $audit);
        $row = $audit[0];
        $this->assertSame('config_update', $row['action']);
        $this->assertSame('config:hysteresis_factor', $row['target']);
        $detail = json_decode((string)$row['detail_json'], true);
        $this->assertSame('1.15', $detail['before']);
        $this->assertSame('1.2', $detail['after']);
    }

    public function testUpdateInvalidNumericLeavesDbUnchangedAndNoAudit(): void
    {
        $this->setConfig('presence_window_days', '90');

        $svc = $this->service();
        $errors = $svc->update(7, ['presence_window_days' => '-5']);

        $this->assertArrayHasKey('presence_window_days', $errors);
        $this->assertNotEmpty($errors);
        $this->assertSame('90', $this->configValue('presence_window_days'));
        $this->assertSame(0, (int)$this->pdo->query(
            "SELECT COUNT(*) FROM game_audit WHERE action = 'config_update'"
        )->fetchColumn());
    }

    public function testUpdateInvalidEnumReturnsError(): void
    {
        $svc = $this->service();
        $errors = $svc->update(7, ['value_combine' => 'avg']);

        $this->assertArrayHasKey('value_combine', $errors);
    }

    private function service(): GameConfigAdminService
    {
        // GameConfig NACH den Overrides konstruieren (cached on first read).
        return new GameConfigAdminService(
            $this->pdo,
            new GameConfig($this->pdo),
            new GameAuditService($this->pdo),
        );
    }

    private function setConfig(string $key, string $value): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_config (config_key, config_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)'
        )->execute([$key, $value]);
    }

    private function configValue(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT config_value FROM game_config WHERE config_key = ?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (string)$v;
    }
}
