<?php

namespace Tests\Unit\Support\Dashboard;

use App\Enums\TenantProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Dashboard\DashboardWidgetCatalog;
use App\Support\Dashboard\ResolveDashboardWidgets;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveDashboardWidgetsTest extends TestCase
{
    #[Test]
    public function for_user_uses_tenant_defaults_in_catalog_order_when_user_has_no_prefs(): void
    {
        $tenant = $this->tenant(TenantProfile::Pharmacy);

        $keys = ResolveDashboardWidgets::make($tenant)->forUser($this->user());

        $this->assertSame([
            'floor_queue',
            'today_activity',
            'compliance_pulse',
            'integration_health',
            'primary_ctas',
        ], $keys);
    }

    #[Test]
    public function for_user_overlays_user_prefs_when_customize_is_allowed(): void
    {
        $tenant = $this->tenant(TenantProfile::Pharmacy);
        $user = $this->user([
            'floor_queue' => false,
            'volume_trends' => true,
        ]);

        $keys = ResolveDashboardWidgets::make($tenant)->forUser($user);

        $this->assertSame([
            'today_activity',
            'compliance_pulse',
            'integration_health',
            'primary_ctas',
            'volume_trends',
        ], $keys);
    }

    #[Test]
    public function for_user_ignores_user_prefs_when_customize_is_disabled(): void
    {
        $tenant = $this->tenant(TenantProfile::Pharmacy, [
            'allow_user_customize' => false,
        ]);
        $user = $this->user([
            'floor_queue' => false,
            'volume_trends' => true,
        ]);

        $keys = ResolveDashboardWidgets::make($tenant)->forUser($user);

        $this->assertSame([
            'floor_queue',
            'today_activity',
            'compliance_pulse',
            'integration_health',
            'primary_ctas',
        ], $keys);
    }

    #[Test]
    public function for_user_drops_disallowed_unknown_and_feature_off_keys(): void
    {
        $tenant = $this->tenant(TenantProfile::Manufacturer, [
            'allowed' => [
                'vrs_rates' => true,
                'compliance_pulse' => false,
                'not_a_widget' => true,
            ],
            'defaults' => [
                'floor_queue' => true,
                'today_activity' => true,
                'compliance_pulse' => true,
                'integration_health' => true,
                'primary_ctas' => true,
                'vrs_rates' => true,
                'volume_trends' => true,
            ],
        ]);
        $user = $this->user([
            'vrs_rates' => true,
            'compliance_pulse' => true,
            'not_a_widget' => true,
        ]);

        $keys = ResolveDashboardWidgets::make($tenant)->forUser($user);

        $this->assertContains('floor_queue', $keys);
        $this->assertContains('volume_trends', $keys);
        $this->assertNotContains('vrs_rates', $keys);
        $this->assertNotContains('compliance_pulse', $keys);
        $this->assertNotContains('not_a_widget', $keys);
    }

    #[Test]
    public function for_user_caps_home_at_eight_and_keeps_primary_ctas(): void
    {
        $defaults = [];
        foreach (DashboardWidgetCatalog::keys() as $key) {
            $defaults[$key] = true;
        }

        $tenant = $this->tenant(TenantProfile::Pharmacy, [
            'defaults' => $defaults,
        ]);
        $user = $this->user($defaults, accessAll: true);

        $keys = ResolveDashboardWidgets::make($tenant)->forUser($user);

        $this->assertCount(8, $keys);
        $this->assertContains('primary_ctas', $keys);
        $this->assertSame(
            array_values(array_intersect(DashboardWidgetCatalog::keys(), $keys)),
            $keys,
        );
    }

    #[Test]
    public function for_analytics_page_returns_allowed_available_analytics_and_ignores_home_prefs(): void
    {
        $tenant = $this->tenant(TenantProfile::Pharmacy, [
            'allowed' => [
                'partner_throughput' => false,
            ],
            'defaults' => [
                'volume_trends' => false,
                'exception_aging' => false,
            ],
        ]);
        $user = $this->user([
            'volume_trends' => false,
            'exception_aging' => false,
            'vrs_rates' => false,
        ], accessAll: true);

        $keys = ResolveDashboardWidgets::make($tenant)->forAnalyticsPage($user);

        $this->assertContains('volume_trends', $keys);
        $this->assertContains('exception_aging', $keys);
        $this->assertContains('vrs_rates', $keys);
        $this->assertContains('site_comparison', $keys);
        $this->assertNotContains('partner_throughput', $keys);
        $this->assertNotContains('floor_queue', $keys);
        $this->assertSame(
            array_values(array_intersect(DashboardWidgetCatalog::analyticsKeys(), $keys)),
            $keys,
        );
    }

    #[Test]
    public function site_comparison_requires_access_all(): void
    {
        $tenant = $this->tenant(TenantProfile::Pharmacy, [
            'defaults' => [
                'site_comparison' => true,
            ],
            'allowed' => [
                'site_comparison' => true,
            ],
        ]);

        $without = ResolveDashboardWidgets::make($tenant)->forAnalyticsPage($this->user());
        $with = ResolveDashboardWidgets::make($tenant)->forAnalyticsPage($this->user(accessAll: true));

        $this->assertNotContains('site_comparison', $without);
        $this->assertContains('site_comparison', $with);
    }

    #[Test]
    public function missing_allowed_key_stays_allowed_for_lean_defaults(): void
    {
        $tenant = $this->tenant(TenantProfile::Pharmacy, [
            'allowed' => [
                'volume_trends' => false,
            ],
        ]);

        $allowed = TenantSettings::forTenant($tenant)->dashboardAllowed();

        $this->assertTrue($allowed['floor_queue']);
        $this->assertTrue($allowed['primary_ctas']);
        $this->assertFalse($allowed['volume_trends']);
    }

    #[Test]
    public function catalog_is_available_uses_existing_feature_methods(): void
    {
        $pharmacy = new TenantFeatures(TenantProfile::Pharmacy);
        $buyingGroup = new TenantFeatures(TenantProfile::BuyingGroup);
        $manufacturer = new TenantFeatures(TenantProfile::Manufacturer);
        $user = $this->user(accessAll: true);

        $this->assertTrue(DashboardWidgetCatalog::isAvailable('vrs_rates', $pharmacy, $user));
        $this->assertFalse(DashboardWidgetCatalog::isAvailable('vrs_rates', $manufacturer, $user));
        $this->assertFalse(DashboardWidgetCatalog::isAvailable('floor_queue', $buyingGroup, $user));
        $this->assertTrue(DashboardWidgetCatalog::isAvailable('atp_expiry', $pharmacy, $user));
        $this->assertFalse(DashboardWidgetCatalog::isAvailable('atp_expiry', $buyingGroup, $user));
        $this->assertFalse(DashboardWidgetCatalog::isAvailable('unknown', $pharmacy, $user));
    }

    /**
     * @param  array<string, mixed>  $dashboard
     */
    private function tenant(TenantProfile $profile, array $dashboard = []): Tenant
    {
        $tenant = new Tenant([
            'name' => 'Dashboard Prefs',
            'profile' => $profile,
        ]);

        if ($dashboard !== []) {
            $tenant->setAttribute('settings', ['dashboard' => $dashboard]);
        }

        return $tenant;
    }

    /**
     * @param  array<string, bool>  $widgets
     */
    private function user(array $widgets = [], bool $accessAll = false): User
    {
        $user = new class extends User
        {
            public bool $accessAll = false;

            public function can($ability, $arguments = []): bool
            {
                return $this->accessAll && $ability === Permissions::SitesAccessAll;
            }
        };

        $user->accessAll = $accessAll;

        if ($widgets !== []) {
            $user->setDashboardWidgetPreferences($widgets);
        }

        return $user;
    }
}
