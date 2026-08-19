<?php

namespace Tests\Feature;

use App\Actions\Catalog\ImportMckessonSoldShipTo;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Support\Catalog\Gln;
use App\Support\Fda\AddressFingerprint;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportMckessonSoldShipToTest extends TestCase
{
    private const FIXTURE = 'tests/fixtures/catalog/mckesson-sold-ship-to.tsv';

    private const CORPORATE_GLN = '0010939000002';

    private const NRDC_GLN = '0010939106001';

    private const BOSTON_GLN = '0010939110008';

    /** @var list<int> */
    private array $createdOrganizationIds = [];

    /** @var list<int> */
    private array $createdFacilityIds = [];

    /** @var list<int> */
    private array $createdEstablishmentIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanupImportState();
    }

    protected function tearDown(): void
    {
        $this->cleanupImportState();
        parent::tearDown();
    }

    private function fixturePath(): string
    {
        return base_path(self::FIXTURE);
    }

    private function cleanupImportState(): void
    {
        if ($this->createdFacilityIds !== []) {
            FdaWddFacility::query()->whereIn('id', $this->createdFacilityIds)->delete();
        }

        if ($this->createdEstablishmentIds !== []) {
            FdaEstablishment::query()->whereIn('id', $this->createdEstablishmentIds)->delete();
        }

        if ($this->createdOrganizationIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->createdOrganizationIds)->delete();
        }

        $this->createdOrganizationIds = [];
        $this->createdFacilityIds = [];
        $this->createdEstablishmentIds = [];
    }

    #[Test]
    public function dry_run_skips_unmatched_glns_and_does_not_write(): void
    {
        $orgsBefore = FdaOrganization::query()->count();

        $summary = app(ImportMckessonSoldShipTo::class)->handle($this->fixturePath(), dryRun: true);

        $this->assertTrue($summary['dry_run']);
        $this->assertSame(0, $summary['partner_created']);
        $this->assertSame(
            FdaOrganization::query()->where('gln', self::CORPORATE_GLN)->exists() ? 1 : 0,
            $summary['partner_updated']
        );
        $this->assertSame($this->matchingShipToCount(), $summary['sites_upserted']);
        $this->assertSame(
            ($summary['partner_updated'] === 1 ? 0 : 1) + (26 - $summary['sites_upserted']),
            $summary['skipped']
        );
        $this->assertSame($orgsBefore, FdaOrganization::query()->count());
    }

    #[Test]
    public function matching_fda_glns_get_blanks_filled_and_unknown_glns_are_skipped(): void
    {
        $org = $this->createBlankOrganization(self::CORPORATE_GLN);
        $facility = $this->createBlankFacility($org, self::NRDC_GLN, 'SSOR McKesson NRDC');

        $summary = app(ImportMckessonSoldShipTo::class)->handle($this->fixturePath(), dryRun: false);

        $this->assertSame(0, $summary['partner_created']);
        $this->assertSame(1, $summary['partner_updated']);
        $this->assertSame(1, $summary['sites_upserted']);
        $this->assertSame(25, $summary['skipped']);
        $this->assertSame($org->id, $summary['partner_id']);

        $org->refresh();
        $this->assertSame('McKesson', $org->name);
        $this->assertSame('McKesson Corporate', $org->doing_business_as);
        $this->assertSame(PartnerType::Wholesaler, $org->partner_type);
        $this->assertSame('6555 State Hwy 161', $org->street_address);
        $this->assertSame('Irving', $org->city);
        $this->assertSame('TX', $org->state_province);
        $this->assertSame('75039', $org->postal_code);
        $this->assertSame('serializationIT@McKesson.com', $org->email);

        $facility->refresh();
        $this->assertSame('Mckesson Nrdc', $facility->name);
        $this->assertSame('Olive Branch', $facility->city);
        $this->assertSame('MS', $facility->state_province);
        $this->assertSame('38654', $facility->postal_code);
        $this->assertNotNull($facility->address_fingerprint);
        $this->assertNotNull($facility->timezone);
    }

    #[Test]
    public function does_not_overwrite_existing_fda_values(): void
    {
        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR McKesson Keep',
            'canonical_name' => 'SSOR MCKESSON KEEP',
            'name' => 'Keep This Name',
            'gln' => self::CORPORATE_GLN,
            'partner_type' => PartnerType::Wholesaler,
            'street_address' => '1 Already Filled',
            'city' => 'Kept City',
            'state_province' => 'CA',
            'postal_code' => '90001',
            'is_active' => true,
        ]);
        $this->createdOrganizationIds[] = $org->id;

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'facility_name' => 'Keep Facility',
            'name' => 'Keep Facility',
            'gln' => self::BOSTON_GLN,
            'city' => 'Kept Boston',
            'state_province' => 'NY',
            'address_fingerprint' => AddressFingerprint::make('9 Keep St', 'Kept Boston', 'NY', '10001', 'US'),
            'is_active' => true,
        ]);
        $this->createdFacilityIds[] = $facility->id;

        app(ImportMckessonSoldShipTo::class)->handle($this->fixturePath(), dryRun: false);

        $org->refresh();
        $this->assertSame('Keep This Name', $org->name);
        $this->assertSame('1 Already Filled', $org->street_address);
        $this->assertSame('Kept City', $org->city);
        $this->assertSame('CA', $org->state_province);

        $facility->refresh();
        $this->assertSame('Keep Facility', $facility->name);
        $this->assertSame('Kept Boston', $facility->city);
        $this->assertSame('NY', $facility->state_province);
    }

    #[Test]
    public function second_import_is_idempotent_and_creates_zero_catalog_rows(): void
    {
        $org = $this->createBlankOrganization(self::CORPORATE_GLN);
        $this->createBlankFacility($org, self::NRDC_GLN, 'SSOR McKesson NRDC Idem');

        $action = app(ImportMckessonSoldShipTo::class);
        $first = $action->handle($this->fixturePath(), dryRun: false);

        $second = $action->handle($this->fixturePath(), dryRun: false);

        $this->assertSame(0, $second['partner_created']);
        $this->assertSame(1, $second['partner_updated']);
        $this->assertSame(1, $second['sites_upserted']);
        $this->assertSame($first['partner_id'], $second['partner_id']);
    }

    #[Test]
    public function matching_establishment_gln_is_filled_without_creating_a_facility(): void
    {
        $org = $this->createBlankOrganization(self::CORPORATE_GLN);
        $establishment = FdaEstablishment::query()->create([
            'fda_organization_id' => $org->id,
            'firm_name' => 'SSOR McKesson Boston Est',
            'name' => null,
            'gln' => self::BOSTON_GLN,
            'address_fingerprint' => AddressFingerprint::make('9 Aegean Dr', 'Methuen', 'MA', '01844', 'US'),
            'is_active' => true,
        ]);
        $this->createdEstablishmentIds[] = $establishment->id;

        $facilitiesBefore = FdaWddFacility::query()->count();

        $summary = app(ImportMckessonSoldShipTo::class)->handle($this->fixturePath(), dryRun: false);

        $this->assertSame(1, $summary['sites_upserted']);
        $this->assertSame($facilitiesBefore, FdaWddFacility::query()->count());

        $establishment->refresh();
        $this->assertSame('Mckesson Boston', $establishment->name);
        $this->assertSame('Methuen', $establishment->city);
        $this->assertSame('MA', $establishment->state_province);
        $this->assertSame('018441596', $establishment->postal_code);
    }

    private function matchingShipToCount(): int
    {
        $handle = fopen($this->fixturePath(), 'r');
        $this->assertNotFalse($handle);
        $header = array_map(static fn ($column) => trim((string) $column), fgetcsv($handle, 0, "\t") ?: []);
        $matched = 0;

        while (($data = fgetcsv($handle, 0, "\t")) !== false) {
            $row = [];
            foreach ($header as $index => $column) {
                $row[$column] = trim((string) ($data[$index] ?? ''));
            }

            if (strtolower($row['Type'] ?? '') !== 'ship to') {
                continue;
            }

            $gln = Gln::normalize($row['Ship To GLN'] ?? null);

            if ($gln === null) {
                continue;
            }

            if (FdaWddFacility::query()->where('gln', $gln)->exists()
                || FdaEstablishment::query()->where('gln', $gln)->exists()) {
                $matched++;
            }
        }

        fclose($handle);

        return $matched;
    }

    private function createBlankOrganization(string $gln): FdaOrganization
    {
        $existing = FdaOrganization::query()->where('gln', $gln)->first();
        $this->assertNull($existing, "FDA organization already occupies fixture GLN {$gln}.");

        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR McKesson Import',
            'canonical_name' => 'SSOR MCKESSON IMPORT',
            'name' => null,
            'gln' => $gln,
            'partner_type' => PartnerType::Wholesaler,
            'is_active' => true,
        ]);
        $this->createdOrganizationIds[] = $org->id;

        return $org;
    }

    private function createBlankFacility(FdaOrganization $org, string $gln, string $label): FdaWddFacility
    {
        $existing = FdaWddFacility::query()->where('gln', $gln)->first()
            ?? FdaEstablishment::query()->where('gln', $gln)->first();
        $this->assertNull($existing, "FDA site already occupies fixture GLN {$gln}.");

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'facility_name' => $label,
            'name' => null,
            'gln' => $gln,
            'address_fingerprint' => AddressFingerprint::make($label, 'Olive Branch', 'MS', '38654', 'US'),
            'is_active' => true,
        ]);
        $this->createdFacilityIds[] = $facility->id;

        return $facility;
    }
}
