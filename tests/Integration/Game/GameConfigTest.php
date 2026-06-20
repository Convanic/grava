<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\GameConfig;
use Tests\IntegrationTestCase;

final class GameConfigTest extends IntegrationTestCase
{
    public function testReadsSeededDefaults(): void
    {
        $this->pdo->exec("INSERT INTO game_config (config_key, config_value) VALUES
            ('pioneer_p0','100'),('pioneer_k','12'),('pioneer_s','4'),
            ('hysteresis_factor','1.15'),('presence_window_days','90'),
            ('popularity_c','30'),('value_combine','max'),
            ('auth_min_speed_kmh','5'),('auth_max_hacc_m','30'),
            ('auth_require_motion','1'),('start_buffer_m','0'),
            ('curation_per_hint','5'),('curation_per_like','2'),
            ('presence_decay','linear')");

        $cfg = new GameConfig($this->pdo);
        $this->assertSame(100.0, $cfg->float('pioneer_p0'));
        $this->assertSame(12.0, $cfg->float('pioneer_k'));
        $this->assertSame(1.15, $cfg->float('hysteresis_factor'));
        $this->assertSame(90, $cfg->int('presence_window_days'));
        $this->assertTrue($cfg->bool('auth_require_motion'));
        $this->assertSame('max', $cfg->string('value_combine'));
    }

    public function testFallsBackToDefaultWhenKeyMissing(): void
    {
        $cfg = new GameConfig($this->pdo); // game_config leer nach TRUNCATE
        $this->assertSame(100.0, $cfg->float('pioneer_p0'));
        $this->assertSame(1.15, $cfg->float('hysteresis_factor'));
    }
}
