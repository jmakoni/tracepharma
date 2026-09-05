<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages\ListFdaEstablishments;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\Pages\ListFdaOrganizations;
use App\Filament\Admin\Resources\Fda\FdaWddFacilities\Pages\ListFdaWddFacilities;
use App\Models\Admin;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\CompanyNameNormalizer;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FdaStreetAddressSearchTest extends TestCase
{
    /** @var list<int> */
    private array $adminIds = [];

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $establishmentIds = [];

    /** @var list<int> */
    private array $facilityIds = [];

    protected function tearDown(): void
    {
        if ($this->facilityIds !== []) {
            FdaWddFacility::query()->whereIn('id', $this->facilityIds)->delete();
        }

        if ($this->establishmentIds !== []) {
            FdaEstablishment::query()->whereIn('id', $this->establishmentIds)->delete();
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function establishments_list_finds_row_by_street_address_fragment(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);
        $suffix = Str::lower(Str::random(8));
        $street = '9147 Chemstreet Ln '.$suffix;
        $org = $this->organization('Est Search Org '.$suffix);

        $match = FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'firm_name' => 'Street Match Est '.$suffix,
            'name' => 'Street Match Est '.$suffix,
            'street_address' => $street,
            'city' => 'Melville',
            'state_province' => 'NY',
            'postal_code' => '11747',
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::make($street, 'Melville', 'NY', '11747', 'US'),
            'is_active' => true,
        ]);
        $this->establishmentIds[] = (int) $match->id;

        $other = FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'firm_name' => 'Other Est '.$suffix,
            'name' => 'Other Est '.$suffix,
            'street_address' => '1 Unrelated Way '.$suffix,
            'city' => 'Austin',
            'state_province' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::make('1 Unrelated Way '.$suffix, 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->establishmentIds[] = (int) $other->id;

        Livewire::test(ListFdaEstablishments::class)
            ->searchTable('Chemstreet Ln '.$suffix)
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    #[Test]
    public function establishments_list_street_only_token_excludes_name_decoy(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);
        $suffix = Str::lower(Str::random(8));
        $streetToken = 'zxstreet'.$suffix;
        $org = $this->organization('Est Decoy Org '.$suffix);

        // Decoy: name contains a shared word; street does not contain the unique token.
        $decoy = FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'firm_name' => 'Summit View Est '.$suffix,
            'name' => 'Summit View Est '.$suffix,
            'street_address' => '1 Unrelated Way '.$suffix,
            'city' => 'Austin',
            'state_province' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::make('1 Unrelated Way '.$suffix, 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->establishmentIds[] = (int) $decoy->id;

        $match = FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'firm_name' => 'Quiet Est '.$suffix,
            'name' => 'Quiet Est '.$suffix,
            'street_address' => '100 '.$streetToken.' Ave',
            'city' => 'Melville',
            'state_province' => 'NY',
            'postal_code' => '11747',
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::make('100 '.$streetToken.' Ave', 'Melville', 'NY', '11747', 'US'),
            'is_active' => true,
        ]);
        $this->establishmentIds[] = (int) $match->id;

        // Single unique street token must not match the name-only decoy.
        Livewire::test(ListFdaEstablishments::class)
            ->searchTable($streetToken)
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$decoy]);
    }

    #[Test]
    public function organizations_list_finds_row_by_street_address_fragment(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);
        $suffix = Str::lower(Str::random(8));
        $street = '2200 Orgstreet Blvd '.$suffix;

        $match = $this->organization('Street Match Org '.$suffix, $street);
        $other = $this->organization('Other Org '.$suffix, '88 Nowhere Rd '.$suffix);

        Livewire::test(ListFdaOrganizations::class)
            ->searchTable('Orgstreet Blvd '.$suffix)
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    #[Test]
    public function wdd_facilities_list_finds_row_by_street_address_fragment(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);
        $suffix = Str::lower(Str::random(8));
        $street = '5300 Wddstreet Pkwy '.$suffix;
        $org = $this->organization('Wdd Search Org '.$suffix);

        $match = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'facility_name' => 'Street Match WDD '.$suffix,
            'name' => 'Street Match WDD '.$suffix,
            'street_address' => $street,
            'city' => 'Bristol',
            'state_province' => 'VA',
            'postal_code' => '24202',
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::fromWdd($street, 'Bristol', 'VA', '24202'),
            'is_active' => true,
        ]);
        $this->facilityIds[] = (int) $match->id;

        $other = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'facility_name' => 'Other WDD '.$suffix,
            'name' => 'Other WDD '.$suffix,
            'street_address' => '9 Alternate Rd '.$suffix,
            'city' => 'Roanoke',
            'state_province' => 'VA',
            'postal_code' => '24011',
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::fromWdd('9 Alternate Rd '.$suffix, 'Roanoke', 'VA', '24011'),
            'is_active' => true,
        ]);
        $this->facilityIds[] = (int) $other->id;

        Livewire::test(ListFdaWddFacilities::class)
            ->searchTable('Wddstreet Pkwy '.$suffix)
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    private function actAsAdmin(AdminRole $role): Admin
    {
        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::factory()->create();
        $admin->assignRole($role->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }

    private function organization(string $name, ?string $street = null): FdaOrganization
    {
        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => PartnerType::Wholesaler,
            'street_address' => $street,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->id;

        return $org;
    }
}
