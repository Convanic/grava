<?php
declare(strict_types=1);

namespace Tests\Unit\Game\Admin;

use App\Game\Admin\AdminGuard;
use PHPUnit\Framework\TestCase;

final class AdminGuardTest extends TestCase
{
    public function testMatchesCommaListCaseInsensitively(): void
    {
        $guard = new AdminGuard('a@x.de, Admin@Y.de');
        $this->assertTrue($guard->isAdminEmail('admin@y.de'));
        $this->assertTrue($guard->isAdminEmail('a@x.de'));
        $this->assertFalse($guard->isAdminEmail('nope@z.de'));
        $this->assertFalse($guard->isAdminEmail(''));
    }

    public function testEmptyConfigDeniesAll(): void
    {
        $guard = new AdminGuard('');
        $this->assertFalse($guard->isAdminEmail('admin@y.de'));
        $this->assertFalse($guard->isAdminEmail(''));
    }
}
