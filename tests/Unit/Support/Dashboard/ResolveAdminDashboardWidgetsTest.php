<?php

namespace Tests\Unit\Support\Dashboard;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\Permissions;
use App\Support\Dashboard\AdminDashboardWidgetCatalog;
use App\Support\Dashboard\ResolveAdminDashboardWidgets;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ResolveAdminDashboardWidgetsTest extends TestCase
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
    public function for_admin_uses_platform_defaults_in_catalog_order_when_admin_has_no_prefs(): void
    {
        $keys = ResolveAdminDashboardWidgets::make()->forAdmin($this->platformAdmin());

        $this->assertSame([
            'tenant_census',
            'onboarding_queue',
            'registry_census',
            'registry_exceptions',
            'import_health',
            'hub_health',
            'primary_ctas',
        ], $keys);
    }

    #[Test]
    public function for_admin_overlays_admin_prefs_when_customize_is_allowed(): void
    {
        $admin = $this->platformAdmin([
            'tenant_census' => false,
            'tenant_growth' => true,
        ]);

        $keys = ResolveAdminDashboardWidgets::make()->forAdmin($admin);

        $this->assertSame([
            'onboarding_queue',
            'registry_census',
            'registry_exceptions',
            'import_health',
            'hub_health',
            'primary_ctas',
            'tenant_growth',
        ], $keys);
    }

    #[Test]
    public function for_admin_ignores_admin_prefs_when_customize_is_disabled(): void
    {
        PlatformSettings::setAdminDashboardAllowUserCustomize(false);

        $admin = $this->platformAdmin([
            'tenant_census' => false,
            'tenant_growth' => true,
        ]);

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
    }

    #[Test]
    public function for_admin_drops_disallowed_unknown_and_permission_off_keys(): void
    {
        PlatformSettings::setAdminDashboardAllowed([
            'demo_volume' => true,
            'registry_exceptions' => false,
            'not_a_widget' => true,
        ]);
        PlatformSettings::setAdminDashboardDefaults([
            'tenant_census' => true,
            'onboarding_queue' => true,
            'registry_exceptions' => true,
            'import_health' => true,
            'hub_health' => true,
            'primary_ctas' => true,
            'demo_volume' => true,
            'tenant_growth' => true,
        ]);

        $admin = $this->platformAdmin([
            'demo_volume' => true,
            'registry_exceptions' => true,
            'not_a_widget' => true,
        ]);

        $keys = ResolveAdminDashboardWidgets::make()->forAdmin($admin);

        $this->assertContains('tenant_census', $keys);
        $this->assertContains('tenant_growth', $keys);
        $this->assertContains('demo_volume', $keys);
        $this->assertNotContains('registry_exceptions', $keys);
        $this->assertNotContains('not_a_widget', $keys);
    }

    #[Test]
    public function for_admin_caps_home_at_eight_and_keeps_primary_ctas(): void
    {
        $defaults = [];
        foreach (AdminDashboardWidgetCatalog::keys() as $key) {
            $defaults[$key] = true;
        }

        PlatformSettings::setAdminDashboardDefaults($defaults);

        $admin = $this->platformAdmin($defaults);

        $keys = ResolveAdminDashboardWidgets::make()->forAdmin($admin);

        $this->assertCount(8, $keys);
        $this->assertContains('primary_ctas', $keys);
        $this->assertSame(
            array_values(array_intersect(AdminDashboardWidgetCatalog::keys(), $keys)),
            $keys,
        );
    }

    #[Test]
    public function for_analytics_page_returns_allowed_available_analytics_and_ignores_home_prefs(): void
    {
        PlatformSettings::setAdminDashboardAllowed([
            'hub_coverage' => false,
        ]);
        PlatformSettings::setAdminDashboardDefaults([
            'tenant_growth' => false,
            'onboarding_funnel' => false,
        ]);

        $admin = $this->platformAdmin([
            'tenant_growth' => false,
            'onboarding_funnel' => false,
            'demo_volume' => false,
        ]);

        $keys = ResolveAdminDashboardWidgets::make()->forAnalyticsPage($admin);

        $this->assertContains('tenant_growth', $keys);
        $this->assertContains('registry_growth', $keys);
        $this->assertContains('onboarding_funnel', $keys);
        $this->assertContains('demo_volume', $keys);
        $this->assertContains('activity_volume', $keys);
        $this->assertNotContains('hub_coverage', $keys);
        $this->assertNotContains('tenant_census', $keys);
        $this->assertSame(
            array_values(array_intersect(AdminDashboardWidgetCatalog::analyticsKeys(), $keys)),
            $keys,
        );
    }

    #[Test]
    public function support_admin_sees_registry_widgets_but_not_tenant_catalog_or_hub(): void
    {
        $support = $this->supportAdmin();

        $home = ResolveAdminDashboardWidgets::make()->forAdmin($support);
        $analytics = ResolveAdminDashboardWidgets::make()->forAnalyticsPage($support);

        $this->assertSame([
            'registry_census',
            'registry_exceptions',
            'primary_ctas',
        ], $home);

        $this->assertSame(['registry_growth'], $analytics);
        $this->assertNotContains('hub_coverage', $analytics);
        $this->assertNotContains('activity_volume', $analytics);
        $this->assertNotContains('tenant_growth', $analytics);
        $this->assertNotContains('import_trends', $analytics);
        $this->assertNotContains('unmatched_aging', $analytics);
        $this->assertNotContains('match_review_aging', $analytics);
    }

    #[Test]
    public function missing_allowed_key_stays_allowed_for_lean_defaults(): void
    {
        PlatformSettings::setAdminDashboardAllowed([
            'tenant_growth' => false,
        ]);

        $allowed = PlatformSettings::adminDashboardAllowed();

        $this->assertTrue($allowed['tenant_census']);
        $this->assertTrue($allowed['primary_ctas']);
        $this->assertFalse($allowed['tenant_growth']);
    }

    #[Test]
    public function catalog_is_available_uses_admin_permissions(): void
    {
        $platform = $this->platformAdmin();
        $support = $this->supportAdmin();

        $this->assertTrue(AdminDashboardWidgetCatalog::isAvailable('tenant_census', $platform));
        $this->assertFalse(AdminDashboardWidgetCatalog::isAvailable('tenant_census', $support));
        $this->assertTrue(AdminDashboardWidgetCatalog::isAvailable('import_health', $platform));
        $this->assertFalse(AdminDashboardWidgetCatalog::isAvailable('import_health', $support));
        $this->assertTrue(AdminDashboardWidgetCatalog::isAvailable('registry_census', $support));
        $this->assertTrue(AdminDashboardWidgetCatalog::isAvailable('registry_exceptions', $support));
        $this->assertTrue(AdminDashboardWidgetCatalog::isAvailable('registry_growth', $support));
        $this->assertFalse(AdminDashboardWidgetCatalog::isAvailable('hub_health', $support));
        $this->assertFalse(AdminDashboardWidgetCatalog::isAvailable('hub_coverage', $support));
        $this->assertFalse(AdminDashboardWidgetCatalog::isAvailable('activity_volume', $support));
        $this->assertTrue(AdminDashboardWidgetCatalog::isAvailable('primary_ctas', $support));
        $this->assertTrue(AdminDashboardWidgetCatalog::isAvailable('hub_health', $platform));
        $this->assertTrue(AdminDashboardWidgetCatalog::isAvailable('hub_coverage', $platform));
        $this->assertTrue(AdminDashboardWidgetCatalog::isAvailable('activity_volume', $platform));
        $this->assertTrue($platform->can(Permissions::TenantsManage));
        $this->assertFalse(AdminDashboardWidgetCatalog::isAvailable('unknown', $platform));
        $this->assertFalse(AdminDashboardWidgetCatalog::isAvailable('primary_ctas', null));
    }

    /**
     * @param  array<string, bool>  $widgets
     */
    private function platformAdmin(array $widgets = []): Admin
    {
        return $this->admin(AdminRole::PlatformAdmin, $widgets);
    }

    /**
     * @param  array<string, bool>  $widgets
     */
    private function supportAdmin(array $widgets = []): Admin
    {
        return $this->admin(AdminRole::Support, $widgets);
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
