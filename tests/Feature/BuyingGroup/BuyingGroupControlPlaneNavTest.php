<?php

declare(strict_types=1);

namespace Tests\Feature\BuyingGroup;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Enums\TenantType;
use App\Filament\App\Pages\OperationsHub;
use App\Filament\App\Pages\ScanInWorkstation;
use App\Filament\App\Pages\ScanOutWorkstation;
use App\Filament\App\Resources\BuyingGroupMembers\BuyingGroupMemberResource;
use App\Filament\App\Resources\BuyingGroupMembers\Pages\ListBuyingGroupMembers;
use App\Filament\App\Resources\OutboundShippingSessions\OutboundShippingSessionResource;
use App\Filament\App\Resources\ReceivingSessions\ReceivingSessionResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\CustomerOnboarding\OrganizationTypeMapper;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BuyingGroupControlPlaneNavTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    #[Test]
    public function buying_group_display_type_is_buying_group_not_distributor(): void
    {
        $this->assertSame(TenantType::BuyingGroup, TenantProfile::BuyingGroup->tenantType());
        $this->assertSame('Buying group', TenantProfile::BuyingGroup->tenantType()->label());
        $this->assertSame('Buying group', OrganizationTypeMapper::options()['buying_group']);
        $this->assertSame(
            TenantType::BuyingGroup,
            OrganizationTypeMapper::map('buying_group')['type'],
        );
    }

    #[Test]
    public function buying_group_has_no_receive_or_ship_nav(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::BuyingGroup);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertTrue(BuyingGroupMemberResource::canAccess());
            $this->assertTrue(BuyingGroupMemberResource::shouldRegisterNavigation());

            $this->assertFalse(ReceivingSessionResource::canAccess());
            $this->assertFalse(ScanInWorkstation::canAccess());
            $this->assertFalse(ScanInWorkstation::shouldRegisterNavigation());

            $this->assertFalse(OutboundShippingSessionResource::canAccess());
            $this->assertFalse(ScanOutWorkstation::canAccess());
            $this->assertFalse(ScanOutWorkstation::shouldRegisterNavigation());

            $this->assertFalse(OperationsHub::canAccess());
        } finally {
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function member_roster_empty_state_describes_control_plane_scope(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::BuyingGroup);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            Livewire::test(ListBuyingGroupMembers::class)
                ->assertSuccessful()
                ->assertSee('No members yet')
                ->assertSee('ATP readiness')
                ->assertSee('compliance APIs');
        } finally {
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            tenancy()->end();
        }
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

        return $tenant;
    }
}
