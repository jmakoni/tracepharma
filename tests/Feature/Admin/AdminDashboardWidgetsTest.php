<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\PlatformAnalytics;
use App\Filament\Admin\Widgets\HomeAdminAnalyticsBundleWidget;
use App\Filament\Admin\Widgets\HubHealthWidget;
use App\Filament\Admin\Widgets\ImportHealthWidget;
use App\Filament\Admin\Widgets\OnboardingQueueWidget;
use App\Filament\Admin\Widgets\PrimaryCtasWidget;
use App\Filament\Admin\Widgets\RegistryCensusWidget;
use App\Filament\Admin\Widgets\RegistryExceptionsWidget;
use App\Filament\Admin\Widgets\TenantCensusWidget;
use App\Models\Admin;
use App\Support\AggregationLinkForeignKeyDoctor;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\Permissions;
use App\Support\Dashboard\AdminDashboardMetrics;
use App\Support\Dashboard\ResolveAdminDashboardWidgets;
use App\Support\PlatformSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminDashboardWidgetsTest extends TestCase
{
    /**
     * Central connection isolation. RefreshDatabase/migrate:fresh cannot complete
     * on this schema (catalog partner slug backfill), so transactions are used instead.
     */
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureAdminPreferencesColumn();
        PlatformSettings::forget('admin_dashboard');
        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        PlatformSettings::forget('admin_dashboard');

        parent::tearDown();
    }

    #[Test]
    public function platform_admin_sees_lean_home_widgets(): void
    {
        $admin = $this->admin(AdminRole::PlatformAdmin);
        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertTrue($admin->can(Permissions::TenantsManage));
        $this->assertTrue($admin->can(Permissions::CatalogManage));

        $keys = ResolveAdminDashboardWidgets::make()->forAdmin($admin);

        $this->assertSame([
            'tenant_census',
            'onboarding_queue',
            'registry_census',
            'registry_exceptions',
            'import_health',
            'hub_health',
            'primary_ctas',
        ], $keys);

        $widgets = Livewire::test(Dashboard::class)
            ->assertOk()
            ->instance()
            ->getWidgets();

        $this->assertSame([
            TenantCensusWidget::class,
            OnboardingQueueWidget::class,
            RegistryCensusWidget::class,
            RegistryExceptionsWidget::class,
            ImportHealthWidget::class,
            HubHealthWidget::class,
            PrimaryCtasWidget::class,
        ], $widgets);

        Livewire::test(TenantCensusWidget::class)
            ->assertOk()
            ->assertSee('Tenant census')
            ->assertSee('As of');

        Livewire::test(OnboardingQueueWidget::class)
            ->assertOk()
            ->assertSee('Onboarding queue')
            ->assertSee('As of');

        Livewire::test(RegistryCensusWidget::class)
            ->assertOk()
            ->assertSee('Registry census')
            ->assertSee('As of');

        Livewire::test(RegistryExceptionsWidget::class)
            ->assertOk()
            ->assertSee('Registry exceptions')
            ->assertSee('As of');

        Livewire::test(ImportHealthWidget::class)
            ->assertOk()
            ->assertSee('Import health')
            ->assertSee('As of');

        Livewire::test(HubHealthWidget::class)
            ->assertOk()
            ->assertSee('Hub health')
            ->assertSee('As of');

        Livewire::test(PrimaryCtasWidget::class)
            ->assertOk()
            ->assertSee('Tenants')
            ->assertSee('Customer onboarding')
            ->assertSee('Import runs')
            ->assertSee('Match reviews')
            ->assertSee('EPCIS Hub')
            ->assertSee('Analytics');

        $this->assertTrue(PlatformAnalytics::canAccess());

        $census = AdminDashboardMetrics::make()->tenantCensus();
        $this->assertArrayHasKey('total', $census);
        $this->assertArrayHasKey('by_profile', $census);
        $this->assertArrayHasKey('by_status', $census);
        $this->assertArrayHasKey('as_of', $census);

        $registryCensus = AdminDashboardMetrics::make()->registryCensus();
        $this->assertArrayHasKey('organizations', $registryCensus);
        $this->assertArrayHasKey('establishments', $registryCensus);
        $this->assertArrayHasKey('facilities', $registryCensus);
        $this->assertArrayHasKey('licenses', $registryCensus);
        $this->assertArrayHasKey('products', $registryCensus);
        $this->assertArrayHasKey('as_of', $registryCensus);
    }

    #[Test]
    public function hub_health_surfaces_never_checked_when_doctor_audit_cache_is_empty(): void
    {
        Cache::forget(AggregationLinkForeignKeyDoctor::LAST_AUDIT_CACHE_KEY);

        $drift = AdminDashboardMetrics::make()->aggregationLinkFkDrift();
        $this->assertTrue($drift['never_checked']);
        $this->assertNull($drift['checked_at']);

        Livewire::test(HubHealthWidget::class)
            ->assertOk()
            ->assertSee('has not been checked yet')
            ->assertSee('Check aggregation FK drift');
    }

    #[Test]
    public function support_without_tenants_manage_still_gets_registry_and_ctas(): void
    {
        $admin = $this->admin(AdminRole::Support);
        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse($admin->can(Permissions::TenantsManage));
        $this->assertFalse($admin->can(Permissions::CatalogManage));

        $keys = ResolveAdminDashboardWidgets::make()->forAdmin($admin);

        $this->assertSame([
            'registry_census',
            'registry_exceptions',
            'primary_ctas',
        ], $keys);

        $widgets = Livewire::test(Dashboard::class)
            ->assertOk()
            ->instance()
            ->getWidgets();

        $this->assertSame([
            RegistryCensusWidget::class,
            RegistryExceptionsWidget::class,
            PrimaryCtasWidget::class,
        ], $widgets);

        $this->assertFalse(TenantCensusWidget::canView());
        $this->assertFalse(OnboardingQueueWidget::canView());
        $this->assertFalse(ImportHealthWidget::canView());
        $this->assertFalse(HubHealthWidget::canView());
        $this->assertTrue(RegistryCensusWidget::canView());
        $this->assertTrue(RegistryExceptionsWidget::canView());
        $this->assertTrue(PrimaryCtasWidget::canView());

        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSee('Registry census')
            ->assertSee('Registry exceptions')
            ->assertSee('Primary actions')
            ->assertDontSee('Tenant census')
            ->assertDontSee('Onboarding queue')
            ->assertDontSee('Import health')
            ->assertDontSee('Hub health');

        Livewire::test(PrimaryCtasWidget::class)
            ->assertOk()
            ->assertDontSee('Tenants')
            ->assertDontSee('Customer onboarding')
            ->assertDontSee('EPCIS Hub')
            ->assertSee('Match reviews')
            ->assertSee('Organizations')
            ->assertSee('Import runs')
            ->assertSee('Analytics');
    }

    #[Test]
    public function home_appends_analytics_bundle_when_admin_enables_analytics_keys(): void
    {
        $admin = $this->admin(AdminRole::PlatformAdmin, [
            'tenant_growth' => true,
        ]);
        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $keys = ResolveAdminDashboardWidgets::make()->forAdmin($admin);

        $this->assertContains('tenant_growth', $keys);
        $this->assertContains('tenant_census', $keys);

        $widgets = Livewire::test(Dashboard::class)
            ->assertOk()
            ->instance()
            ->getWidgets();

        $this->assertSame([
            TenantCensusWidget::class,
            OnboardingQueueWidget::class,
            RegistryCensusWidget::class,
            RegistryExceptionsWidget::class,
            ImportHealthWidget::class,
            HubHealthWidget::class,
            PrimaryCtasWidget::class,
            HomeAdminAnalyticsBundleWidget::class,
        ], $widgets);

        Livewire::test(HomeAdminAnalyticsBundleWidget::class)
            ->assertOk()
            ->assertSee('Analytics')
            ->assertSee('As of')
            ->assertSee('Open Analytics');
    }

    /**
     * @param  array<string, bool>  $widgets
     */
    private function admin(AdminRole $role, array $widgets = []): Admin
    {
        $admin = Admin::factory()->create();
        $admin->assignRole($role->value);

        if ($widgets !== []) {
            $admin->setDashboardWidgetPreferences($widgets);
            $admin->save();
        }

        return $admin;
    }

    private function ensureAdminPreferencesColumn(): void
    {
        if (Schema::hasColumn('admins', 'preferences')) {
            return;
        }

        $this->artisan('migrate', [
            '--force' => true,
            '--path' => 'database/migrations/2026_08_16_170000_add_preferences_to_admins_table.php',
        ])->assertSuccessful();
    }
}
