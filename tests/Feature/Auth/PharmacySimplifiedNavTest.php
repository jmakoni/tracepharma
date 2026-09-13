<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\Analytics;
use App\Filament\App\Pages\BreakPackWorkstation;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Pages\PackWorkstation;
use App\Filament\App\Pages\ScanOutWorkstation;
use App\Filament\App\Resources\OutboundEpcisDocuments\OutboundEpcisDocumentResource;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\SsccLabels\SsccLabelResource;
use App\Filament\App\Resources\TransferringSessions\TransferringSessionResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PharmacySimplifiedNavTest extends TestCase
{
    private const TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private static bool $tenantReady = false;

    #[Test]
    public function simplified_nav_hides_wholesaler_floor_pages_for_pharmacy(): void
    {
        $tenant = $this->initializeTenant(TenantProfile::Pharmacy);

        try {
            TenantSettings::forTenant(tenant())->setPharmacySimplifiedNavEnabled(true);
            tenant()?->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->assertFalse(OperationsHub::shouldRegisterNavigation());
            $this->assertFalse(PackWorkstation::shouldRegisterNavigation());
            $this->assertFalse(BreakPackWorkstation::shouldRegisterNavigation());
            $this->assertFalse(SsccLabelResource::canAccess());
            $this->assertFalse(Analytics::shouldRegisterNavigation());
            $this->assertFalse(TransferringSessionResource::shouldRegisterNavigation());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function pharmacy_default_has_no_scan_out_or_sscc(): void
    {
        $this->initializeTenant(TenantProfile::Pharmacy);

        try {
            TenantSettings::forTenant(tenant())
                ->setPharmacySimplifiedNavEnabled(true)
                ->setPharmacyFullOutboundEnabled(false);
            tenant()?->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $features = TenantFeatures::forTenant(tenant());
            $this->assertFalse($features->supportsPharmacyFullOutbound());
            $this->assertFalse($features->supportsOutboundIntegrations());
            $this->assertFalse(ScanOutWorkstation::canAccess());
            $this->assertFalse(OutboundEpcisDocumentResource::canAccess());
            $this->assertFalse(OutboundShippingSessionResource::canAccess());
            $this->assertFalse(SsccLabelResource::canAccess());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function pharmacy_full_outbound_with_warehouse_tools_unlocks_scan_out_not_sscc(): void
    {
        $this->initializeTenant(TenantProfile::Pharmacy);

        try {
            TenantSettings::forTenant(tenant())
                ->setPharmacySimplifiedNavEnabled(false)
                ->setPharmacyFullOutboundEnabled(true);
            tenant()?->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $features = TenantFeatures::forTenant(tenant());
            $this->assertTrue($features->showsWholesaleOperationsNav());
            $this->assertTrue($features->supportsPharmacyFullOutbound());
            $this->assertTrue(ScanOutWorkstation::canAccess());
            $this->assertTrue(OutboundEpcisDocumentResource::canAccess());
            $this->assertFalse(OutboundShippingSessionResource::canAccess());
            $this->assertFalse(SsccLabelResource::canAccess());
            $this->assertFalse($features->supportsSsccLabeling());
        } finally {
            TenantSettings::forTenant(tenant())
                ->setPharmacySimplifiedNavEnabled(true)
                ->setPharmacyFullOutboundEnabled(false);
            tenant()?->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function pharmacy_keeps_pack_off_sidebar_when_simplified_nav_disabled(): void
    {
        $this->initializeTenant(TenantProfile::Pharmacy);

        try {
            TenantSettings::forTenant(tenant())->setPharmacySimplifiedNavEnabled(false);
            tenant()?->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $features = TenantFeatures::forTenant(tenant());
            $this->assertTrue($features->supportsPacking());
            $this->assertTrue($features->showsWholesaleOperationsNav());
            $this->assertTrue(OperationsHub::shouldRegisterNavigation());
            $this->assertFalse(PackWorkstation::shouldRegisterNavigation());
            $this->assertFalse(BreakPackWorkstation::shouldRegisterNavigation());
            $this->assertFalse(SsccLabelResource::canAccess());

            $hub = Livewire::test(OperationsHub::class)->instance();
            $labels = collect($hub->directories())->pluck('label')->all();
            $this->assertContains('Packing', $labels);
            $this->assertContains('Break & pack', $labels);
            $this->assertTrue($hub->featureMap()['Packing'] ?? false);
        } finally {
            TenantSettings::forTenant(tenant())->setPharmacySimplifiedNavEnabled(true);
            tenant()?->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function pharmacy_simplified_hides_pack_from_hub_feature_map(): void
    {
        $this->initializeTenant(TenantProfile::Pharmacy);

        try {
            TenantSettings::forTenant(tenant())->setPharmacySimplifiedNavEnabled(true);
            tenant()?->save();

            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            // Hub page itself is not in nav; feature map must not advertise Packing
            // (enabled-only map omits disabled desks entirely).
            $this->assertFalse(TenantFeatures::forTenant(tenant())->showsWholesaleOperationsNav());
            $this->assertArrayNotHasKey('Packing', (new OperationsHub)->featureMap());
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function wholesaler_pack_stays_in_sidebar(): void
    {
        $tenant = $this->initializeTenant(TenantProfile::DrugWholesaler);

        try {
            Filament::setCurrentPanel(Filament::getPanel('app'));
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);

            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsPacking());
            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsSsccLabeling());
            $this->assertTrue(PackWorkstation::shouldRegisterNavigation());
            $this->assertTrue(BreakPackWorkstation::shouldRegisterNavigation());
            $this->assertTrue(SsccLabelResource::canAccess());
        } finally {
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            tenancy()->end();
        }
    }

    private function initializeTenant(TenantProfile $profile): Tenant
    {
        $tenant = Tenant::query()->find(self::TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => $profile->value,
                'status' => 'active',
                'tenancy_db_name' => 'tenant_demo2_internal_vatengi_com',
            ]));
            $tenant->domains()->create(['domain' => 'demo2.internal.vatengi.com']);
        }

        $tenant->forceFill(['profile' => $profile])->save();

        if (! self::$tenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();
            self::$tenantReady = true;
        }

        tenancy()->end();
        tenancy()->initialize($tenant->fresh());

        return $tenant->fresh();
    }
}
