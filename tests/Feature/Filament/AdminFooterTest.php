<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminFooterTest extends TestCase
{
    #[Test]
    public function admin_footer_shows_ops_links_copyright_version_and_environment(): void
    {
        config([
            'tracepharma.app_version' => '9.9.9',
            'tracepharma.platform_support_email' => 'ops@example.test',
        ]);

        $html = view('filament.admin.hooks.admin-footer')->render();

        $this->assertStringContainsString('Admin help', $html);
        $this->assertStringContainsString('Platform connections', $html);
        $this->assertStringContainsString('Support', $html);
        $this->assertStringContainsString('mailto:ops@example.test', $html);
        $this->assertStringContainsString('Terms of Service', $html);
        $this->assertStringContainsString('Privacy Policy', $html);
        $this->assertStringContainsString(
            '© 2026 Vatengi Systems LLC. TracePharma is a product of Vatengi Systems LLC · 9.9.9 ·',
            $html,
        );
        $this->assertStringContainsString(app()->environment(), $html);
    }
}
