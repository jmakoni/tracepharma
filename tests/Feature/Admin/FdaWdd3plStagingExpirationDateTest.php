<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\PartnerType;
use App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\Pages\ListFdaWdd3plStagings;
use App\Models\Admin;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWdd3plStaging;
use App\Support\Auth\AdminRoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FdaWdd3plStagingExpirationDateTest extends TestCase
{
    private const FACILITY = 'SSOR WDD Exp Cardinal Car';

    private const ORG = 'SSOR WDD Exp Org';

    /** @var list<int> */
    private array $adminIds = [];

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $stagingIds = [];

    protected function tearDown(): void
    {
        if ($this->stagingIds !== []) {
            FdaWdd3plStaging::query()->whereIn('id', $this->stagingIds)->delete();
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
    public function hyphenated_us_expiration_dates_do_not_crash_the_staging_table(): void
    {
        $org = FdaOrganization::query()->create([
            'original_name' => self::ORG,
            'canonical_name' => strtoupper(self::ORG),
            'name' => self::ORG,
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
        $this->orgIds[] = $org->id;

        $row = FdaWdd3plStaging::query()->create([
            'fda_organization_id' => $org->id,
            'facility_name' => self::FACILITY,
            'city' => 'Dallas',
            'state' => 'TX',
            'facility_type' => 'wdd',
            'license_number' => 'SSOR-EXP-0114',
            'license_state' => 'TX',
            'expiration_date' => '01-14-2027',
            'reporting_year' => 2026,
        ]);
        $this->stagingIds[] = $row->id;

        $this->actAsPlatformAdmin();

        Livewire::test(ListFdaWdd3plStagings::class)
            ->searchTable('Car')
            ->assertSuccessful()
            ->assertSee(self::FACILITY)
            ->assertSee('Jan 14, 2027');
    }

    private function actAsPlatformAdmin(): void
    {
        app(AdminRoleSeeder::class)->seed();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::factory()->create();
        $admin->assignRole(AdminRole::PlatformAdmin->value);
        $this->adminIds[] = (int) $admin->getKey();

        $this->actingAs($admin, 'admin');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }
}
