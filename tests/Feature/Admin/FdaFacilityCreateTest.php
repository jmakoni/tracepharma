<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages\CreateFdaEstablishment;
use App\Filament\Admin\Resources\Fda\FdaWddFacilities\Pages\CreateFdaWddFacility;
use App\Models\Admin;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Gs1\Gtin;
use App\Support\Gs1\Sgln;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FdaFacilityCreateTest extends TestCase
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
    public function platform_admin_can_create_establishment_with_identifiers_without_fei(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);
        $org = $this->organization();
        $gln = $this->uniqueGln('70');
        $sgln = Sgln::toUrn($gln, 6);
        $suffix = Str::lower(Str::random(6));

        Livewire::test(CreateFdaEstablishment::class)
            ->fillForm([
                'fda_organization_id' => $org->id,
                'firm_name' => 'Manual Est '.$suffix,
                'name' => 'Manual Est '.$suffix,
                'gln' => $gln,
                'sgln' => $sgln,
                'duns_number' => '80373640412345',
                'dea_number' => 'RS1234563',
                'hin_number' => 'H123456789',
                'chemical_reg_number' => 'CR-EST-001',
                'street_address' => '1 Manual Est Way '.$suffix,
                'city' => 'Melville',
                'state_province' => 'NY',
                'postal_code' => '11747',
                'country_code' => 'US',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = FdaEstablishment::query()->where('firm_name', 'Manual Est '.$suffix)->first();
        $this->assertNotNull($row);
        $this->establishmentIds[] = (int) $row->id;

        $this->assertNull($row->fei_number);
        $this->assertSame($gln, $row->gln);
        $this->assertSame($sgln, $row->sgln);
        $this->assertSame('80373640412345', $row->duns_number);
        $this->assertSame('RS1234563', $row->dea_number);
        $this->assertSame('H123456789', $row->hin_number);
        $this->assertSame('CR-EST-001', $row->chemical_reg_number);

        $this->assertContains('dea_number', $row->manuallyEditedFields());
        $this->assertContains('hin_number', $row->manuallyEditedFields());
        $this->assertContains('duns_number', $row->manuallyEditedFields());
        $this->assertContains('chemical_reg_number', $row->manuallyEditedFields());
        $this->assertContains('gln', $row->manuallyEditedFields());

        $row->fillFromFda([
            'dea_number' => 'RC0000000',
            'hin_number' => 'HIN000000',
            'chemical_reg_number' => 'CR-OVERWRITE',
            'city' => 'Feed City',
        ]);
        $row->refresh();
        $this->assertSame('RS1234563', $row->dea_number);
        $this->assertSame('H123456789', $row->hin_number);
        $this->assertSame('CR-EST-001', $row->chemical_reg_number);
        $this->assertSame('Feed City', $row->city);
    }

    #[Test]
    public function platform_admin_can_create_wdd_facility_with_identifiers(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);
        $org = $this->organization();
        $gln = $this->uniqueGln('71');
        $sgln = Sgln::toUrn($gln, 6);
        $suffix = Str::lower(Str::random(6));

        Livewire::test(CreateFdaWddFacility::class)
            ->fillForm([
                'fda_organization_id' => $org->id,
                'facility_type' => FacilityType::Wdd->value,
                'facility_name' => 'Manual WDD '.$suffix,
                'name' => 'Manual WDD '.$suffix,
                'gln' => $gln,
                'sgln' => $sgln,
                'duns_number' => '01243088098765',
                'dea_number' => 'RW9876543',
                'hin_number' => 'H987654321',
                'chemical_reg_number' => 'CR-WDD-001',
                'street_address' => '53 Summit View Ln '.$suffix,
                'city' => 'Bristol',
                'state_province' => 'VA',
                'postal_code' => '24202',
                'country_code' => 'US',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = FdaWddFacility::query()->where('name', 'Manual WDD '.$suffix)->first();
        $this->assertNotNull($row);
        $this->facilityIds[] = (int) $row->id;

        $this->assertSame($gln, $row->gln);
        $this->assertSame($sgln, $row->sgln);
        $this->assertSame('01243088098765', $row->duns_number);
        $this->assertSame('RW9876543', $row->dea_number);
        $this->assertSame('H987654321', $row->hin_number);
        $this->assertSame('CR-WDD-001', $row->chemical_reg_number);
        $this->assertContains('dea_number', $row->manuallyEditedFields());
        $this->assertContains('hin_number', $row->manuallyEditedFields());
        $this->assertContains('duns_number', $row->manuallyEditedFields());
        $this->assertContains('chemical_reg_number', $row->manuallyEditedFields());

        $row->fillFromFda([
            'dea_number' => 'RC0000000',
            'hin_number' => 'HIN000000',
            'duns_number' => '99988877766655',
            'chemical_reg_number' => 'CR-OVERWRITE',
            'city' => 'Feed City',
        ]);
        $row->refresh();
        $this->assertSame('RW9876543', $row->dea_number);
        $this->assertSame('H987654321', $row->hin_number);
        $this->assertSame('01243088098765', $row->duns_number);
        $this->assertSame('CR-WDD-001', $row->chemical_reg_number);
        $this->assertSame('Feed City', $row->city);
    }

    #[Test]
    public function create_establishment_syncs_name_from_firm_name_when_name_omitted(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);
        $org = $this->organization();
        $suffix = Str::lower(Str::random(6));
        $firm = 'Firm Only '.$suffix;

        Livewire::test(CreateFdaEstablishment::class)
            ->fillForm([
                'fda_organization_id' => $org->id,
                'firm_name' => $firm,
                'name' => null,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = FdaEstablishment::query()->where('firm_name', $firm)->first();
        $this->assertNotNull($row);
        $this->establishmentIds[] = (int) $row->id;
        $this->assertSame($firm, $row->name);
        $this->assertSame('US', $row->country_code);
    }

    #[Test]
    public function duplicate_fei_fails_form_validation(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);
        $org = $this->organization();
        $fei = '9'.substr((string) abs(crc32(Str::random(8))), 0, 9);

        $existing = FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'fei_number' => $fei,
            'firm_name' => 'Existing FEI Plant',
            'name' => 'Existing FEI Plant',
            'address_fingerprint' => AddressFingerprint::make('1 Existing', 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->establishmentIds[] = (int) $existing->id;

        Livewire::test(CreateFdaEstablishment::class)
            ->fillForm([
                'fda_organization_id' => $org->id,
                'fei_number' => $fei,
                'firm_name' => 'Duplicate FEI Plant',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['fei_number']);
    }

    #[Test]
    public function duplicate_wdd_address_fingerprint_fails_form_validation(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);
        $org = $this->organization();
        $suffix = Str::lower(Str::random(6));
        $street = '100 Dup DC '.$suffix;
        $city = 'Bristol';
        $state = 'VA';
        $zip = '24202';

        $existing = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'facility_name' => 'Existing Dup DC '.$suffix,
            'name' => 'Existing Dup DC '.$suffix,
            'street_address' => $street,
            'city' => $city,
            'state_province' => $state,
            'postal_code' => $zip,
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::fromWdd($street, $city, $state, $zip),
            'is_active' => true,
        ]);
        $this->facilityIds[] = (int) $existing->id;

        Livewire::test(CreateFdaWddFacility::class)
            ->fillForm([
                'fda_organization_id' => $org->id,
                'facility_type' => FacilityType::Wdd->value,
                'facility_name' => 'Second Dup DC '.$suffix,
                'street_address' => $street,
                'city' => $city,
                'state_province' => $state,
                'postal_code' => $zip,
                'country_code' => null,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['street_address']);
    }

    #[Test]
    public function wdd_create_without_country_fingerprints_as_us(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);
        $org = $this->organization();
        $suffix = Str::lower(Str::random(6));
        $street = '200 US Default '.$suffix;
        $city = 'Melville';
        $state = 'NY';
        $zip = '11747';

        Livewire::test(CreateFdaWddFacility::class)
            ->fillForm([
                'fda_organization_id' => $org->id,
                'facility_type' => FacilityType::Wdd->value,
                'facility_name' => 'US Default DC '.$suffix,
                'street_address' => $street,
                'city' => $city,
                'state_province' => $state,
                'postal_code' => $zip,
                'country_code' => null,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $row = FdaWddFacility::query()->where('facility_name', 'US Default DC '.$suffix)->first();
        $this->assertNotNull($row);
        $this->facilityIds[] = (int) $row->id;

        $this->assertSame('US', $row->country_code);
        $this->assertSame(
            AddressFingerprint::fromWdd($street, $city, $state, $zip),
            $row->address_fingerprint,
        );
        $this->assertSame('US Default DC '.$suffix, $row->name);
    }

    #[Test]
    public function support_admin_cannot_open_create_establishment_or_wdd(): void
    {
        $this->actAsAdmin(AdminRole::Support);

        Livewire::test(CreateFdaEstablishment::class)->assertForbidden();
        Livewire::test(CreateFdaWddFacility::class)->assertForbidden();
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

    private function organization(): FdaOrganization
    {
        $suffix = Str::lower(Str::random(8));
        $name = 'SSOR Create Org '.$suffix;
        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => PartnerType::Wholesaler,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->id;

        return $org;
    }

    private function uniqueGln(string $marker): string
    {
        do {
            $body = '030404'.$marker.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (
            FdaEstablishment::query()->where('gln', $gln)->exists()
            || FdaWddFacility::query()->where('gln', $gln)->exists()
        );

        return $gln;
    }
}
