<?php

namespace Tests\Includes;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/functions.php';

class SidebarNavTest extends TestCase
{
    public function testActiveNavItemGetsAccentClasses(): void
    {
        $this->assertStringContainsString('bg-accent-600/20', sidebar_nav_class('dashboard', 'dashboard'));
    }

    public function testInactiveNavItemGetsMutedClasses(): void
    {
        $class = sidebar_nav_class('dashboard', 'upload');
        $this->assertStringContainsString('text-gray-400', $class);
        $this->assertStringNotContainsString('bg-accent-600/20', $class);
    }
}
