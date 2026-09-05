<?php

declare(strict_types=1);

namespace Tests\Feature\BuyingGroup;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\BuyingGroupMembers\BuyingGroupMemberResource;
use App\Filament\App\Resources\BuyingGroupMembers\Pages\ListBuyingGroupMembers;
use App\Models\BuyingGroupMember;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\TenantFeatures;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BuyingGroupMemberResourceTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $memberIds = [];

    protected function tearDown(): void
    {
        $this->cleanupTenantRows();
        parent::tearDown();
    }

    #[Test]
    public function buying_group_owner_can_access_member_roster_list(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);

        try {
            app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::BuyingGroup);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $user = User::factory()->create();
            $user->assignRole(TenantRole::Owner->value);
            $this->actingAs($user);
            Filament::setCurrentPanel(Filament::getPanel('app'));

            $this->assertTrue(TenantFeatures::forTenant(tenant())->supportsBuyingGroupNetwork());
            $this->assertTrue(BuyingGroupMemberResource::canAccess());

            $member = BuyingGroupMember::query()->create([
                'name' => 'Acme Pharmacy Member',
                'status' => 'active',
                'contact_email' => 'ops@acme.example',
            ]);
            $this->memberIds[] = (int) $member->getKey();

            Livewire::test(ListBuyingGroupMembers::class)
                ->assertSuccessful()
                ->assertSee('Acme Pharmacy Member');
        } finally {
            $this->cleanupTenantRows();
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function pharmacy_cannot_access_member_roster(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            $this->assertFalse(TenantFeatures::forTenant(tenant())->supportsBuyingGroupNetwork());
            $this->assertFalse(BuyingGroupMemberResource::canAccess());
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

    private function cleanupTenantRows(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->memberIds !== [] && class_exists(BuyingGroupMember::class)) {
            BuyingGroupMember::query()->whereIn('id', $this->memberIds)->delete();
            $this->memberIds = [];
        }
    }
}
