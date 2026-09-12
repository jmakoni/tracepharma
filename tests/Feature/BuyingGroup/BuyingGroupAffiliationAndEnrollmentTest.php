<?php

declare(strict_types=1);

namespace Tests\Feature\BuyingGroup;

use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Filament\App\Pages\OrganizationSettings;
use App\Filament\App\Resources\BuyingGroupMembers\Pages\ListBuyingGroupMembers;
use App\Models\BuyingGroupMember;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\BuyingGroup\BuyingGroupEnrollmentAnalytics;
use App\Support\TenantFeatures;
use App\Support\TenantSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BuyingGroupAffiliationAndEnrollmentTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    /** @var list<int> */
    private array $memberIds = [];

    /** @var list<int> */
    private array $userIds = [];

    private ?string $priorAffiliationCode = null;

    protected function tearDown(): void
    {
        $this->cleanupTenantRows();
        parent::tearDown();
    }

    #[Test]
    public function buying_group_owner_can_open_organization_settings_and_save_affiliation_code(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);
        $this->priorAffiliationCode = TenantSettings::forTenant($tenant)->affiliationCode();

        try {
            $this->assertTrue(TenantFeatures::forTenant($tenant)->supportsBuyingGroupNetwork());
            $this->assertFalse(TenantFeatures::forTenant($tenant)->supportsMasterData());
            $this->assertTrue(OrganizationSettings::canAccess());

            Livewire::test(OrganizationSettings::class)
                ->assertSuccessful()
                ->assertFormFieldExists('affiliation_code')
                ->fillForm([
                    'affiliation_code' => 'GPO-DEMO-42',
                ])
                ->call('save')
                ->assertHasNoFormErrors();

            $this->assertSame(
                'GPO-DEMO-42',
                TenantSettings::forTenant($tenant->fresh())->affiliationCode(),
            );
        } finally {
            TenantSettings::forTenant($tenant)->setAffiliationCode($this->priorAffiliationCode);
            $tenant->save();
            $this->cleanupTenantRows();
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function pharmacy_organization_settings_hides_buying_group_program_affiliation_field(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::Pharmacy);

        try {
            $this->actingAsPharmacyOwner();
            $this->assertFalse(TenantFeatures::forTenant($tenant)->supportsBuyingGroupNetwork());
            $this->assertTrue(OrganizationSettings::canAccess());

            Livewire::test(OrganizationSettings::class)
                ->assertSuccessful()
                ->assertFormFieldIsHidden('affiliation_code');
        } finally {
            $this->cleanupTenantRows();
            $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
            tenancy()->end();
        }
    }

    #[Test]
    public function enrollment_analytics_counts_soft_hard_and_affiliation_coverage(): void
    {
        $tenant = $this->initializeDemo2Tenant(TenantProfile::BuyingGroup);

        try {
            $soft = BuyingGroupMember::query()->create([
                'name' => 'Soft Roster Member',
                'status' => 'active',
                'affiliation_code' => 'AFF-A',
            ]);
            $this->memberIds[] = (int) $soft->getKey();

            $hard = BuyingGroupMember::query()->create([
                'name' => 'Hard Linked Member',
                'status' => 'active',
                'member_tenant_id' => (string) \Illuminate\Support\Str::uuid(),
                'affiliation_code' => null,
            ]);
            $this->memberIds[] = (int) $hard->getKey();

            $blank = BuyingGroupMember::query()->create([
                'name' => 'Blank Affiliation Soft',
                'status' => 'active',
            ]);
            $this->memberIds[] = (int) $blank->getKey();

            $summary = app(BuyingGroupEnrollmentAnalytics::class)->summarize();

            $this->assertSame(3, $summary['total']);
            $this->assertSame(2, $summary['soft_linked']);
            $this->assertSame(1, $summary['hard_linked']);
            $this->assertSame(1, $summary['with_affiliation_code']);
            $this->assertSame(33.3, $summary['affiliation_code_pct']);

            $this->actingAsBuyingGroupOwner();

            Livewire::test(ListBuyingGroupMembers::class)
                ->assertSuccessful()
                ->assertSee('Enrollment:')
                ->assertSee('2 soft')
                ->assertSee('1 hard-linked')
                ->assertSee('33.3%');
        } finally {
            $this->cleanupTenantRows();
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

    private function actingAsPharmacyOwner(): User
    {
        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::Pharmacy);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole(TenantRole::Owner->value);
        $this->userIds[] = (int) $user->getKey();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));

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

        $this->artisan('tenants:migrate', [
            '--tenants' => [self::DEMO2_TENANT_ID],
            '--force' => true,
        ])->assertSuccessful();

        tenancy()->initialize($tenant);

        if ($profile === TenantProfile::BuyingGroup) {
            $this->actingAsBuyingGroupOwner();
        }

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
