<?php

namespace Tests\Feature\Fda;

use App\Actions\Fda\ImportFdaWdd3plStaging;
use App\Actions\Fda\ImportFdaWddToRegistry;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Models\Fda\FdaImportRun;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Models\Fda\FdaWdd3plImportRun;
use App\Models\Fda\FdaWdd3plStaging;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Fda\FdaOrganizationSlugIndex;
use App\Support\PartnerSlug;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The staging import decides which FDA licenses a tenant can ever see, so what it
 * skips matters as much as what it matches: a facility an admin already triaged
 * must come in on the next run, and a Top-6 wholesaler must not be skipped
 * because the report names the licensed branch entity.
 */
class ImportFdaWdd3plMatchingTest extends TestCase
{
    private const ALPHA_NAME = 'Test WDD Partner Alpha';

    private const BETA_NAME = 'Test WDD Partner Beta';

    private const UNKNOWN_NAME = 'Totally Unknown Facility Co';

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cleanupFixtureRows();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtureRows();

        foreach ($this->tempFiles as $path) {
            File::delete($path);
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    #[Test]
    public function contact_phone_lands_on_staging_and_the_registry_facility(): void
    {
        $alpha = $this->organization(self::ALPHA_NAME);

        $path = base_path('tests/fixtures/fda/wdd_3pl_sample.txt');

        app(ImportFdaWdd3plStaging::class)->handle($path);

        $stagedAlpha = FdaWdd3plStaging::query()
            ->where('fda_organization_id', $alpha->id)
            ->first();

        $this->assertNotNull($stagedAlpha);
        $this->assertSame('512-555-0100', $stagedAlpha->contact_phone);
        $this->assertSame('Alpha Contact', $stagedAlpha->contact_person);
        $this->assertSame('alpha@example.test', $stagedAlpha->contact_email);

        app(ImportFdaWddToRegistry::class)->handle($path);

        $facility = FdaWddFacility::query()->where('facility_name', self::ALPHA_NAME)->first();

        $this->assertNotNull($facility);
        $this->assertSame('512-555-0100', $facility->contact_phone);
    }

    #[Test]
    public function skipped_rows_record_the_fda_type_for_triage(): void
    {
        app(ImportFdaWdd3plStaging::class)->handle(
            base_path('tests/fixtures/fda/wdd_3pl_sample.txt'),
        );

        $unmatched = FdaWdd3plUnmatched::query()
            ->where('facility_name', self::UNKNOWN_NAME)
            ->first();

        $this->assertNotNull($unmatched);
        $this->assertSame(FacilityType::Wdd, $unmatched->facility_type);
    }

    #[Test]
    public function a_resolved_unmatched_facility_matches_on_the_next_import(): void
    {
        $path = base_path('tests/fixtures/fda/wdd_3pl_sample.txt');

        app(ImportFdaWdd3plStaging::class)->handle($path);

        $unmatched = FdaWdd3plUnmatched::query()->where('facility_name', self::UNKNOWN_NAME)->first();
        $this->assertNotNull($unmatched);
        $this->assertSame(
            0,
            FdaWdd3plStaging::query()->where('facility_name', self::UNKNOWN_NAME)->count(),
            'The facility is unknown on the first pass.',
        );

        // Admin triage: link the facility to an organization whose name it does not carry.
        $organization = $this->organization('Resolved Triage Wholesaler');
        $unmatched->forceFill([
            'fda_organization_id' => $organization->id,
            'resolved_at' => now(),
        ])->save();

        $counts = app(ImportFdaWdd3plStaging::class)->handle($path);

        $staged = FdaWdd3plStaging::query()->where('facility_name', self::UNKNOWN_NAME)->first();

        $this->assertNotNull($staged, 'A resolved facility must be staged without re-triage.');
        $this->assertSame($organization->id, $staged->fda_organization_id);
        $this->assertArrayNotHasKey(self::UNKNOWN_NAME, $counts['unmatched_facilities']);
    }

    #[Test]
    public function major_wholesaler_entity_names_roll_up_to_the_top_six_organization(): void
    {
        $mckesson = $this->topSixOrganization('McKesson');
        $cardinal = $this->topSixOrganization('Cardinal Health');
        $cencora = $this->topSixOrganization('Cencora');

        $path = $this->writeReport([
            ['WDD', 'McKesson Corporation (Anchorage)', '', 'LIC-MCK-ALIAS'],
            ['WDD', 'Cardinal Health 110, LLC', '', 'LIC-CAH-ALIAS'],
            ['3PL', 'AmerisourceBergen Drug Corporation', '', 'LIC-ABC-ALIAS'],
            ['WDD', 'Unrelated Regional Distributor', '', 'LIC-UNR-ALIAS'],
        ]);

        $counts = app(ImportFdaWdd3plStaging::class)->handle($path);

        $this->assertSame(3, $counts['matched']);
        $this->assertSame(
            $mckesson->id,
            FdaWdd3plStaging::query()->where('license_number', 'LIC-MCK-ALIAS')->value('fda_organization_id'),
        );
        $this->assertSame(
            $cardinal->id,
            FdaWdd3plStaging::query()->where('license_number', 'LIC-CAH-ALIAS')->value('fda_organization_id'),
        );
        $this->assertSame(
            $cencora->id,
            FdaWdd3plStaging::query()->where('license_number', 'LIC-ABC-ALIAS')->value('fda_organization_id'),
            'AmerisourceBergen licenses belong to Cencora.',
        );
        $this->assertSame(
            ['Unrelated Regional Distributor' => 1],
            $counts['unmatched_facilities'],
            'The alias map must not swallow companies outside the Top 6.',
        );
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $rows
     */
    private function writeReport(array $rows): string
    {
        $header = [
            'Type', 'Facility_Name', 'Doing_Business_As', 'Facility_Street', 'Facility_City',
            'Facility_State', 'Facility_Zip', 'License_Number', 'License_State',
            'License_Expiration_Date', 'Facility_Contact_Name', 'Facility_Contact_Phone',
            'Facility_Contact_Email', 'Reporting_Year',
        ];

        $lines = [implode("\t", $header)];

        foreach ($rows as [$type, $name, $dba, $licenseNumber]) {
            $lines[] = implode("\t", [
                $type, $name, $dba, '1 Alias Way', 'Austin', 'US-TX', '78701',
                $licenseNumber, 'US-TX', '12/31/2027', 'Alias Contact', '512-555-0999',
                'alias@example.test', '2026',
            ]);
        }

        $path = storage_path('app/fda/wdd_alias_'.uniqid('', true).'.txt');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, implode("\n", $lines)."\n");

        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * The Top 6 are seeded in some environments, and the slug index resolves to the
     * lowest id, so reuse the organization already carrying the slug.
     */
    private function topSixOrganization(string $name): FdaOrganization
    {
        $existingId = FdaOrganizationSlugIndex::map()[PartnerSlug::from($name)] ?? null;

        return $existingId === null
            ? $this->organization($name)
            : FdaOrganization::query()->findOrFail($existingId);
    }

    private function organization(string $name): FdaOrganization
    {
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

    private function cleanupFixtureRows(): void
    {
        FdaWdd3plStaging::query()->truncate();
        FdaWdd3plImportRun::query()->truncate();

        FdaWdd3plUnmatched::query()->whereIn('facility_name', [
            self::UNKNOWN_NAME,
            'McKesson Corporation (Anchorage)',
            'Cardinal Health 110, LLC',
            'AmerisourceBergen Drug Corporation',
            'Unrelated Regional Distributor',
        ])->delete();

        FdaWddLicense::query()
            ->whereIn('license_number', ['LIC-ALPHA-001', 'LIC-BETA-002', 'LIC-UNK-003'])
            ->delete();
        FdaWddFacility::query()->whereIn('facility_name', [
            self::ALPHA_NAME,
            'Unlisted Facility LLC',
            self::UNKNOWN_NAME,
        ])->delete();
        FdaImportRun::query()->where('source', 'wdd')->where(function ($query): void {
            $query->where('source_path', 'like', '%wdd_3pl_sample.txt')
                ->orWhere('source_path', 'like', '%wdd_alias_%');
        })->delete();
        FdaOrganizationMatchReview::query()->where('source', 'wdd')->whereIn('original_name', [
            self::ALPHA_NAME,
            self::BETA_NAME,
            self::UNKNOWN_NAME,
            'Unlisted Facility LLC',
        ])->delete();

        if ($this->orgIds !== []) {
            FdaWddLicense::query()->whereIn('fda_wdd_facility_id', function ($query): void {
                $query->select('id')->from('fda_wdd_facilities')->whereIn('fda_organization_id', $this->orgIds);
            })->delete();
            FdaWddFacility::query()->whereIn('fda_organization_id', $this->orgIds)->delete();
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
            $this->orgIds = [];
        }

        FdaOrganization::query()->whereIn('canonical_name', [
            'TEST WDD PARTNER ALPHA',
            'TEST WDD PARTNER BETA',
            'TOTALLY UNKNOWN FACILITY',
            'UNLISTED FACILITY',
        ])->delete();
    }
}
