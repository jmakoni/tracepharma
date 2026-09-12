<?php

declare(strict_types=1);

namespace Tests\Feature\BuyingGroup;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Resources\BuyingGroupMembers\BuyingGroupMemberResource;
use App\Filament\App\Resources\BuyingGroupMembers\Pages\CreateBuyingGroupMember;
use App\Filament\App\Resources\BuyingGroupMembers\Pages\EditBuyingGroupMember;
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

    /** @var list<int> */
    private array $userIds = [];

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
            $this->userIds[] = (int) $user->getKey();
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
                ->assertSee('Acme Pharmacy Member')
                ->assertSee('Export CSV');
        } finally {
            $this->cleanupTenantRows();
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function buying_group_owner_can_create_member_with_roster_fields(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);

        try {
            $this->actingAsBuyingGroupOwner();

            $primaryGln = $this->validGln('0614141');

            Livewire::test(CreateBuyingGroupMember::class)
                ->fillForm([
                    'name' => 'Roster Create Member',
                    'status' => 'active',
                    'contact_email' => 'roster-create@example.test',
                    'dea_number' => 'AB1234567',
                    'npi' => '1234567890',
                    'state_license_ref' => 'IL-PHARM-1001',
                    'primary_gln' => $primaryGln,
                    'affiliation_code' => 'AFF-NORTH',
                    'program_sku' => 'SKU-GPO-01',
                    'notes' => 'Wave F1 roster enrichment',
                    'sites_count' => 3,
                ])
                ->call('create')
                ->assertHasNoFormErrors();

            $member = BuyingGroupMember::query()->where('name', 'Roster Create Member')->first();
            $this->assertNotNull($member);
            $this->memberIds[] = (int) $member->getKey();

            $this->assertSame('AB1234567', $member->dea_number);
            $this->assertSame('1234567890', $member->npi);
            $this->assertSame('IL-PHARM-1001', $member->state_license_ref);
            $this->assertSame($primaryGln, $member->primary_gln);
            $this->assertSame('AFF-NORTH', $member->affiliation_code);
            $this->assertSame('SKU-GPO-01', $member->program_sku);
            $this->assertSame('Wave F1 roster enrichment', $member->notes);
            $this->assertSame(3, $member->sites_count);
        } finally {
            $this->cleanupTenantRows();
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function buying_group_owner_can_edit_member_roster_fields(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);

        try {
            $this->actingAsBuyingGroupOwner();

            $member = BuyingGroupMember::query()->create([
                'name' => 'Roster Edit Member',
                'status' => 'active',
                'contact_email' => 'roster-edit@example.test',
                'dea_number' => 'AB0000001',
                'affiliation_code' => 'AFF-OLD',
                'sites_count' => 1,
            ]);
            $this->memberIds[] = (int) $member->getKey();

            $primaryGln = $this->validGln('0614142');

            Livewire::test(EditBuyingGroupMember::class, ['record' => $member->getKey()])
                ->fillForm([
                    'name' => 'Roster Edit Member',
                    'status' => 'active',
                    'contact_email' => 'roster-edit@example.test',
                    'dea_number' => 'AB9999999',
                    'npi' => '0987654321',
                    'state_license_ref' => 'NY-RX-55',
                    'primary_gln' => $primaryGln,
                    'affiliation_code' => 'AFF-SOUTH',
                    'program_sku' => 'SKU-GPO-02',
                    'notes' => 'Updated roster notes',
                    'sites_count' => 5,
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $member->refresh();
            $this->assertSame('AB9999999', $member->dea_number);
            $this->assertSame('0987654321', $member->npi);
            $this->assertSame('NY-RX-55', $member->state_license_ref);
            $this->assertSame($primaryGln, $member->primary_gln);
            $this->assertSame('AFF-SOUTH', $member->affiliation_code);
            $this->assertSame('SKU-GPO-02', $member->program_sku);
            $this->assertSame('Updated roster notes', $member->notes);
            $this->assertSame(5, $member->sites_count);
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

    private function actingAsBuyingGroupOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::BuyingGroup);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return $user;
    }

    private function validGln(string $body7): string
    {
        $body12 = str_pad($body7, 12, '0');
        $sum = 0;
        foreach (str_split($body12) as $i => $digit) {
            $sum += ((int) $digit) * (($i % 2 === 0) ? 1 : 3);
        }

        return $body12.(string) ((10 - ($sum % 10)) % 10);
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

        // Always migrate so Wave F1 roster columns land on demo2 between test runs.
        $this->artisan('tenants:migrate', [
            '--tenants' => [self::DEMO2_TENANT_ID],
            '--force' => true,
        ])->assertSuccessful();
        self::$demo2TenantReady = true;

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

        if ($this->userIds !== []) {
            User::query()->whereIn('id', $this->userIds)->delete();
            $this->userIds = [];
        }
    }
}
