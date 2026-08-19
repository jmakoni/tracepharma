<?php

namespace Tests\Feature\Dashboard;

use App\Enums\TenantProfile;
use App\Filament\Admin\Pages\Dashboard as AdminDashboard;
use App\Filament\App\Pages\Dashboard as AppDashboard;
use App\Models\Tenant;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardNavIconOnlyTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function app_dashboard_nav_item_is_icon_only(): void
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        try {
            $this->assertNavItemIsIconOnly(AppDashboard::getNavigationItems());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function admin_dashboard_nav_item_is_icon_only(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertNavItemIsIconOnly(AdminDashboard::getNavigationItems());
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function assertNavItemIsIconOnly(array $items): void
    {
        $this->assertCount(1, $items);
        $item = $items[0];
        $attributes = $item->getExtraAttributes();

        $this->assertSame('Dashboard', $item->getLabel());
        $this->assertStringContainsString('tp-nav-icon-only', (string) ($attributes['class'] ?? ''));
        $this->assertSame('Dashboard', $attributes['title'] ?? null);
        $this->assertSame('Dashboard', $attributes['aria-label'] ?? null);
    }
}
