<?php

namespace Tests\Feature\Floor;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\MobilePackWorkstation;
use App\Filament\App\Pages\MobileVerifyProduct;
use App\Filament\App\Pages\PackWorkstation;
use App\Filament\App\Pages\ScanOutWorkstation;
use App\Filament\App\Pages\VerifyProduct;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Floor\FloorLayout;
use App\Support\Floor\FloorShell;
use App\Support\Floor\FloorTaskMenu;
use App\Support\Receiving\ReceiveLayout;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FloorShellTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const WHOLESALER_TENANT_ID = 'f43e01c9-c7d7-452c-bf2c-4c9163877699';

    private const WHOLESALER_DOMAIN = 'wholesaler.internal.vatengi.com';

    private const WHOLESALER_DATABASE = 'tenant_wholesaler_internal_vatengi_com';

    private static bool $demo2Ready = false;

    private static bool $wholesalerReady = false;

    private ?TenantProfile $priorDemo2Profile = null;

    private ?TenantProfile $priorWholesalerProfile = null;

    private ?bool $priorSimplifiedNav = null;

    private ?bool $priorFullOutbound = null;

    #[Test]
    public function pharmacy_tiles_omit_ship_when_scan_out_inaccessible(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser(TenantProfile::Pharmacy);
            $this->actingAs($user);

            $settings = TenantSettings::forTenant($tenant);
            $this->priorSimplifiedNav = $settings->pharmacySimplifiedNavEnabled();
            $this->priorFullOutbound = $settings->pharmacyFullOutboundEnabled();
            $settings->setPharmacySimplifiedNavEnabled(true);
            $settings->setPharmacyFullOutboundEnabled(false);
            $tenant->save();

            $this->assertFalse(ScanOutWorkstation::canAccess());

            $keys = array_column(FloorTaskMenu::tiles(), 'key');
            $this->assertContains('receive', $keys);
            $this->assertContains('scan-first', $keys);
            $this->assertNotContains('ship', $keys);
            $this->assertNotContains('compliance', $keys);
            $this->assertNotContains('settings', $keys);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function wholesaler_tiles_include_ship_and_exclude_admin_surfaces(): void
    {
        $this->initializeWholesalerTenant();

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser(TenantProfile::DrugWholesaler);
            $this->actingAs($user);

            $this->assertTrue(ScanOutWorkstation::canAccess());

            $keys = array_column(FloorTaskMenu::tiles(), 'key');
            $this->assertContains('receive', $keys);
            $this->assertContains('ship', $keys);
            $this->assertContains('transfer', $keys);
            $this->assertNotContains('operations-hub', $keys);
            $this->assertNotContains('users', $keys);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function pack_and_verify_tiles_prefer_floor_urls_when_accessible(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser(TenantProfile::Pharmacy);
            $this->actingAs($user);

            $settings = TenantSettings::forTenant($tenant);
            $this->priorSimplifiedNav = $settings->pharmacySimplifiedNavEnabled();
            $settings->setPharmacySimplifiedNavEnabled(false);
            $tenant->save();

            if (PackWorkstation::canAccess()) {
                $pack = collect(FloorTaskMenu::tiles())->firstWhere('key', 'pack');
                $this->assertNotNull($pack);
                $this->assertStringContainsString('/pack/floor', $pack['url']);
            }

            if (VerifyProduct::canAccess()) {
                $verify = collect(FloorTaskMenu::tiles())->firstWhere('key', 'verify');
                $this->assertNotNull($verify);
                $this->assertStringContainsString('/verify-product/floor', $verify['url']);
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function session_url_prefers_floor_when_floor_cookie_or_phone_viewport(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $desktopPath = parse_url(ReceiveLayout::sessionUrl(1), PHP_URL_PATH) ?? '';
            $this->assertStringNotContainsString('/floor', $desktopPath);

            request()->cookies->set(FloorLayout::COOKIE, FloorLayout::FLOOR);
            $floorPath = parse_url(ReceiveLayout::sessionUrl(1), PHP_URL_PATH) ?? '';
            $this->assertStringContainsString('/floor', $floorPath);

            request()->cookies->set(FloorLayout::COOKIE, FloorLayout::DESKTOP);
            $forcedDesktop = parse_url(ReceiveLayout::sessionUrl(1), PHP_URL_PATH) ?? '';
            $this->assertStringNotContainsString('/floor', $forcedDesktop);

            request()->cookies->remove(FloorLayout::COOKIE);
            request()->cookies->set(FloorShell::VIEWPORT_COOKIE, FloorShell::VIEWPORT_PHONE);
            $phonePath = parse_url(ReceiveLayout::sessionUrl(1), PHP_URL_PATH) ?? '';
            $this->assertStringContainsString('/floor', $phonePath);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function mobile_pack_and_verify_floor_pages_render_when_accessible(): void
    {
        $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            $user = $this->createOwnerUser(TenantProfile::Pharmacy);
            $this->actingAs($user);

            if (MobilePackWorkstation::canAccess()) {
                Livewire::test(MobilePackWorkstation::class)
                    ->assertSuccessful()
                    ->assertSeeHtml('tp-floor-task-menu')
                    ->assertSeeHtml('tp-floor-pack')
                    ->assertSeeHtml('tp-floor-receive__footer')
                    ->assertSeeHtml('tp-floor-receive__camera-overlay')
                    ->assertSeeHtml('tp-floor-camera-counts')
                    ->assertSeeHtml('tp-staged-scan-panel')
                    ->assertSee('Children');
            }

            if (MobileVerifyProduct::canAccess()) {
                Livewire::test(MobileVerifyProduct::class)
                    ->assertSuccessful()
                    ->assertSeeHtml('tp-floor-task-menu')
                    ->assertSeeHtml('tp-floor-verify')
                    ->assertSeeHtml('tp-floor-receive__footer')
                    ->assertSeeHtml('tp-floor-receive__camera-overlay')
                    ->assertSeeHtml('tp-floor-camera-counts')
                    ->assertSeeHtml('tp-staged-scan-panel')
                    ->assertSee('Verify');
            }
        } finally {
            $this->cleanup();
        }
    }

    private function createOwnerUser(TenantProfile $profile): User
    {
        app(TenantRoleSeeder::class)->seedForProfile($profile);

        $user = User::factory()->create([
            'email' => 'floor-shell-'.uniqid('', true).'@example.test',
        ]);
        $user->assignRole(TenantRole::Owner->value);

        return $user;
    }

    private function initializeDemo2Tenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => $profile,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));
        }

        $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);

        $this->priorDemo2Profile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => $profile])->save();

        if (! self::$demo2Ready) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2Ready = true;
        }

        tenancy()->initialize($tenant->fresh());

        return $tenant;
    }

    private function initializeWholesalerTenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::WHOLESALER_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::WHOLESALER_TENANT_ID,
                'name' => 'Demo Wholesaler',
                'profile' => TenantProfile::DrugWholesaler,
                'status' => 'active',
                'tenancy_db_name' => self::WHOLESALER_DATABASE,
            ]));
        }

        $tenant->domains()->firstOrCreate(['domain' => self::WHOLESALER_DOMAIN]);

        $this->priorWholesalerProfile = $tenant->profile instanceof TenantProfile
            ? $tenant->profile
            : TenantProfile::tryFrom((string) $tenant->profile);

        $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();

        if (! self::$wholesalerReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::WHOLESALER_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$wholesalerReady = true;
        }

        tenancy()->initialize($tenant->fresh());

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            $tenant = tenant();
            if ($tenant instanceof Tenant) {
                if ($tenant->getKey() === self::DEMO2_TENANT_ID) {
                    if ($this->priorDemo2Profile !== null) {
                        $tenant->forceFill(['profile' => $this->priorDemo2Profile])->save();
                    }
                    if ($this->priorSimplifiedNav !== null || $this->priorFullOutbound !== null) {
                        $settings = TenantSettings::forTenant($tenant);
                        if ($this->priorSimplifiedNav !== null) {
                            $settings->setPharmacySimplifiedNavEnabled($this->priorSimplifiedNav);
                        }
                        if ($this->priorFullOutbound !== null) {
                            $settings->setPharmacyFullOutboundEnabled($this->priorFullOutbound);
                        }
                        $tenant->save();
                    }
                }
                if ($tenant->getKey() === self::WHOLESALER_TENANT_ID && $this->priorWholesalerProfile !== null) {
                    $tenant->forceFill(['profile' => $this->priorWholesalerProfile])->save();
                }
            }
            tenancy()->end();
        }

        $this->priorSimplifiedNav = null;
        $this->priorFullOutbound = null;

        DB::purge('tenant');
    }
}
