<?php

namespace Tests\Feature\Dashboard;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\Analytics;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Widgets\CompliancePulseWidget;
use App\Filament\App\Widgets\FloorQueueWidget;
use App\Filament\App\Widgets\HomeAnalyticsBundleWidget;
use App\Filament\App\Widgets\IntegrationHealthWidget;
use App\Filament\App\Widgets\PrimaryCtasWidget;
use App\Filament\App\Widgets\TodayActivityWidget;
use App\Models\Receiving\ReceivingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\CurrentSite;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Dashboard\DashboardMetrics;
use App\Support\Dashboard\ResolveDashboardWidgets;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?bool $priorJobRolesEnabled = null;

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    #[Test]
    public function resolve_maps_pharmacy_owner_to_lean_keys_and_dashboard_returns_widget_classes(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            $user = $this->actingOwner();
            TenantSettings::forTenant($tenant)->setOnboardingDismissedAt(now());
            $tenant->save();

            $keys = ResolveDashboardWidgets::make($tenant)->forUser($user);

            $this->assertSame([
                'floor_queue',
                'today_activity',
                'compliance_pulse',
                'integration_health',
                'primary_ctas',
            ], $keys);

            Filament::setCurrentPanel(Filament::getPanel('app'));

            $widgets = Livewire::test(Dashboard::class)
                ->assertOk()
                ->instance()
                ->getWidgets();

            $this->assertSame([
                FloorQueueWidget::class,
                TodayActivityWidget::class,
                CompliancePulseWidget::class,
                IntegrationHealthWidget::class,
                PrimaryCtasWidget::class,
            ], $widgets);

            Livewire::test(FloorQueueWidget::class)
                ->assertOk()
                ->assertSee('Floor queue')
                ->assertSee('As of');

            Livewire::test(PrimaryCtasWidget::class)
                ->assertOk()
                ->assertSee('Receive')
                ->assertSee('Operations Hub')
                ->assertSee('Analytics');

            $this->assertTrue(Analytics::canAccess());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function home_appends_analytics_bundle_when_user_enables_analytics_keys(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            $user = $this->actingOwner();
            TenantSettings::forTenant($tenant)->setOnboardingDismissedAt(now());
            $tenant->save();

            $user->setDashboardWidgetPreferences([
                'volume_trends' => true,
            ]);
            $user->save();

            $keys = ResolveDashboardWidgets::make($tenant)->forUser($user->fresh());

            $this->assertContains('volume_trends', $keys);
            $this->assertContains('floor_queue', $keys);

            Filament::setCurrentPanel(Filament::getPanel('app'));

            $widgets = Livewire::test(Dashboard::class)
                ->assertOk()
                ->instance()
                ->getWidgets();

            $this->assertSame([
                FloorQueueWidget::class,
                TodayActivityWidget::class,
                CompliancePulseWidget::class,
                IntegrationHealthWidget::class,
                PrimaryCtasWidget::class,
                HomeAnalyticsBundleWidget::class,
            ], $widgets);

            Livewire::test(HomeAnalyticsBundleWidget::class)
                ->assertOk()
                ->assertSee('Volume trends')
                ->assertSee('As of')
                ->assertSee('Open Analytics');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function buying_group_owner_hides_dashboard_widgets(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::BuyingGroup);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertSame([], ResolveDashboardWidgets::make($tenant)->forUser($user));

            $component = Livewire::test(Dashboard::class)
                ->assertOk()
                ->assertSee('Buying group control plane')
                ->assertDontSee('Floor queue')
                ->assertDontSee('Today’s activity')
                ->assertDontSee('Primary actions');

            $this->assertSame([], $component->instance()->getWidgets());
            $this->assertFalse(Analytics::canAccess());
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function floor_queue_counts_open_sessions_for_current_site(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            $this->actingOwner();
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $site = Site::factory()->owned()->create([
                'name' => 'Dashboard Queue Site',
            ]);
            $this->siteIds[] = (int) $site->getKey();

            $other = Site::factory()->owned()->create([
                'name' => 'Dashboard Other Site',
            ]);
            $this->siteIds[] = (int) $other->getKey();

            $open = ReceivingSession::query()->create([
                'site_id' => $site->getKey(),
                'status' => 'open',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);
            $this->sessionIds[] = (int) $open->getKey();

            ReceivingSession::query()->create([
                'site_id' => $other->getKey(),
                'status' => 'in_progress',
                'expected_parent_count' => 0,
                'confirmed_parent_count' => 0,
                'expected_child_count' => 0,
                'confirmed_child_count' => 0,
                'opened_at' => now(),
            ]);

            CurrentSite::set((int) $site->getKey());

            $queue = DashboardMetrics::make(auth()->user())->floorQueue();

            $this->assertSame(1, $queue['receiving_open']);
            $this->assertSame(0, $queue['shipping_open']);
            $this->assertSame((int) $site->getKey(), $queue['site_id']);
        } finally {
            session()->forget(CurrentSite::SESSION_KEY);
            $this->cleanup($tenant);
        }
    }

    private function actingOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->actingAs($user);

        return $user;
    }

    private function initializeDemo2Tenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => $profile === TenantProfile::BuyingGroup ? 'Demo Buying Group' : 'Demo Pharmacy',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->forceFill(['profile' => $profile])->save();
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

        $this->priorJobRolesEnabled = TenantSettings::forTenant($tenant)->jobRolesEnabled();

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (tenancy()->initialized) {
            foreach ($this->sessionIds as $sessionId) {
                ReceivingSession::query()->whereKey($sessionId)->delete();
            }

            foreach ($this->siteIds as $siteId) {
                Site::query()->whereKey($siteId)->delete();
            }

            if ($this->priorJobRolesEnabled !== null) {
                TenantSettings::forTenant($tenant->fresh() ?? $tenant)
                    ->setJobRolesEnabled($this->priorJobRolesEnabled);
            }

            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            tenancy()->end();
        }

        $this->sessionIds = [];
        $this->siteIds = [];
        $this->priorJobRolesEnabled = null;
    }
}
