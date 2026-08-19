<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages\EditFdaEstablishment;
use App\Filament\Admin\Resources\Fda\FdaProducts\Pages\EditFdaProduct;
use App\Filament\Admin\Resources\Fda\FdaWddFacilities\Pages\EditFdaWddFacility;
use App\Models\Admin;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaWddFacility;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\CompanyNameNormalizer;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FdaRegistryReparentOrganizationTest extends TestCase
{
    /** @var list<int> */
    private array $adminIds = [];

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $establishmentIds = [];

    /** @var list<int> */
    private array $facilityIds = [];

    /** @var list<int> */
    private array $productIds = [];

    protected function tearDown(): void
    {
        if ($this->productIds !== []) {
            FdaProduct::query()->whereIn('id', $this->productIds)->delete();
        }

        if ($this->facilityIds !== []) {
            FdaWddFacility::query()->whereIn('id', $this->facilityIds)->delete();
        }

        if ($this->establishmentIds !== []) {
            FdaEstablishment::query()->whereIn('id', $this->establishmentIds)->delete();
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        if ($this->adminIds !== []) {
            DB::table('model_has_roles')
                ->where('model_type', Admin::class)
                ->whereIn('model_id', $this->adminIds)
                ->delete();
            DB::table('admins')->whereIn('id', $this->adminIds)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function establishment_edit_persists_a_new_parent_organization(): void
    {
        $from = $this->seedOrganization();
        $to = $this->seedOrganization();
        $establishment = $this->seedEstablishment($from);
        $this->actAsPlatformAdmin();

        Livewire::test(EditFdaEstablishment::class, ['record' => $establishment->getKey()])
            ->fillForm(['fda_organization_id' => $to->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($to->id, $establishment->fresh()?->fda_organization_id);
    }

    #[Test]
    public function wdd_facility_edit_persists_a_new_parent_organization(): void
    {
        $from = $this->seedOrganization();
        $to = $this->seedOrganization();
        $facility = $this->seedFacility($from);
        $this->actAsPlatformAdmin();

        Livewire::test(EditFdaWddFacility::class, ['record' => $facility->getKey()])
            ->fillForm(['fda_organization_id' => $to->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($to->id, $facility->fresh()?->fda_organization_id);
    }

    #[Test]
    public function product_edit_persists_a_new_parent_organization(): void
    {
        $from = $this->seedOrganization();
        $to = $this->seedOrganization();
        $product = $this->seedProduct($from);
        $this->actAsPlatformAdmin();

        Livewire::test(EditFdaProduct::class, ['record' => $product->getKey()])
            ->fillForm(['fda_organization_id' => $to->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($to->id, $product->fresh()?->fda_organization_id);
    }

    private function seedOrganization(): FdaOrganization
    {
        $suffix = Str::lower(Str::random(6));
        $name = 'SSOR Reparent Org '.$suffix;

        $organization = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $organization->id;

        return $organization;
    }

    private function seedEstablishment(FdaOrganization $organization): FdaEstablishment
    {
        $suffix = Str::lower(Str::random(6));
        $name = 'SSOR Reparent Est '.$suffix;

        $establishment = FdaEstablishment::query()->create([
            'fda_organization_id' => $organization->id,
            'fei_number' => 'SSORRP'.strtoupper($suffix),
            'firm_name' => $name,
            'name' => $name,
            'city' => 'Austin',
            'state_province' => 'TX',
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::fromWdd('1 Reparent Rd '.$suffix, 'Austin', 'TX', '78701'),
            'is_active' => true,
        ]);
        $this->establishmentIds[] = (int) $establishment->id;

        return $establishment;
    }

    private function seedFacility(FdaOrganization $organization): FdaWddFacility
    {
        $suffix = Str::lower(Str::random(6));
        $name = 'SSOR Reparent Wdd '.$suffix;

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $organization->id,
            'facility_type' => FacilityType::Wdd,
            'facility_name' => $name,
            'name' => $name,
            'city' => 'Dallas',
            'state_province' => 'TX',
            'address_fingerprint' => AddressFingerprint::fromWdd('9 Reparent Rd '.$suffix, 'Dallas', 'TX', '75201'),
            'is_active' => true,
        ]);
        $this->facilityIds[] = (int) $facility->id;

        return $facility;
    }

    private function seedProduct(FdaOrganization $organization): FdaProduct
    {
        $suffix = Str::lower(Str::random(6));

        $product = FdaProduct::query()->create([
            'product_id' => 'SSOR-RP-'.$suffix,
            'product_ndc' => '88883-'.str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT),
            'name' => 'SSOR Reparent Product '.$suffix,
            'fda_organization_id' => $organization->id,
            'product_type' => FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION,
            'is_active' => true,
        ]);
        $this->productIds[] = (int) $product->id;

        return $product;
    }

    private function actAsPlatformAdmin(): Admin
    {
        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::factory()->create();
        $admin->assignRole(AdminRole::PlatformAdmin->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }
}
