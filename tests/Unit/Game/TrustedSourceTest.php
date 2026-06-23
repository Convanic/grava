<?php
declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Game\GameIngestionService;
use PHPUnit\Framework\TestCase;

final class TrustedSourceTest extends TestCase
{
    public function testStravaAndImportAreTrusted(): void
    {
        $this->assertTrue(GameIngestionService::isTrustedSource('strava'));
        $this->assertTrue(GameIngestionService::isTrustedSource('import'));
    }

    public function testAppAndManualAreNotTrusted(): void
    {
        $this->assertFalse(GameIngestionService::isTrustedSource('app'));
        $this->assertFalse(GameIngestionService::isTrustedSource('manual'));
        $this->assertFalse(GameIngestionService::isTrustedSource(null));
        $this->assertFalse(GameIngestionService::isTrustedSource(''));
    }
}
