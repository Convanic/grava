<?php
declare(strict_types=1);

namespace Tests\Unit\Game\Admin;

use App\Game\Admin\AdminHost;
use PHPUnit\Framework\TestCase;

final class AdminHostTest extends TestCase
{
    public function testExplicitAdminHost(): void
    {
        $this->assertTrue(AdminHost::isAdmin('admin.grava.world', 'admin.grava.world', 'https://grava.world'));
        $this->assertFalse(AdminHost::isAdmin('grava.world', 'admin.grava.world', 'https://grava.world'));
    }

    public function testDerivedFromAppUrlWhenConfiguredHostEmpty(): void
    {
        $this->assertTrue(AdminHost::isAdmin('admin.grava.world', '', 'https://grava.world'));
    }

    public function testCaseInsensitiveAndPortStripped(): void
    {
        $this->assertTrue(AdminHost::isAdmin('Admin.Grava.World:443', 'admin.grava.world', ''));
    }
}
