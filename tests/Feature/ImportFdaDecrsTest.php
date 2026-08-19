<?php

namespace Tests\Feature;

use App\Actions\Fda\ImportFdaDecrs;
use App\Actions\Fda\MapFdaRegistryToCatalog;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaEstablishmentOperation;
use App\Models\Fda\FdaImportRun;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaOrganizationMatchReview;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Support\Fda\AddressFingerprint;
use App\Support\Fda\FdaDecrsDataset;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportFdaDecrsTest extends TestCase
{
    #[Test]
    public function fixture_import_upserts_orgs_establishments_and_skips_blank_fei(): void
    {
        $this->cleanup();

        $path = app(FdaDecrsDataset::class)->resolvePath(
            base_path('tests/fixtures/fda/decrs_sample.txt'),
            false
        );

        $counts = app(ImportFdaDecrs::class)->handle($path);

        $this->assertSame(3, $counts['read']);
        $this->assertSame(2, $counts['inserted']);
        $this->assertSame(1, $counts['skipped']);
        $this->assertSame(0, $counts['sent_to_review']);

        $acme = FdaOrganization::query()->where('canonical_name', 'ACME PHARMA')->first();
        $this->assertNotNull($acme);
        $this->assertSame('123456789', $acme->duns_number);

        $plant = FdaEstablishment::query()->where('fei_number', '0000001001')->first();
        $this->assertNotNull($plant);
        $this->assertSame($acme->id, $plant->fda_organization_id);
        $this->assertSame('Acme Fill Plant', $plant->firm_name);
        $this->assertSame('TX', $plant->state_province);
        $this->assertSame('78701', $plant->postal_code);
        $this->assertFalse($plant->exclusion_flag);
        $this->assertTrue($plant->is_currently_registered);
        $this->assertSame(
            AddressFingerprint::fromWdd('100 Alpha Way', 'Austin', 'TX', '78701'),
            $plant->address_fingerprint
        );
        $this->assertEqualsCanonicalizing(
            ['MANUFACTURE', 'PACK'],
            $plant->operations()->pluck('operation_code')->all()
        );

        $excluded = FdaEstablishment::query()->where('fei_number', '0000001003')->first();
        $this->assertNotNull($excluded);
        $this->assertTrue($excluded->exclusion_flag);
        $this->assertFalse($excluded->is_currently_registered);

        $this->assertNull(FdaEstablishment::query()->where('firm_name', 'No Fei Plant')->first());

        $run = FdaImportRun::query()->findOrFail($counts['import_run_id']);
        $this->assertTrue($run->isComplete());
        $this->assertSame('decrs', $run->source);
        $this->assertSame(3, $run->rows_read);

        $again = app(ImportFdaDecrs::class)->handle($path);
        $this->assertSame(2, $again['updated']);
        $this->assertSame(0, $again['inserted']);
        $this->assertSame(1, FdaOrganization::query()->where('canonical_name', 'ACME PHARMA')->count());

        $this->cleanup();
    }

    #[Test]
    public function map_fda_registry_to_catalog_is_a_noop_and_leaves_fda_rows_intact(): void
    {
        $this->cleanup();

        app(ImportFdaDecrs::class)->handle(base_path('tests/fixtures/fda/decrs_sample.txt'));

        $org = FdaOrganization::query()->where('canonical_name', 'ACME PHARMA')->firstOrFail();
        $establishment = FdaEstablishment::query()->where('fei_number', '0000001001')->firstOrFail();

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => 'wdd',
            'facility_name' => 'Acme Fill Plant',
            'street_address' => '100 Alpha Way',
            'city' => 'Austin',
            'state_province' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'address_fingerprint' => $establishment->address_fingerprint,
        ]);

        FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'LIC-ACME-TX',
            'jurisdiction' => 'TX',
            'expiration_date' => '2027-12-31',
            'reporting_year' => 2026,
            'is_active' => true,
        ]);

        $counts = app(MapFdaRegistryToCatalog::class)->handle([(int) $org->id]);

        $this->assertSame(0, $counts['partners_created']);
        $this->assertSame(0, $counts['partners_linked']);
        $this->assertSame(0, $counts['sites_created']);
        $this->assertSame(0, $counts['sites_linked']);
        $this->assertSame(0, $counts['licenses_upserted']);
        $this->assertSame(0, $counts['import_run_id']);

        $this->assertSame($org->id, $establishment->fresh()->fda_organization_id);
        $this->assertSame('0000001001', $establishment->fresh()->fei_number);
        $this->assertSame(1, FdaWddLicense::query()->where('license_number', 'LIC-ACME-TX')->count());

        $this->cleanup();
    }

    #[Test]
    public function recalc_command_refreshes_stale_registration_flags(): void
    {
        $this->cleanup();

        app(ImportFdaDecrs::class)->handle(base_path('tests/fixtures/fda/decrs_sample.txt'));

        $plant = FdaEstablishment::query()->where('fei_number', '0000001001')->firstOrFail();
        $plant->fillFromFda([
            'expiration_date' => now()->subDay()->toDateString(),
            'exclusion_flag' => false,
            'is_currently_registered' => true,
        ]);

        $this->artisan('tracepharma:recalc-fda-establishment-registration')
            ->assertSuccessful()
            ->expectsOutputToContain('Updated');

        $plant->refresh();
        $this->assertFalse($plant->is_currently_registered);

        $this->cleanup();
    }

    #[Test]
    public function similar_registrant_names_with_different_duns_create_separate_orgs(): void
    {
        $this->cleanup();
        $this->cleanupSiblings();

        $path = base_path('tests/fixtures/fda/decrs_sibling_registrants.txt');
        $counts = app(ImportFdaDecrs::class)->handle($path);

        $this->assertSame(2, $counts['read']);
        $this->assertSame(2, $counts['inserted']);
        $this->assertSame(0, $counts['sent_to_review']);

        $us = FdaOrganization::query()->where('duns_number', '111111111')->first();
        $at = FdaOrganization::query()->where('duns_number', '222222222')->first();
        $this->assertNotNull($us);
        $this->assertNotNull($at);
        $this->assertNotSame($us->id, $at->id);

        $siteA = FdaEstablishment::query()->where('fei_number', '0000002001')->firstOrFail();
        $siteB = FdaEstablishment::query()->where('fei_number', '0000002002')->firstOrFail();
        $this->assertSame($us->id, $siteA->fda_organization_id);
        $this->assertSame($at->id, $siteB->fda_organization_id);

        $this->cleanupSiblings();
        $this->cleanup();
    }

    private function cleanup(): void
    {
        $feiNumbers = ['0000001001', '0000001003'];
        $estIds = FdaEstablishment::query()->whereIn('fei_number', $feiNumbers)->pluck('id');
        $orgIds = FdaOrganization::query()->whereIn('canonical_name', ['ACME PHARMA', 'OTHER LABS'])->pluck('id');

        if ($estIds->isNotEmpty()) {
            FdaEstablishmentOperation::query()->whereIn('fda_establishment_id', $estIds)->delete();
        }

        FdaWddLicense::query()->where('license_number', 'LIC-ACME-TX')->delete();
        FdaWddFacility::query()->where('facility_name', 'Acme Fill Plant')->delete();
        FdaEstablishment::query()->whereIn('fei_number', $feiNumbers)->delete();
        FdaOrganizationMatchReview::query()->whereIn('source', ['decrs', 'wdd'])->delete();
        FdaImportRun::query()->whereIn('source', ['decrs', 'wdd', 'catalog_map'])->delete();

        if ($orgIds->isNotEmpty()) {
            FdaOrganization::query()->whereIn('id', $orgIds)->delete();
        }
    }

    private function cleanupSiblings(): void
    {
        $feiNumbers = ['0000002001', '0000002002'];
        $estIds = FdaEstablishment::query()->whereIn('fei_number', $feiNumbers)->pluck('id');

        if ($estIds->isNotEmpty()) {
            FdaEstablishmentOperation::query()->whereIn('fda_establishment_id', $estIds)->delete();
        }

        FdaEstablishment::query()->whereIn('fei_number', $feiNumbers)->delete();
        FdaOrganization::query()->whereIn('duns_number', ['111111111', '222222222'])->delete();
    }
}
