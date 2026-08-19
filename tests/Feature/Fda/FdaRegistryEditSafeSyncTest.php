<?php

namespace Tests\Feature\Fda;

use App\Actions\Fda\ImportFdaDecrs;
use App\Actions\OpenFda\ImportOpenFdaNdcProducts;
use App\Enums\AdminRole;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Filament\Admin\Support\ViewOnlyFdaRegistryResource;
use App\Models\Admin;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaEstablishmentOperation;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Fda\FdaDecrsDataset;
use App\Support\Auth\AdminRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FdaRegistryEditSafeSyncTest extends TestCase
{
    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $establishmentIds = [];

    /** @var list<int> */
    private array $facilityIds = [];

    /** @var list<int> */
    private array $licenseIds = [];

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $packagingIds = [];

    protected function tearDown(): void
    {
        if ($this->packagingIds !== []) {
            FdaProductPackaging::query()->whereIn('id', $this->packagingIds)->delete();
        }

        if ($this->productIds !== []) {
            FdaProduct::query()->whereIn('id', $this->productIds)->delete();
        }

        if ($this->licenseIds !== []) {
            FdaWddLicense::query()->whereIn('id', $this->licenseIds)->delete();
        }

        if ($this->facilityIds !== []) {
            FdaWddFacility::query()->whereIn('id', $this->facilityIds)->delete();
        }

        if ($this->establishmentIds !== []) {
            FdaEstablishmentOperation::query()->whereIn('fda_establishment_id', $this->establishmentIds)->delete();
            FdaEstablishment::query()->whereIn('id', $this->establishmentIds)->delete();
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function saving_a_registry_row_records_dirty_fillable_fields(): void
    {
        $org = $this->organization();

        $org->name = 'Edited Name '.$this->suffix();
        $org->save();

        $this->assertContains('name', $org->fresh()->manuallyEditedFields());
        $this->assertNotContains('city', $org->fresh()->manuallyEditedFields());
    }

    #[Test]
    public function fill_from_fda_skips_edited_fields_and_refreshes_the_rest(): void
    {
        $org = $this->organization();
        $org->name = 'Frozen Name '.$this->suffix();
        $org->save();

        $org->fillFromFda([
            'name' => 'Feed Name',
            'city' => 'Feed City',
            'telephone' => '555-0100',
        ]);

        $org->refresh();

        $this->assertStringStartsWith('Frozen Name', (string) $org->name);
        $this->assertSame('Feed City', $org->city);
        $this->assertSame('555-0100', $org->telephone);
    }

    #[Test]
    public function fill_from_fda_inserts_a_missing_establishment(): void
    {
        $org = $this->organization();
        $fei = '9'.substr((string) crc32($this->suffix()), -9);

        $establishment = FdaEstablishment::query()->firstOrNew(['fei_number' => $fei]);
        $establishment->fillFromFda([
            'fda_organization_id' => $org->id,
            'firm_name' => 'New Plant '.$this->suffix(),
            'city' => 'Austin',
            'address_fingerprint' => AddressFingerprint::make('1 New St', 'Austin', 'TX', '78701', 'US'),
        ]);
        $this->establishmentIds[] = (int) $establishment->id;

        $this->assertTrue($establishment->exists);
        $this->assertSame($fei, $establishment->fei_number);
        $this->assertSame('Austin', $establishment->city);
        $this->assertSame([], $establishment->manuallyEditedFields());
    }

    #[Test]
    public function fill_from_fda_does_not_delist_a_license_with_frozen_is_active(): void
    {
        $org = $this->organization();
        $facility = $this->facility($org);
        $frozen = $this->license($facility, 'FROZEN-'.$this->suffix());
        $open = $this->license($facility, 'OPEN-'.$this->suffix());

        $frozen->setAttribute('manually_edited_fields', ['is_active']);
        $frozen->save();

        $frozen->fillFromFda(['is_active' => false]);
        $open->fillFromFda(['is_active' => false]);

        $this->assertTrue($frozen->fresh()->is_active);
        $this->assertFalse($open->fresh()->is_active);
    }

    #[Test]
    public function delist_query_skips_licenses_whose_is_active_was_edited(): void
    {
        $org = $this->organization();
        $facility = $this->facility($org);
        $frozen = $this->license($facility, 'DL-FROZEN-'.$this->suffix());
        $open = $this->license($facility, 'DL-OPEN-'.$this->suffix());

        $frozen->setAttribute('manually_edited_fields', ['is_active']);
        $frozen->save();

        FdaWddLicense::query()
            ->whereIn('id', [$frozen->id, $open->id])
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('manually_edited_fields')
                    ->orWhereRaw(
                        'NOT JSON_CONTAINS(COALESCE(manually_edited_fields, JSON_ARRAY()), ?)',
                        ['"is_active"'],
                    );
            })
            ->update(['is_active' => false]);

        $this->assertTrue($frozen->fresh()->is_active);
        $this->assertFalse($open->fresh()->is_active);
    }

    #[Test]
    public function decrs_reimport_keeps_an_edited_firm_name_and_still_inserts_missing_ops(): void
    {
        $path = app(FdaDecrsDataset::class)->resolvePath(
            base_path('tests/fixtures/fda/decrs_sample.txt'),
            false
        );

        app(ImportFdaDecrs::class)->handle($path);

        $plant = FdaEstablishment::query()->where('fei_number', '0000001001')->firstOrFail();
        $this->establishmentIds[] = (int) $plant->id;
        $this->orgIds[] = (int) $plant->fda_organization_id;

        FdaEstablishmentOperation::query()->firstOrCreate([
            'fda_establishment_id' => $plant->id,
            'operation_code' => 'MANUAL-KEEP',
        ]);

        $plant->firm_name = 'Edited Acme Fill Plant';
        $plant->save();

        app(ImportFdaDecrs::class)->handle($path);

        $plant->refresh();

        $this->assertSame('Edited Acme Fill Plant', $plant->firm_name);
        $this->assertSame('Austin', $plant->city);
        $this->assertContains('MANUAL-KEEP', $plant->operations()->pluck('operation_code')->all());
        $this->assertContains('MANUFACTURE', $plant->operations()->pluck('operation_code')->all());
    }

    #[Test]
    public function openfda_import_inserts_a_missing_package_and_skips_an_edited_brand_name(): void
    {
        $suffix = $this->suffix();
        $productId = 'ndc-edit-safe-'.$suffix;
        $packageNdc = '99999-'.$suffix.'-01';

        $counts = app(ImportOpenFdaNdcProducts::class)->handle([[
            'product_id' => $productId,
            'product_ndc' => '99999-'.$suffix,
            'brand_name' => 'Feed Brand',
            'generic_name' => 'Feed Generic',
            'labeler_name' => '',
            'finished' => true,
            'packaging' => [
                ['package_ndc' => $packageNdc, 'description' => '30 tablets'],
            ],
        ]]);

        $this->assertSame(1, $counts['fda_upserted']);
        $this->assertSame(1, $counts['packaging_upserted']);

        $product = FdaProduct::query()->where('product_id', $productId)->firstOrFail();
        $this->productIds[] = (int) $product->id;
        $packaging = FdaProductPackaging::query()->where('package_ndc', $packageNdc)->firstOrFail();
        $this->packagingIds[] = (int) $packaging->id;

        $product->brand_name = 'Edited Brand';
        $product->save();

        app(ImportOpenFdaNdcProducts::class)->handle([[
            'product_id' => $productId,
            'product_ndc' => '99999-'.$suffix,
            'brand_name' => 'Feed Brand Again',
            'generic_name' => 'Updated Generic',
            'labeler_name' => '',
            'finished' => true,
            'packaging' => [
                ['package_ndc' => $packageNdc, 'description' => '60 tablets'],
            ],
        ]]);

        $product->refresh();
        $packaging->refresh();

        $this->assertSame('Edited Brand', $product->brand_name);
        $this->assertSame('Updated Generic', $product->generic_name);
        $this->assertSame('60 tablets', $packaging->description);
    }

    #[Test]
    public function fda_registry_resources_allow_edit_but_not_create_or_delete(): void
    {
        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::factory()->create();
        $admin->assignRole(AdminRole::PlatformAdmin->value);

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $resource = new class
        {
            use ViewOnlyFdaRegistryResource;
        };

        $this->assertTrue($resource::canEdit(new FdaOrganization));
        $this->assertFalse($resource::canCreate());
        $this->assertFalse($resource::canDelete(new FdaOrganization));
    }

    private function organization(): FdaOrganization
    {
        $suffix = $this->suffix();
        $name = 'SSOR EditSafe Org '.$suffix;
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

    private function facility(FdaOrganization $org): FdaWddFacility
    {
        $suffix = $this->suffix();
        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => 'SSOR EditSafe Fac '.$suffix,
            'facility_name' => 'SSOR EditSafe Fac '.$suffix,
            'city' => 'Austin',
            'state_province' => 'TX',
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::make('1 Edit St', 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);
        $this->facilityIds[] = (int) $facility->id;

        return $facility;
    }

    private function license(FdaWddFacility $facility, string $number): FdaWddLicense
    {
        $license = FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => $number,
            'jurisdiction' => 'TX',
            'is_active' => true,
        ]);
        $this->licenseIds[] = (int) $license->id;

        return $license;
    }

    private function suffix(): string
    {
        return Str::lower(Str::random(8));
    }
}
