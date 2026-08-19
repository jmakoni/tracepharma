<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\PartnerType;
use App\Filament\Admin\Resources\Fda\FdaOrganizations\Pages\EditFdaOrganization;
use App\Models\Admin;
use App\Models\Fda\FdaOrganization;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Gs1\Gtin;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FdaOrganizationGlnValidationTest extends TestCase
{
    private const VALID_GLN = '0614141000005';

    private const INVALID_CHECK_DIGIT_GLN = '0614141000006';

    /** @var list<int> */
    private array $adminIds = [];

    /** @var list<int> */
    private array $orgIds = [];

    protected function tearDown(): void
    {
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
        $organization = $this->seedOrganization();
        $this->actAsPlatformAdmin();

        Livewire::test(EditFdaOrganization::class, ['record' => $organization->getKey()])
            ->fillForm(['gln' => self::INVALID_CHECK_DIGIT_GLN])
            ->call('save')
            ->assertHasFormErrors(['gln']);

        $this->assertNull($organization->fresh()?->gln);
    }

    #[Test]
    public function edit_saves_a_valid_gs1_gln(): void
    {
        $organization = $this->seedOrganization();
        $this->actAsPlatformAdmin();
        $gln = $this->unusedValidGln(self::VALID_GLN);

        Livewire::test(EditFdaOrganization::class, ['record' => $organization->getKey()])
            ->fillForm(['gln' => $gln])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($gln, $organization->fresh()?->gln);
    }

    #[Test]
    public function edit_rejects_a_duplicate_organization_gln(): void
    {
        $other = $this->seedOrganization();
        $organization = $this->seedOrganization();
        $gln = $this->unusedValidGln(self::VALID_GLN);
        $other->update(['gln' => $gln]);
        $this->actAsPlatformAdmin();

        Livewire::test(EditFdaOrganization::class, ['record' => $organization->getKey()])
            ->fillForm(['gln' => $gln])
            ->call('save')
            ->assertHasFormErrors(['gln']);

        $this->assertNull($organization->fresh()?->gln);
    }

    #[Test]
    public function edit_allows_clearing_an_optional_gln(): void
    {
        $organization = $this->seedOrganization();
        $gln = $this->unusedValidGln(self::VALID_GLN);
        $organization->update(['gln' => $gln]);
        $this->actAsPlatformAdmin();

        Livewire::test(EditFdaOrganization::class, ['record' => $organization->getKey()])
            ->fillForm(['gln' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($organization->fresh()?->gln);
    }

    private function seedOrganization(): FdaOrganization
    {
        $suffix = Str::lower(Str::random(6));
        $name = 'SSOR Org Gln '.$suffix;

        $organization = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => PartnerType::Wholesaler,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $organization->id;

        return $organization;
    }

    private function unusedValidGln(string $preferred): string
    {
        if (! FdaOrganization::query()->where('gln', $preferred)->exists()) {
            return $preferred;
        }

        do {
            $body = '98'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
            $gln = $body.Gtin::checkDigit($body);
        } while (FdaOrganization::query()->where('gln', $gln)->exists());

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
