<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\PartnerType;
use App\Filament\Admin\Resources\Fda\FdaEstablishments\Pages\EditFdaEstablishment;
use App\Models\Admin;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
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

class FdaEstablishmentGlnValidationTest extends TestCase
{
    private const VALID_GLN = '0614141000005';

    private const INVALID_CHECK_DIGIT_GLN = '0614141000006';

    /** @var list<int> */
    private array $adminIds = [];

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $establishmentIds = [];

    protected function tearDown(): void
    {
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
    public function edit_rejects_a_gln_with_a_bad_check_digit(): void
    {
        $establishment = $this->seedEstablishment();
        $this->actAsPlatformAdmin();

        Livewire::test(EditFdaEstablishment::class, ['record' => $establishment->getKey()])
            ->fillForm(['gln' => self::INVALID_CHECK_DIGIT_GLN])
            ->call('save')
            ->assertHasFormErrors(['gln']);

        $this->assertNull($establishment->fresh()?->gln);
    }

    #[Test]
    public function edit_saves_a_valid_gs1_gln(): void
    {
        $establishment = $this->seedEstablishment();
        $this->actAsPlatformAdmin();
        $gln = $this->unusedValidGln(self::VALID_GLN);

        Livewire::test(EditFdaEstablishment::class, ['record' => $establishment->getKey()])
            ->fillForm(['gln' => $gln])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($gln, $establishment->fresh()?->gln);
    }

    #[Test]
    public function edit_rejects_a_duplicate_establishment_gln(): void
    {
        $other = $this->seedEstablishment();
        $establishment = $this->seedEstablishment();
        $gln = $this->unusedValidGln(self::VALID_GLN);
        $other->update(['gln' => $gln]);
        $this->actAsPlatformAdmin();

        Livewire::test(EditFdaEstablishment::class, ['record' => $establishment->getKey()])
            ->fillForm(['gln' => $gln])
            ->call('save')
            ->assertHasFormErrors(['gln']);

        $this->assertNull($establishment->fresh()?->gln);
    }

    #[Test]
    public function edit_allows_clearing_an_optional_gln(): void
    {
        $establishment = $this->seedEstablishment();
        $gln = $this->unusedValidGln(self::VALID_GLN);
        $establishment->update(['gln' => $gln]);
        $this->actAsPlatformAdmin();

        Livewire::test(EditFdaEstablishment::class, ['record' => $establishment->getKey()])
            ->fillForm(['gln' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($establishment->fresh()?->gln);
    }

    #[Test]
    public function edit_saves_duns_number(): void
    {
        $establishment = $this->seedEstablishment();
        $this->actAsPlatformAdmin();
        $duns = $this->unusedDuns();

        Livewire::test(EditFdaEstablishment::class, ['record' => $establishment->getKey()])
            ->fillForm(['duns_number' => $duns])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($duns, $establishment->fresh()?->duns_number);
    }

    private function seedEstablishment(): FdaEstablishment
    {
        $suffix = Str::lower(Str::random(6));
        $name = 'SSOR Est Gln '.$suffix;

        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->id;

        $establishment = FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'fei_number' => 'SSORGLN'.strtoupper($suffix),
            'firm_name' => $name,
            'name' => $name,
            'city' => 'Austin',
            'state_province' => 'TX',
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::fromWdd('1 Gln Rd '.$suffix, 'Austin', 'TX', '78701'),
            'is_active' => true,
        ]);
        $this->establishmentIds[] = (int) $establishment->id;

        return $establishment;
    }

    private function unusedValidGln(string $preferred): string
    {
        if (! FdaEstablishment::query()->where('gln', $preferred)->exists()) {
            return $preferred;
        }

        do {
            $body = '98'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (FdaEstablishment::query()->where('gln', $gln)->exists());

        return $gln;
    }

    private function unusedDuns(): string
    {
        do {
            $duns = str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);
        } while (FdaEstablishment::query()->where('duns_number', $duns)->exists()
            || FdaOrganization::query()->where('duns_number', $duns)->exists());

        return $duns;
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
