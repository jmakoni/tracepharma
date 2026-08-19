<?php

namespace Tests\Feature\Dashboard;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Widgets\TodayActivityWidget;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Verification;
use App\Support\Auth\Permissions;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Dashboard\DashboardMetrics;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TodayActivitySiteAccessTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $verificationIds = [];

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $userIds = [];

    #[Test]
    public function site_restricted_user_does_not_see_tenant_wide_vrs_counts(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->seedVerifications();

            $site = Site::factory()->owned()->create(['name' => 'Restricted VRS Site']);
            $this->siteIds[] = (int) $site->getKey();

            $user = $this->createUserWithSites([(int) $site->getKey()]);
            $this->actingAs($user);

            $this->assertFalse($user->can(Permissions::SitesAccessAll));

            $metrics = DashboardMetrics::make($user)->todayActivity();

            $this->assertNull($metrics['vrs_allowed']);
            $this->assertNull($metrics['vrs_blocked']);

            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(TodayActivityWidget::class)
                ->assertOk()
                ->assertSee('Today’s activity')
                ->assertDontSee('Allowed / blocked');
        } finally {
            $this->cleanup($tenant);
        }
    }

    #[Test]
    public function access_all_user_sees_vrs_counts(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            $this->seedVerifications();

            $user = $this->actingOwner();
            $this->assertTrue($user->can(Permissions::SitesAccessAll));

            $metrics = DashboardMetrics::make($user)->todayActivity();

            $this->assertGreaterThanOrEqual(1, $metrics['vrs_allowed']);
            $this->assertGreaterThanOrEqual(1, $metrics['vrs_blocked']);

            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(TodayActivityWidget::class)
                ->assertOk()
                ->assertSee('Allowed / blocked');
        } finally {
            $this->cleanup($tenant);
        }
    }

    private function seedVerifications(): void
    {
        $since = now()->subHours(6);

        $this->verificationIds[] = (int) Verification::query()->create([
            'gtin14' => '30301164005162',
            'serial' => 'TA-ALLOW-'.random_int(1000, 9999),
            'status' => 'verified',
            'created_at' => $since,
        ])->getKey();

        $this->verificationIds[] = (int) Verification::query()->create([
            'gtin14' => '30301164005162',
            'serial' => 'TA-BLOCK-'.random_int(1000, 9999),
            'status' => 'failed',
            'created_at' => $since,
        ])->getKey();
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function createUserWithSites(array $siteIds): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->syncSites($siteIds);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function actingOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->actingAs($user);
        $this->userIds[] = (int) $user->getKey();

        return $user;
    }

    private function initializeDemo2Tenant(): Tenant
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
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
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

        return $tenant;
    }

    private function cleanup(Tenant $tenant): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->verificationIds !== []) {
            Verification::query()->whereKey($this->verificationIds)->delete();
            $this->verificationIds = [];
        }

        foreach ($this->siteIds as $siteId) {
            Site::query()->whereKey($siteId)->delete();
        }
        $this->siteIds = [];

        if ($this->userIds !== []) {
            User::query()->whereKey($this->userIds)->delete();
            $this->userIds = [];
        }

        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        tenancy()->end();
    }
}
