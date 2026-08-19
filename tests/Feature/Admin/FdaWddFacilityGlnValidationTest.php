<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Filament\Admin\Resources\Fda\FdaWddFacilities\Pages\EditFdaWddFacility;
use App\Models\Admin;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Gs1\Gtin;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FdaWddFacilityGlnValidationTest extends TestCase
{
    private const VALID_GLN = '0614141000005';

    private const INVALID_CHECK_DIGIT_GLN = '0614141000006';

    /** @var list<int> */
    private array $adminIds = [];

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $facilityIds = [];

    protected function tearDown(): void
    {
        if ($this->facilityIds !== []) {
            FdaWddFacility::query()->whereIn('id', $this->facilityIds)->delete();
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
    public function edit_rejects_a_gln_with_a_bad_check_digit(): void
    {
        $facility = $this->seedFacility();
        $this->actAsPlatformAdmin();

        Livewire::test(EditFdaWddFacility::class, ['record' => $facility->getKey()])
            ->fillForm(['gln' => self::INVALID_CHECK_DIGIT_GLN])
            ->call('save')
            ->assertHasFormErrors(['gln']);

        $this->assertNull($facility->fresh()?->gln);
    }

    #[Test]
    public function edit_saves_a_valid_gs1_gln(): void
    {
        $facility = $this->seedFacility();
        $this->actAsPlatformAdmin();
        $gln = $this->unusedValidGln(self::VALID_GLN);

        Livewire::test(EditFdaWddFacility::class, ['record' => $facility->getKey()])
            ->fillForm(['gln' => $gln])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($gln, $facility->fresh()?->gln);
    }

    #[Test]
    public function edit_rejects_a_duplicate_facility_gln(): void
    {
        $other = $this->seedFacility();
        $facility = $this->seedFacility();
        $gln = $this->unusedValidGln(self::VALID_GLN);
        $other->update(['gln' => $gln]);
        $this->actAsPlatformAdmin();

        Livewire::test(EditFdaWddFacility::class, ['record' => $facility->getKey()])
            ->fillForm(['gln' => $gln])
            ->call('save')
            ->assertHasFormErrors(['gln']);

        $this->assertNull($facility->fresh()?->gln);
    }

    #[Test]
    public function edit_allows_clearing_an_optional_gln(): void
    {
        $facility = $this->seedFacility();
        $gln = $this->unusedValidGln(self::VALID_GLN);
        $facility->update(['gln' => $gln]);
        $this->actAsPlatformAdmin();

        Livewire::test(EditFdaWddFacility::class, ['record' => $facility->getKey()])
            ->fillForm(['gln' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($facility->fresh()?->gln);
    }

    private function seedFacility(): FdaWddFacility
    {
        $suffix = Str::lower(Str::random(6));
        $name = 'SSOR Wdd Gln '.$suffix;

        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => PartnerType::Wholesaler,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->id;

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'facility_name' => $name,
            'name' => $name,
            'city' => 'Dallas',
            'state_province' => 'TX',
            'address_fingerprint' => AddressFingerprint::fromWdd('9 Gln Rd '.$suffix, 'Dallas', 'TX', '75201'),
            'is_active' => true,
        ]);
        $this->facilityIds[] = (int) $facility->id;

        return $facility;
    }

    private function unusedValidGln(string $preferred): string
    {
        if (! FdaWddFacility::query()->where('gln', $preferred)->exists()) {
            return $preferred;
        }

        do {
            $body = '98'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (FdaWddFacility::query()->where('gln', $gln)->exists());

        return $gln;
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
