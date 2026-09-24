<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\User;
use Database\Seeders\TenantFooterMenuSeeder;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantFooterVersionTest extends TestCase
{
    #[Test]
    public function tenant_footer_shows_recommended_links_copyright_and_version(): void
    {
        config(['tracepharma.app_version' => '9.9.9']);

        (new TenantFooterMenuSeeder)->run();

        $this->actingAs(User::factory()->make());

        $html = view('filament.app.hooks.tenant-footer-menu')->render();

        $this->assertStringContainsString('Settings', $html);
        $this->assertStringContainsString('My profile', $html);
        $this->assertStringContainsString('Getting started', $html);
        $this->assertStringContainsString('Documentation', $html);
        $this->assertStringContainsString('Legal', $html);
        $this->assertStringContainsString('Terms of Service', $html);
        $this->assertStringContainsString('Privacy Policy', $html);
        $this->assertStringContainsString('Support', $html);
        $this->assertStringContainsString(
            '© 2026 Vatengi Systems LLC. TracePharma is a product of Vatengi Systems LLC · 9.9.9',
            $html,
        );
        $this->assertStringNotContainsString('tp-tenant-footer--guest', $html);

        $this->assertStringNotContainsString('My dashboard', $html);
        $this->assertStringNotContainsString('Legal documents', $html);
        $this->assertStringNotContainsString('Integration health', $html);
        $this->assertStringNotContainsString('Alert center', $html);
    }

    #[Test]
    public function guest_login_footer_hides_auth_only_primary_links(): void
    {
        config(['tracepharma.app_version' => '9.9.9']);

        (new TenantFooterMenuSeeder)->run();

        $this->assertTrue(auth()->guest());

        $html = view('filament.app.hooks.tenant-footer-menu')->render();

        $this->assertStringContainsString('tp-tenant-footer--guest', $html);
        $this->assertStringContainsString('Documentation', $html);
        $this->assertStringContainsString('Terms of Service', $html);
        $this->assertStringContainsString('Privacy Policy', $html);
        $this->assertStringContainsString('Support', $html);
        $this->assertStringContainsString(
            '© 2026 Vatengi Systems LLC. TracePharma is a product of Vatengi Systems LLC · 9.9.9',
            $html,
        );

        $this->assertStringNotContainsString('Settings', $html);
        $this->assertStringNotContainsString('My profile', $html);
        $this->assertStringNotContainsString('Getting started', $html);
    }

    #[Test]
    public function tenant_footer_on_floor_receive_shows_copyright_only(): void
    {
        config(['tracepharma.app_version' => '9.9.9']);

        (new TenantFooterMenuSeeder)->run();

        $request = Request::create('/receiving-sessions/1/floor', 'GET');
        $request->setRouteResolver(function () use ($request) {
            $route = new Route(['GET'], 'receiving-sessions/{record}/floor', []);
            $route->name('filament.app.resources.receiving-sessions.floor');
            $route->bind($request);

            return $route;
        });

        app()->instance('request', $request);

        $html = view('filament.app.hooks.tenant-footer-menu')->render();

        $this->assertStringContainsString(
            '© 2026 Vatengi Systems LLC. TracePharma is a product of Vatengi Systems LLC · 9.9.9',
            $html,
        );
        $this->assertStringContainsString('tp-tenant-footer--floor-compact', $html);
        $this->assertStringNotContainsString('Settings', $html);
        $this->assertStringNotContainsString('My profile', $html);
        $this->assertStringNotContainsString('Getting started', $html);
        $this->assertStringNotContainsString('Documentation', $html);
        $this->assertStringNotContainsString('Terms of Service', $html);
        $this->assertStringNotContainsString('Privacy Policy', $html);
        $this->assertStringNotContainsString('tp-tenant-footer__nav', $html);
    }
}
