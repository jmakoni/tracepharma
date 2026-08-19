<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages\ListFdaEstablishments;
use App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages\ViewFdaEstablishment;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\FdaOrganizationResource;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\Pages\EditFdaOrganization;
use App\Filament\Admin\Resources\Fda\FdaImportRuns\Pages\ListFdaImportRuns;
use App\Filament\Admin\Resources\Fda\FdaImportRuns\Pages\ViewFdaImportRun;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\Pages\ListFdaOrganizations;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\Pages\ViewFdaOrganization;
use App\Filament\Admin\Resources\Fda\FdaProducts\Pages\ListFdaProducts;
use App\Filament\Admin\Resources\Fda\FdaProducts\Pages\ViewFdaProduct;
use App\Filament\Admin\Resources\Fda\FdaWddFacilities\Pages\ListFdaWddFacilities;
use App\Filament\Admin\Resources\Fda\FdaWddFacilities\Pages\ViewFdaWddFacility;
use App\Filament\Admin\Resources\Fda\FdaWddLicenses\Pages\ListFdaWddLicenses;
use App\Models\Admin;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaImportRun;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Fda\AddressFingerprint;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FdaRegistryResourcesTest extends TestCase
{
    /** @var list<int> */
    private array $adminIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
        $this->seedRegistry();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function platform_admin_can_list_and_view_registry_records_including_otc_products(): void
    {
        $this->actAsAdmin(AdminRole::PlatformAdmin);

        $org = FdaOrganization::query()->where('canonical_name', 'SSOR REG ORG')->firstOrFail();
        $est = FdaEstablishment::query()->where('fei_number', 'SSORREG0001')->firstOrFail();
        $fac = FdaWddFacility::query()->where('name', 'SSOR REG FAC')->firstOrFail();
        $run = FdaImportRun::query()->where('source_path', 'ssor-reg-import')->firstOrFail();
        $otc = FdaProduct::query()->where('product_id', 'SSOR-REG-OTC')->firstOrFail();

        Livewire::test(ListFdaOrganizations::class)->assertSuccessful()->assertSee('SSOR REG Org');
        Livewire::test(ViewFdaOrganization::class, ['record' => $org->getKey()])
            ->assertSuccessful()
            ->assertSee('WDD license is not required');

        Livewire::test(ListFdaEstablishments::class)->assertSuccessful()->assertSee('SSORREG0001');
        Livewire::test(ViewFdaEstablishment::class, ['record' => $est->getKey()])->assertSuccessful();

        Livewire::test(ListFdaWddFacilities::class)->assertSuccessful()->assertSee('SSOR REG FAC');
        Livewire::test(ViewFdaWddFacility::class, ['record' => $fac->getKey()])->assertSuccessful();

        Livewire::test(ListFdaWddLicenses::class)->assertSuccessful()->assertSee('SSOR-REG-LIC');

        Livewire::test(ListFdaProducts::class)->assertSuccessful()->assertSee('SSOR OTC')->assertSee('88882-201');
        Livewire::test(ViewFdaProduct::class, ['record' => $otc->getKey()])->assertSuccessful()->assertSee('OTC');

        Livewire::test(ListFdaImportRuns::class)->assertSuccessful()->assertSee('decrs');
        Livewire::test(ViewFdaImportRun::class, ['record' => $run->getKey()])->assertSuccessful();
    }

    #[Test]
    public function support_admin_can_view_registry_screens(): void
    {
        $this->actAsAdmin(AdminRole::Support);

        Livewire::test(ListFdaOrganizations::class)->assertSuccessful();
        Livewire::test(ListFdaProducts::class)->assertSuccessful()->assertSee('SSOR OTC');
        Livewire::test(ListFdaImportRuns::class)->assertSuccessful();
    }

    #[Test]
    public function support_admin_can_view_any_but_cannot_edit_registry_records(): void
    {
        $this->actAsAdmin(AdminRole::Support);

        $org = FdaOrganization::query()->where('canonical_name', 'SSOR REG ORG')->firstOrFail();

        $this->assertTrue(FdaOrganizationResource::canViewAny());
        $this->assertFalse(FdaOrganizationResource::canEdit($org));

        Livewire::test(EditFdaOrganization::class, ['record' => $org->getKey()])
            ->assertForbidden();
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

    private function seedRegistry(): void
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR REG Org',
            'canonical_name' => 'SSOR REG ORG',
            'name' => 'SSOR REG Org',
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);

        FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'fei_number' => 'SSORREG0001',
            'firm_name' => 'SSOR REG Plant',
            'name' => 'SSOR REG Plant',
            'city' => 'Austin',
            'state_province' => 'TX',
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::fromWdd('1 Reg Way', 'Austin', 'TX', '78701'),
            'is_active' => true,
        ]);

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'facility_name' => 'SSOR REG FAC',
            'name' => 'SSOR REG FAC',
            'city' => 'Dallas',
            'state_province' => 'TX',
            'address_fingerprint' => AddressFingerprint::fromWdd('9 Fac Rd', 'Dallas', 'TX', '75201'),
            'is_active' => true,
        ]);

        FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'SSOR-REG-LIC',
            'jurisdiction' => 'TX',
            'is_active' => true,
        ]);

        FdaProduct::query()->create([
            'product_id' => 'SSOR-REG-OTC',
            'product_ndc' => '88882-201',
            'name' => 'SSOR OTC',
            'fda_organization_id' => $org->id,
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_OTC,
            'is_active' => true,
        ]);

        FdaImportRun::query()->create([
            'source' => 'decrs',
            'source_path' => 'ssor-reg-import',
            'rows_read' => 10,
            'rows_inserted' => 8,
            'rows_updated' => 1,
            'rows_skipped' => 1,
            'rows_sent_to_review' => 0,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'duration_ms' => 1200,
        ]);
    }

    private function cleanup(): void
    {
        FdaImportRun::query()->where('source_path', 'ssor-reg-import')->delete();
        FdaProduct::query()->where('product_id', 'SSOR-REG-OTC')->delete();
        FdaWddLicense::query()->where('license_number', 'SSOR-REG-LIC')->delete();
        FdaWddFacility::query()->where('name', 'SSOR REG FAC')->delete();
        FdaEstablishment::query()->where('fei_number', 'SSORREG0001')->delete();
        FdaOrganization::query()->where('canonical_name', 'SSOR REG ORG')->delete();

        if ($this->adminIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->whereIn('model_id', $this->adminIds)
                ->delete();
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
            $this->adminIds = [];
        }
    }
}
