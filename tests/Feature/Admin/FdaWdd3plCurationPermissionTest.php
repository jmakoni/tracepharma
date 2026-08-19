<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\PartnerType;
use App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\Pages\ListFdaWdd3plStagings;
use App\Filament\Admin\Resources\Fda\FdaWdd3plUnmatcheds\Pages\ListFdaWdd3plUnmatcheds;
use App\Models\Admin;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWdd3plStaging;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\PartnerSlug;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The FDA registry screens are the widest-reach writes in the admin panel.
 * A Support admin triages the queues read-only.
 */
class FdaWdd3plCurationPermissionTest extends TestCase
{
    private const PARTNER_NAME = 'FDA Curation Gate Partner';

    private const UNMATCHED_FACILITY = 'FDA Curation Gate Facility';

    /** @var list<int> */
    private array $adminIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    #[Test]
    public function the_unmatched_queue_refuses_partner_creation_and_linking_from_support(): void
    {
        $record = $this->unmatchedFacility();

        $this->actAsAdmin(AdminRole::Support);

        Livewire::test(ListFdaWdd3plUnmatcheds::class)
            ->assertSuccessful()
            ->assertActionHidden(TestAction::make('createCatalogPartner')->table($record))
            ->assertActionHidden(TestAction::make('linkExistingPartner')->table($record))
            ->mountAction(TestAction::make('createCatalogPartner')->table($record))
            ->assertActionNotMounted()
            ->mountAction(TestAction::make('linkExistingPartner')->table($record))
            ->assertActionNotMounted();

        $this->assertNull($record->fresh()?->resolved_at);
        $this->assertSame(0, FdaOrganization::query()->where('name', self::UNMATCHED_FACILITY)->count());

        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(ListFdaWdd3plUnmatcheds::class)
            ->assertActionVisible(TestAction::make('createCatalogPartner')->table($record))
            ->assertActionVisible(TestAction::make('linkExistingPartner')->table($record));
    }

    /**
     * The link action is the one that resolves a row without any catalog record to hang a
     * policy off, so it is worth proving the write itself never lands.
     */
    #[Test]
    public function a_support_admin_cannot_link_an_unmatched_facility_to_a_catalog_partner(): void
    {
        $record = $this->unmatchedFacility();

        $this->actAsAdmin(AdminRole::Support);

        Livewire::test(ListFdaWdd3plUnmatcheds::class)
            ->mountAction(TestAction::make('linkExistingPartner')->table($record))
            ->callMountedAction(['fda_organization_id' => 1]);

        $record->refresh();

        $this->assertNull($record->resolved_at);
        $this->assertNull($record->fda_organization_id);
    }

    #[Test]
    public function the_staging_screen_refuses_import_and_promote_from_support(): void
    {
        $this->stagingRow();

        $this->actAsAdmin(AdminRole::Support);

        Livewire::test(ListFdaWdd3plStagings::class)
            ->assertSuccessful()
            ->assertActionHidden(TestAction::make('importWdd3pl'))
            ->assertActionHidden(TestAction::make('promoteToCatalog'))
            ->mountAction(TestAction::make('importWdd3pl'))
            ->assertActionNotMounted()
            ->mountAction(TestAction::make('promoteToCatalog'))
            ->assertActionNotMounted();

        // Import truncates staging and promote is a no-op.
        $this->assertSame(1, FdaWdd3plStaging::query()->count());

        $this->actAsAdmin(AdminRole::PlatformAdmin);

        Livewire::test(ListFdaWdd3plStagings::class)
            ->assertActionVisible(TestAction::make('importWdd3pl'))
            ->assertActionHidden(TestAction::make('promoteToCatalog'));
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

    private function unmatchedFacility(): FdaWdd3plUnmatched
    {
        return FdaWdd3plUnmatched::query()->create([
            'facility_name' => self::UNMATCHED_FACILITY,
            'slug_attempt' => PartnerSlug::from(self::UNMATCHED_FACILITY),
            'row_count' => 3,
            'last_seen_at' => now(),
        ]);
    }

    private function fdaOrganization(): FdaOrganization
    {
        return FdaOrganization::query()->create([
            'original_name' => self::PARTNER_NAME,
            'canonical_name' => strtoupper(self::PARTNER_NAME),
            'name' => self::PARTNER_NAME,
            'partner_type' => PartnerType::Wholesaler,
            'country_code' => 'US',
            'is_active' => true,
        ]);
    }

    private function stagingRow(): void
    {
        FdaWdd3plStaging::query()->create([
            'fda_organization_id' => $this->fdaOrganization()->getKey(),
            'facility_name' => self::PARTNER_NAME,
            'street_address' => '200 Beta Way',
            'city' => 'Austin',
            'state' => 'TX',
            'facility_type' => 'wdd',
            'license_number' => 'FDA-GATE-001',
            'license_state' => 'TX',
            'expiration_date' => '12/31/2027',
            'reporting_year' => 2026,
        ]);
    }

    private function cleanup(): void
    {
        FdaWdd3plStaging::query()->truncate();

        FdaWdd3plUnmatched::query()
            ->whereIn('facility_name', [self::UNMATCHED_FACILITY, self::PARTNER_NAME])
            ->delete();

        FdaOrganization::query()
            ->whereIn('name', [self::PARTNER_NAME, self::UNMATCHED_FACILITY])
            ->delete();

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
