<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Clock;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    public function testNowUtcStringFormat(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            Clock::nowUtcString()
        );
    }

    public function testNowUtcIsUtc(): void
    {
        $this->assertSame('UTC', Clock::nowUtc()->getTimezone()->getName());
    }

    public function testUtcPlusSecondsIsInFuture(): void
    {
        $now    = Clock::nowUtcString();
        $future = Clock::utcPlusSeconds(3600);
        $this->assertGreaterThan($now, $future);
    }

    public function testToIso8601(): void
    {
        $this->assertSame('2026-05-01T07:30:00Z', Clock::toIso8601('2026-05-01 07:30:00'));
    }

    public function testToIso8601PassthroughOnInvalid(): void
    {
        $this->assertSame('garbage', Clock::toIso8601('garbage'));
    }
}
