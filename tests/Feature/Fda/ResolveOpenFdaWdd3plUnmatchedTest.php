<?php

namespace Tests\Feature\Fda;

use App\Actions\Fda\ImportFdaWdd3plStaging;
use App\Actions\Fda\ResolveOpenFdaWdd3plUnmatched;
use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\Fda\WddOrganizationName;
use App\Support\PartnerSlug;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveOpenFdaWdd3plUnmatchedTest extends TestCase
{
    private const PREFIX = 'OPENWDD3PL';

    /** @var list<int> */
    private array $orgIds = [];

    /** @var list<int> */
    private array $unmatchedIds = [];

    protected function tearDown(): void
    {
        if ($this->unmatchedIds !== []) {
            FdaWdd3plUnmatched::query()->whereIn('id', $this->unmatchedIds)->delete();
        }

        FdaWdd3plUnmatched::query()
            ->where('facility_name', 'like', self::PREFIX.'%')
            ->orWhere('facility_name', 'like', 'Owens & Minor Distribution '.self::PREFIX.'%')
            ->delete();

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        FdaOrganization::query()
            ->where('canonical_name', 'like', self::PREFIX.'%')
            ->orWhere('canonical_name', 'like', 'OWENS MINOR DISTRIBUTION '.self::PREFIX.'%')
            ->delete();

        parent::tearDown();
    }

    #[Test]
    public function rolls_dc_siblings_up_to_existing_parent_org(): void
    {
        $parent = self::PREFIX.' Owens Rollup Distribution';
        $org = FdaOrganization::query()->create([
            'original_name' => $parent,
            'canonical_name' => CompanyNameNormalizer::canonical($parent),
            'name' => $parent,
            'partner_type' => PartnerType::Logistics3pl,
            'is_active' => true,
        ]);
        $this->orgIds[] = (int) $org->id;

        $dcA = $parent.' - Bangor DC 96';
        $dcB = $parent.' - Chicago DC 98';

        foreach ([$dcA, $dcB] as $name) {
            $row = FdaWdd3plUnmatched::query()->create([
                'facility_name' => $name,
                'slug_attempt' => PartnerSlug::from($name),
                'row_count' => 3,
                'last_seen_at' => now(),
            ]);
            $this->unmatchedIds[] = (int) $row->id;
        }

        $this->assertSame(
            $parent,
            WddOrganizationName::fromFacilityName($dcA),
        );

        $fixture = $this->writeTypeFixture([
            ['3PL', $dcA],
            ['3PL', $dcB],
        ]);

        try {
            $result = app(ResolveOpenFdaWdd3plUnmatched::class)->handle($fixture, false);
        } finally {
            @unlink($fixture);
        }

        $this->assertSame(2, $result['scanned']);
        $this->assertSame(1, $result['parents']);
        $this->assertSame(1, $result['linked']);
        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['rows_resolved']);

        foreach ([$dcA, $dcB] as $name) {
            $row = FdaWdd3plUnmatched::query()->where('facility_name', $name)->firstOrFail();
            $this->assertNotNull($row->resolved_at);
            $this->assertSame($org->id, $row->fda_organization_id);
        }
    }

    #[Test]
    public function creates_one_org_per_novel_parent(): void
    {
        $name = self::PREFIX.' Novel Wholesaler LLC';
        $row = FdaWdd3plUnmatched::query()->create([
            'facility_name' => $name,
            'slug_attempt' => PartnerSlug::from($name),
            'row_count' => 5,
            'last_seen_at' => now(),
        ]);
        $this->unmatchedIds[] = (int) $row->id;

        $fixture = $this->writeTypeFixture([
            ['WDD', $name],
        ]);

        try {
            $result = app(ResolveOpenFdaWdd3plUnmatched::class)->handle($fixture, false);
        } finally {
            @unlink($fixture);
        }

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['rows_resolved']);

        $row->refresh();
        $this->assertNotNull($row->resolved_at);
        $this->assertNotNull($row->fda_organization_id);

        $org = FdaOrganization::query()->findOrFail($row->fda_organization_id);
        $this->orgIds[] = (int) $org->id;
        $this->assertSame(PartnerType::Wholesaler, $org->partner_type);
        $this->assertSame(CompanyNameNormalizer::canonical($name), $org->canonical_name);
    }

    #[Test]
    public function staging_unmatched_label_uses_parent_name(): void
    {
        $parent = self::PREFIX.' Staging Parent Distribution';
        $dc = $parent.' - Tulsa DC 16';

        $fixture = base_path('tests/fixtures/fda/wdd_3pl_dc_rollup_staging.txt');
        file_put_contents(
            $fixture,
            "Type\tFacility_Name\tDoing_Business_As\tFacility_Street\tFacility_City\tFacility_State\tFacility_Zip\tLicense_Number\tLicense_State\tLicense_Expiration_Date\tFacility_Contact_Name\tFacility_Contact_Phone\tFacility_Contact_Email\tReporting_Year\n"
            ."WDD\t{$dc}\t\t1 Main\tAustin\tUS-TX\t78701\tLIC-DC-1\tUS-TX\t12/31/2027\t\t\t\t2026\n"
            ."WDD\t{$parent} - Sioux Falls DC06\t\t2 Main\tAustin\tUS-TX\t78701\tLIC-DC-2\tUS-TX\t12/31/2027\t\t\t\t2026\n"
        );

        try {
            app(ImportFdaWdd3plStaging::class)->handle($fixture);
        } finally {
            @unlink($fixture);
        }

        $unmatched = FdaWdd3plUnmatched::query()->where('facility_name', $parent)->first();
        $this->assertNotNull($unmatched);
        $this->unmatchedIds[] = (int) $unmatched->id;
        $this->assertSame(2, $unmatched->row_count);
        $this->assertNull(
            FdaWdd3plUnmatched::query()->where('facility_name', $dc)->first()
        );
    }

    /**
     * @param  list<array{0: string, 1: string}>  $rows
     */
    private function writeTypeFixture(array $rows): string
    {
        $path = base_path('tests/fixtures/fda/wdd_3pl_open_resolve_'.uniqid('', true).'.txt');
        $lines = [
            "Type\tFacility_Name\tDoing_Business_As\tFacility_Street\tFacility_City\tFacility_State\tLicense_Number\tLicense_State\tLicense_Expiration_Date\tFacility_Contact_Name\tFacility_Contact_Phone\tFacility_Contact_Email\tReporting_Year",
        ];

        foreach ($rows as [$type, $name]) {
            $lines[] = implode("\t", [
                $type, $name, '', '1 Main', 'Austin', 'US-TX', 'LIC1', 'US-TX', '12/31/2027', '', '', '', '2026',
            ]);
        }

        file_put_contents($path, implode("\n", $lines)."\n");

        return $path;
    }
}
