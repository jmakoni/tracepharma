<?php

namespace Tests\Feature;

use App\Actions\Fda\ImportFdaWdd3plStaging;
use App\Actions\Fda\ImportFdaWddToRegistry;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Models\Fda\FdaImportRun;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Models\Fda\FdaWdd3plImportRun;
use App\Models\Fda\FdaWdd3plStaging;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Jobs\SyncTenantAtpLicensesFromFda;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Fda\FdaWdd3plDataset;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportFdaWdd3plTest extends TestCase
{
    private const ALPHA_NAME = 'Test WDD Partner Alpha';

    private const BETA_NAME = 'Test WDD Partner Beta';

    #[Test]
    public function fixture_import_matches_partners_and_truncates_on_rerun(): void
    {
        $this->cleanupFixtureRows();

        $alpha = $this->createFixtureOrganization(self::ALPHA_NAME);
        $beta = $this->createFixtureOrganization(self::BETA_NAME);

        $path = app(FdaWdd3plDataset::class)->resolvePath(
            base_path('tests/fixtures/fda/wdd_3pl_sample.txt'),
            false
        );

        $counts = app(ImportFdaWdd3plStaging::class)->handle($path);

        $this->assertSame(3, $counts['read']);
        $this->assertSame(2, $counts['matched']);
        $this->assertSame(1, $counts['skipped_unmatched']);
        $this->assertSame(2, $counts['inserted']);
        $this->assertSame(
            ['Totally Unknown Facility Co' => 1],
            $counts['unmatched_facilities']
        );

        $this->assertSame(2, FdaWdd3plStaging::query()->count());

        $alphaRow = FdaWdd3plStaging::query()->where('fda_organization_id', $alpha->id)->first();
        $this->assertNotNull($alphaRow);
        $this->assertSame(self::ALPHA_NAME, $alphaRow->facility_name);
        $this->assertSame('TX', $alphaRow->license_state);
        $this->assertSame('wdd', $alphaRow->facility_type);
        $this->assertSame('LIC-ALPHA-001', $alphaRow->license_number);
        $this->assertSame('78701', $alphaRow->zip);
        $this->assertSame(2026, $alphaRow->reporting_year);

        $betaRow = FdaWdd3plStaging::query()->where('fda_organization_id', $beta->id)->first();
        $this->assertNotNull($betaRow);
        $this->assertSame('Unlisted Facility LLC', $betaRow->facility_name);
        $this->assertSame(self::BETA_NAME, $betaRow->alternate_name);
        $this->assertSame('TX', $betaRow->license_state);
        $this->assertSame('75201', $betaRow->zip);
        $this->assertSame('3pl', $betaRow->facility_type);

        $this->assertNull(
            FdaWdd3plStaging::query()->where('facility_name', 'Totally Unknown Facility Co')->first()
        );

        // Re-run: truncate keeps the row count stable, no duplicate accumulation.
        $again = app(ImportFdaWdd3plStaging::class)->handle($path);
        $this->assertSame(2, $again['inserted']);
        $this->assertSame(2, FdaWdd3plStaging::query()->count());

        $this->cleanupFixtureRows();
    }

    #[Test]
    public function registry_import_upserts_facilities_with_two_letter_jurisdiction(): void
    {
        $this->cleanupFixtureRows();

        $path = base_path('tests/fixtures/fda/wdd_3pl_sample.txt');
        $counts = app(ImportFdaWddToRegistry::class)->handle($path);

        $this->assertSame(3, $counts['read']);
        $this->assertSame(3, $counts['inserted']);
        $this->assertSame(0, $counts['sent_to_review']);

        $alpha = FdaWddFacility::query()->where('facility_name', self::ALPHA_NAME)->first();
        $this->assertNotNull($alpha);
        $this->assertSame('TX', $alpha->state_province);
        $this->assertSame(FacilityType::Wdd, $alpha->facility_type);

        $alphaLicense = FdaWddLicense::query()->where('license_number', 'LIC-ALPHA-001')->first();
        $this->assertNotNull($alphaLicense);
        $this->assertSame('TX', $alphaLicense->jurisdiction);
        $this->assertTrue($alphaLicense->is_active);
        $this->assertSame($alpha->id, $alphaLicense->fda_wdd_facility_id);

        $beta = FdaWddFacility::query()->where('facility_name', 'Unlisted Facility LLC')->first();
        $this->assertNotNull($beta);
        $this->assertSame(FacilityType::ThreePl, $beta->facility_type);
        $this->assertSame('Test WDD Partner Beta', $beta->alternate_name);

        $run = FdaImportRun::query()->findOrFail($counts['import_run_id']);
        $this->assertTrue($run->isComplete());
        $this->assertSame('wdd', $run->source);
        $this->assertSame(3, $run->rows_read);

        $again = app(ImportFdaWddToRegistry::class)->handle($path);
        $this->assertSame(3, $again['updated']);
        $this->assertSame(0, $again['inserted']);
        $this->assertSame(1, FdaOrganization::query()->where('canonical_name', 'TEST WDD PARTNER ALPHA')->count());

        $this->cleanupFixtureRows();
    }

    #[Test]
    public function registry_reimport_soft_delists_missing_licenses(): void
    {
        $this->cleanupFixtureRows();

        $path = base_path('tests/fixtures/fda/wdd_3pl_sample.txt');
        app(ImportFdaWddToRegistry::class)->handle($path);

        $this->assertTrue(
            FdaWddLicense::query()->where('license_number', 'LIC-UNK-003')->first()?->is_active
        );

        $lines = file($path);
        $this->assertIsArray($lines);
        $reduced = storage_path('app/fda/wdd_reduced_'.uniqid('', true).'.txt');
        File::ensureDirectoryExists(dirname($reduced));
        File::put($reduced, implode('', array_slice($lines, 0, 3)));

        $counts = app(ImportFdaWddToRegistry::class)->handle($reduced);

        $this->assertGreaterThanOrEqual(1, $counts['licenses_delisted']);
        $this->assertFalse(
            FdaWddLicense::query()->where('license_number', 'LIC-UNK-003')->first()?->is_active
        );
        $this->assertTrue(
            FdaWddLicense::query()->where('license_number', 'LIC-ALPHA-001')->first()?->is_active
        );

        File::delete($reduced);
        $this->cleanupFixtureRows();
    }

    #[Test]
    public function command_writes_unmatched_report_when_rows_are_skipped(): void
    {
        $this->cleanupFixtureRows();

        $this->createFixturePartners();

        $path = base_path('tests/fixtures/fda/wdd_3pl_sample.txt');
        $reportPath = storage_path('app/fda/wdd_unmatched_'.now()->format('Y-m-d').'.csv');

        if (File::exists($reportPath)) {
            File::delete($reportPath);
        }

        $this->artisan('tracepharma:import-fda-wdd-3pl', ['--path' => $path])
            ->assertSuccessful()
            ->expectsOutputToContain('skipped_unmatched: 1')
            ->expectsOutputToContain('Unmatched facilities report:');

        $this->assertFileExists($reportPath);

        $csv = array_map('str_getcsv', file($reportPath));
        $this->assertSame(['facility_name', 'count'], $csv[0]);
        $this->assertContains(['Totally Unknown Facility Co', '1'], $csv);

        File::delete($reportPath);
        $this->cleanupFixtureRows();
    }

    #[Test]
    public function a_finished_import_records_a_completed_run(): void
    {
        $this->cleanupFixtureRows();

        $this->createFixturePartners();

        $path = base_path('tests/fixtures/fda/wdd_3pl_sample.txt');
        $counts = app(ImportFdaWdd3plStaging::class)->handle($path);

        $run = FdaWdd3plImportRun::query()->findOrFail($counts['import_run_id']);

        $this->assertTrue($run->isComplete());
        $this->assertSame(3, $run->rows_read);
        $this->assertSame(2, $run->row_count);
        $this->assertSame(1, $run->rows_skipped_unmatched);
        $this->assertSame(hash_file('sha256', $path), $run->sha256);
        $this->assertSame($run->id, FdaWdd3plImportRun::latestRun()?->id);

        $this->cleanupFixtureRows();
    }

    #[Test]
    public function promote_flag_is_a_noop_and_does_not_write_catalog_licenses(): void
    {
        Queue::fake();
        $this->cleanupFixtureRows();

        $this->createFixturePartners();

        $path = base_path('tests/fixtures/fda/wdd_3pl_sample.txt');

        $this->recordPreviousImportOf(100);

        $this->artisan('tracepharma:import-fda-wdd-3pl', ['--path' => $path, '--promote' => true])
            ->expectsOutputToContain('Promoting staging rows to catalog sites')
            ->assertSuccessful();

        $this->assertSame(2, FdaWdd3plStaging::query()->count());
        Queue::assertPushed(SyncTenantAtpLicensesFromFda::class);

        File::delete(storage_path('app/fda/wdd_unmatched_'.now()->format('Y-m-d').'.csv'));
        $this->cleanupFixtureRows();
    }

    private function recordPreviousImportOf(int $rowCount): void
    {
        FdaWdd3plImportRun::query()->create([
            'source_path' => 'previous.txt',
            'rows_read' => $rowCount,
            'rows_matched' => $rowCount,
            'row_count' => $rowCount,
            'started_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);
    }

    private function createFixturePartners(): void
    {
        foreach ([self::ALPHA_NAME, self::BETA_NAME] as $name) {
            $this->createFixtureOrganization($name);
        }
    }

    private function createFixtureOrganization(string $name): FdaOrganization
    {
        return FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => PartnerType::Logistics3pl,
            'is_active' => true,
        ]);
    }

    private function cleanupFixtureRows(): void
    {
        FdaWdd3plStaging::query()->truncate();
        FdaWdd3plImportRun::query()->truncate();

        FdaWddLicense::query()->whereIn('license_number', ['LIC-ALPHA-001', 'LIC-BETA-002', 'LIC-UNK-003'])->delete();
        FdaWddFacility::query()->whereIn('facility_name', [self::ALPHA_NAME, 'Unlisted Facility LLC', 'Totally Unknown Facility Co'])->delete();
        FdaImportRun::query()->where('source', 'wdd')->where(function ($query): void {
            $query->where('source_path', 'like', '%wdd_3pl_sample.txt')
                ->orWhere('source_path', 'like', '%wdd_reduced_%');
        })->delete();
        FdaOrganizationMatchReview::query()->where('source', 'wdd')->whereIn('original_name', [
            self::ALPHA_NAME,
            self::BETA_NAME,
            'Totally Unknown Facility Co',
            'Unlisted Facility LLC',
        ])->delete();
        FdaOrganization::query()->whereIn('canonical_name', [
            'TEST WDD PARTNER ALPHA',
            'TEST WDD PARTNER BETA',
            'TOTALLY UNKNOWN FACILITY',
            'UNLISTED FACILITY',
        ])->delete();
    }
}
