<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Filament\Admin\Pages\DashboardPreferences;
use App\Filament\Admin\Pages\PlatformAnalytics;
use App\Filament\Admin\Pages\PlatformDashboardSettings;
use App\Models\Admin;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Auth\Permissions;
use App\Support\Dashboard\AdminAnalyticsMetrics;
use App\Support\Dashboard\AdminDashboardWidgetCatalog;
use App\Support\PlatformSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PlatformAnalyticsPageTest extends TestCase
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
    public function unauthenticated_admin_cannot_access_platform_analytics(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(PlatformAnalytics::canAccess());
    }

    #[Test]
    public function any_authenticated_admin_can_access_platform_analytics(): void
    {
        $support = $this->admin(AdminRole::Support);
        $this->actingAs($support, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse($support->can(Permissions::TenantsManage));
        $this->assertFalse($support->can(Permissions::CatalogManage));
        $this->assertTrue(PlatformAnalytics::canAccess());

        Livewire::test(PlatformAnalytics::class)
            ->assertOk()
            ->assertSee('Platform Analytics')
            ->assertSee('As of');

        $platform = $this->admin(AdminRole::PlatformAdmin);
        $this->actingAs($platform, 'admin');

        $this->assertTrue(PlatformAnalytics::canAccess());

        Livewire::test(PlatformAnalytics::class)
            ->assertOk()
            ->set('rangeDays', 7)
            ->assertSet('rangeDays', 7);
    }

    #[Test]
    public function analytics_metrics_methods_return_arrays(): void
    {
        $metrics = AdminAnalyticsMetrics::make(30);

        foreach (AdminDashboardWidgetCatalog::analyticsKeys() as $key) {
            $payload = $metrics->forKey($key);
            $this->assertIsArray($payload, $key.' should return an array');
            $this->assertNotSame([], array_keys($payload), $key.' should not be an empty list');
        }

        $growth = $metrics->tenantGrowth(30);
        $this->assertArrayHasKey('days', $growth);
        $this->assertArrayHasKey('total', $growth);
        $this->assertArrayHasKey('by_environment', $growth);

        $funnel = $metrics->onboardingFunnel();
        $this->assertArrayHasKey('statuses', $funnel);
        $this->assertArrayHasKey('total', $funnel);
        $this->assertArrayHasKey('average_days_to_provisioned', $funnel);

        $demos = $metrics->demoVolume(30);
        $this->assertArrayHasKey('days', $demos);
        $this->assertArrayHasKey('total', $demos);

        $imports = $metrics->importTrends(30);
        $this->assertArrayHasKey('days', $imports);
        $this->assertArrayHasKey('sources', $imports);
        $this->assertArrayHasKey('success', $imports);

        $unmatched = $metrics->unmatchedAging();
        $this->assertArrayHasKey('bands', $unmatched);
        $this->assertArrayHasKey('total', $unmatched);

        $reviews = $metrics->matchReviewAging();
        $this->assertArrayHasKey('bands', $reviews);
        $this->assertArrayHasKey('confidences', $reviews);
        $this->assertArrayHasKey('total', $reviews);

        $hub = $metrics->hubCoverage();
        $this->assertArrayHasKey('environments', $hub);
        $this->assertArrayHasKey('tenants_with_providers', $hub);
        $this->assertArrayHasKey('active_routes', $hub);

        $activity = $metrics->activityVolume(30);
        $this->assertArrayHasKey('days', $activity);
        $this->assertArrayHasKey('total', $activity);
    }

    #[Test]
    public function dashboard_preferences_page_exists_for_authenticated_admins(): void
    {
        $this->assertTrue(class_exists(DashboardPreferences::class));

        $admin = $this->admin(AdminRole::Support);
        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertTrue(DashboardPreferences::canAccess());

        Livewire::test(DashboardPreferences::class)
            ->assertOk()
            ->assertSee('My dashboard');
    }

    #[Test]
    public function platform_dashboard_settings_are_gated_by_admins_manage(): void
    {
        $support = $this->admin(AdminRole::Support);
        $this->actingAs($support, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse($support->can(Permissions::AdminsManage));
        $this->assertFalse(PlatformDashboardSettings::canAccess());

        $platform = $this->admin(AdminRole::PlatformAdmin);
        $this->actingAs($platform, 'admin');

        $this->assertTrue($platform->can(Permissions::AdminsManage));
        $this->assertTrue(PlatformDashboardSettings::canAccess());

        Livewire::test(PlatformDashboardSettings::class)
            ->assertOk()
            ->assertSee('Platform dashboard');
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
