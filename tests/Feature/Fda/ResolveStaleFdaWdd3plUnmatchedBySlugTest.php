<?php

namespace Tests\Feature\Fda;

use App\Actions\Fda\ResolveStaleFdaWdd3plUnmatchedBySlug;
use App\Enums\PartnerType;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWdd3plUnmatched;
use App\Support\Fda\CompanyNameNormalizer;
use App\Support\PartnerSlug;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveStaleFdaWdd3plUnmatchedBySlugTest extends TestCase
{
    private const PREFIX = 'STALEWDD3PL';

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
            ->delete();

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
        }

        FdaOrganization::query()
            ->where('canonical_name', 'like', self::PREFIX.'%')
            ->delete();

        parent::tearDown();
    }

    #[Test]
    public function links_open_unmatched_when_slug_matches_existing_org(): void
    {
        $name = self::PREFIX.' Supply Chain Solutions Inc';
        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => null,
            'duns_number' => null,
        ]);
        $this->orgIds[] = (int) $org->id;

        $unmatched = FdaWdd3plUnmatched::query()->create([
            'facility_name' => $name,
            'slug_attempt' => PartnerSlug::from($name),
            'row_count' => 12,
            'last_seen_at' => now()->subDay(),
        ]);
        $this->unmatchedIds[] = (int) $unmatched->id;

        $fixture = base_path('tests/fixtures/fda/wdd_3pl_stale_resolve.txt');
        file_put_contents($fixture, implode("\t", [
            'Type', 'Facility_Name', 'Doing_Business_As', 'Facility_Street', 'Facility_City',
            'Facility_State', 'License_Number', 'License_State', 'License_Expiration_Date',
            'Facility_Contact_Name', 'Facility_Contact_Phone', 'Facility_Contact_Email', 'Reporting_Year',
        ])."\n".implode("\t", [
            'WDD', $name, '', '1 Main', 'Austin', 'US-TX', 'LIC1', 'US-TX', '12/31/2027',
            '', '', '', '2026',
        ])."\n");

        try {
            $result = app(ResolveStaleFdaWdd3plUnmatchedBySlug::class)->handle($fixture, false);
        } finally {
            @unlink($fixture);
        }

        $this->assertSame(1, $result['linked']);
        $this->assertSame(1, $result['partner_type_filled']);

        $unmatched->refresh();
        $this->assertNotNull($unmatched->resolved_at);
        $this->assertSame($org->id, $unmatched->fda_organization_id);

        $org->refresh();
        $this->assertSame(PartnerType::Wholesaler, $org->partner_type);
    }

    #[Test]
    public function dry_run_does_not_link(): void
    {
        $name = self::PREFIX.' Dry Run Logistics LLC';
        $org = FdaOrganization::query()->create([
            'original_name' => $name,
            'canonical_name' => CompanyNameNormalizer::canonical($name),
            'name' => $name,
            'partner_type' => PartnerType::Wholesaler,
            'duns_number' => null,
        ]);
        $this->orgIds[] = (int) $org->id;

        $unmatched = FdaWdd3plUnmatched::query()->create([
            'facility_name' => $name,
            'slug_attempt' => PartnerSlug::from($name),
            'row_count' => 2,
            'last_seen_at' => now(),
        ]);
        $this->unmatchedIds[] = (int) $unmatched->id;

        $fixture = base_path('tests/fixtures/fda/wdd_3pl_stale_resolve_dry.txt');
        file_put_contents($fixture, "Type\tFacility_Name\tDoing_Business_As\tFacility_Street\tFacility_City\tFacility_State\tLicense_Number\tLicense_State\tLicense_Expiration_Date\tFacility_Contact_Name\tFacility_Contact_Phone\tFacility_Contact_Email\tReporting_Year\n"
            ."3PL\t{$name}\t\t1 Main\tAustin\tUS-TX\tLIC2\tUS-TX\t12/31/2027\t\t\t\t2026\n");

        try {
            $result = app(ResolveStaleFdaWdd3plUnmatchedBySlug::class)->handle($fixture, true);
        } finally {
            @unlink($fixture);
        }

        $this->assertSame(1, $result['linked']);
        $this->assertSame(0, $result['partner_type_filled']);

        $unmatched->refresh();
        $this->assertNull($unmatched->resolved_at);
        $this->assertNull($unmatched->fda_organization_id);
    }
}
