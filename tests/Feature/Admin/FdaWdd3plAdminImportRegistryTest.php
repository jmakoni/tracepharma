<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Filament\Admin\Resources\Fda\FdaWdd3plStagings\Pages\ListFdaWdd3plStagings;
use App\Jobs\ImportFdaDatasetJob;
use App\Models\Admin;
use App\Models\Fda\FdaImportRun;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Models\Fda\FdaWdd3plImportRun;
use App\Models\Fda\FdaWdd3plStaging;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Support\Auth\AdminRoleSeeder;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Fda\FdaWdd3plDataset;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FdaWdd3plAdminImportRegistryTest extends TestCase
{
    /** @var list<int> */
    private array $adminIds = [];

    private string $facilityName = '';

    private string $licenseNumber = '';

    private string $fixturePath = '';

    /** @var list<int> */
    private array $previouslyActiveLicenseIds = [];

    protected function tearDown(): void
    {
        $this->cleanupFixture();

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
    public function admin_import_refreshes_a_registry_facility(): void
    {
        $suffix = Str::lower(Str::random(6));
        $this->facilityName = 'SSOR Admin Imp Alpha '.$suffix;
        $this->licenseNumber = 'LIC-ADMIN-'.$suffix;
        $this->fixturePath = storage_path('app/fda/ssor_admin_wdd_'.$suffix.'.txt');

        $org = FdaOrganization::query()->create([
            'original_name' => $this->facilityName,
            'canonical_name' => CompanyNameNormalizer::canonical($this->facilityName),
            'name' => $this->facilityName,
            'partner_type' => PartnerType::Wholesaler,
            'is_active' => true,
        ]);

        $street = '100 Alpha Way '.$suffix;
        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'facility_name' => $this->facilityName,
            'name' => $this->facilityName,
            'street_address' => $street,
            'city' => 'Austin',
            'state_province' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'address_fingerprint' => AddressFingerprint::fromWdd($street, 'Austin', 'TX', '78701'),
            'contact_person' => 'STALE CONTACT',
            'is_active' => true,
        ]);

        File::ensureDirectoryExists(dirname($this->fixturePath));
        File::put($this->fixturePath, implode("\t", [
            'Type',
            'Facility_Name',
            'Doing_Business_As',
            'Facility_Street',
            'Facility_City',
            'Facility_State',
            'Facility_Zip',
            'License_Number',
            'License_State',
            'License_Expiration_Date',
            'Facility_Contact_Name',
            'Facility_Contact_Phone',
            'Facility_Contact_Email',
            'Reporting_Year',
        ])."\n".implode("\t", [
            'WDD',
            $this->facilityName,
            '',
            $street,
            'Austin',
            'US-TX',
            '78701',
            $this->licenseNumber,
            'US-TX',
            '12/31/2027',
            'Alpha Contact',
            '512-555-0100',
            'alpha@example.test',
            '2026',
        ])."\n");

        $dataset = Mockery::mock(FdaWdd3plDataset::class)->makePartial();
        $dataset->shouldReceive('resolvePath')->andReturn($this->fixturePath);
        $this->app->instance(FdaWdd3plDataset::class, $dataset);

        $this->previouslyActiveLicenseIds = FdaWddLicense::query()
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->actAsPlatformAdmin();

        Bus::fake();

        Livewire::test(ListFdaWdd3plStagings::class)
            ->callAction(TestAction::make('importWdd3pl'), ['fresh_download' => false])
            ->assertNotified('Import queued');

        Bus::assertDispatched(ImportFdaDatasetJob::class, function (ImportFdaDatasetJob $job): bool {
            $this->assertSame(ImportFdaDatasetJob::WDD_COMMAND, $job->command);
            $this->assertSame([], $job->parameters);

            $job->handle();

            return true;
        });

        $this->assertSame('Alpha Contact', $facility->fresh()?->contact_person);
        $this->assertNotNull(
            FdaWddLicense::query()
                ->where('fda_wdd_facility_id', $facility->id)
                ->where('license_number', $this->licenseNumber)
                ->first()
        );
    }

    #[Test]
    public function duplicate_wdd_import_shows_warning_instead_of_queueing(): void
    {
        Bus::fake();
        $this->actAsPlatformAdmin();

        $job = new ImportFdaDatasetJob(ImportFdaDatasetJob::WDD_COMMAND);
        $this->assertTrue(app(UniqueLock::class)->acquire($job));

        try {
            Livewire::test(ListFdaWdd3plStagings::class)
                ->callAction(TestAction::make('importWdd3pl'), ['fresh_download' => false])
                ->assertNotified('Import already running');

            Bus::assertNotDispatched(ImportFdaDatasetJob::class);
        } finally {
            app(UniqueLock::class)->release($job);
        }
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

    private function cleanupFixture(): void
    {
        if ($this->fixturePath !== '' && File::exists($this->fixturePath)) {
            File::delete($this->fixturePath);
        }

        if ($this->licenseNumber !== '') {
            FdaWddLicense::query()->where('license_number', $this->licenseNumber)->delete();
        }

        if ($this->facilityName !== '') {
            FdaWdd3plStaging::query()->where('facility_name', $this->facilityName)->delete();
            FdaWddFacility::query()->where('facility_name', $this->facilityName)->delete();
            FdaOrganizationMatchReview::query()
                ->where('source', 'wdd')
                ->where('original_name', $this->facilityName)
                ->delete();
            FdaOrganization::query()
                ->where('canonical_name', CompanyNameNormalizer::canonical($this->facilityName))
                ->delete();
        }

        if ($this->previouslyActiveLicenseIds !== []) {
            FdaWddLicense::query()
                ->whereIn('id', $this->previouslyActiveLicenseIds)
                ->where('is_active', false)
                ->update(['is_active' => true]);
        }

        if ($this->fixturePath !== '') {
            FdaImportRun::query()
                ->where('source', 'wdd')
                ->where('source_path', $this->fixturePath)
                ->delete();
            FdaWdd3plImportRun::query()->where('source_path', $this->fixturePath)->delete();
        }
    }
}
