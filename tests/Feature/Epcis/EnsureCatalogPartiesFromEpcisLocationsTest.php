<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\EnrichEpcisDocumentShippingFields;
use App\Actions\Epcis\EnsureCatalogPartiesFromEpcisLocations;
use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\FacilityType;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\AtpLicense;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Fda\AddressFingerprint;
use App\Support\Gs1\Gtin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnsureCatalogPartiesFromEpcisLocationsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    /** @var list<string> */
    private array $cleanupGlns = [];

    /** @var list<int> */
    private array $orgIds = [];

    #[Test]
    public function pharmacy_profile_assigns_wholesaler_to_inbound_source(): void
    {
        $suffix = substr((string) str()->ulid(), -6);
        $sourceOwningGln = $this->uniqueTestGln('91');
        $destOwningGln = $this->uniqueTestGln('92');

        $this->cleanupGlns = [$sourceOwningGln, $destOwningGln];

        $locations = [
            [
                'gln' => $sourceOwningGln,
                'name' => 'Pharmacy Source Co '.$suffix,
                'country_code' => 'US',
            ],
            [
                'gln' => $destOwningGln,
                'name' => 'Pharmacy Dest Co '.$suffix,
                'country_code' => 'US',
            ],
        ];

        $partyGlns = [
            'source_owning_party_gln' => $sourceOwningGln,
            'destination_owning_party_gln' => $destOwningGln,
        ];

        $tenant = $this->initializeDemo2Tenant();
        $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
        tenancy()->initialize($tenant->fresh());

        try {
            app(EnsureCatalogPartiesFromEpcisLocations::class)->handle($locations, $partyGlns, [
                'product_manufacturer_name' => 'Different Labeler '.$suffix,
            ]);

            $sourcePartner = TradingPartner::query()->where('gln', $sourceOwningGln)->first();
            $destPartner = TradingPartner::query()->where('gln', $destOwningGln)->first();

            $this->assertNotNull($sourcePartner);
            $this->assertNotNull($destPartner);
            $this->assertSame(PartnerType::Wholesaler, $sourcePartner->partner_type);
            $this->assertSame(PartnerType::Pharmacy, $destPartner->partner_type);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function location_without_country_defaults_tenant_partner_and_site_to_us(): void
    {
        $suffix = substr((string) str()->ulid(), -6);
        $sourceOwningGln = $this->uniqueTestGln('81');
        $destOwningGln = $this->uniqueTestGln('82');
        $sourceLocationGln = $this->uniqueTestGln('83');

        $this->cleanupGlns = [$sourceOwningGln, $destOwningGln, $sourceLocationGln];

        $locations = [
            [
                'gln' => $sourceOwningGln,
                'name' => 'No Country Source '.$suffix,
            ],
            [
                'gln' => $destOwningGln,
                'name' => 'No Country Dest '.$suffix,
            ],
            [
                'gln' => $sourceLocationGln,
                'name' => 'No Country DC '.$suffix,
            ],
        ];

        $partyGlns = [
            'source_owning_party_gln' => $sourceOwningGln,
            'destination_owning_party_gln' => $destOwningGln,
            'source_location_gln' => $sourceLocationGln,
        ];

        $tenant = $this->initializeDemo2Tenant();
        $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
        tenancy()->initialize($tenant->fresh());

        try {
            app(EnsureCatalogPartiesFromEpcisLocations::class)->handle($locations, $partyGlns);

            $sourcePartner = TradingPartner::query()->where('gln', $sourceOwningGln)->first();
            $sourceSite = Site::query()->where('gln', $sourceLocationGln)->first();

            $this->assertNotNull($sourcePartner);
            $this->assertNotNull($sourceSite);
            $this->assertSame('US', $sourcePartner->country_code);
            $this->assertSame('US', $sourceSite->country_code);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function manufacturer_wdd_only_location_is_not_stamped_or_licensed(): void
    {
        $suffix = substr((string) str()->ulid(), -6);
        $sourceOwningGln = $this->uniqueTestGln('71');
        $destOwningGln = $this->uniqueTestGln('72');
        $plantGln = $this->uniqueTestGln('73');

        $this->cleanupGlns = [$sourceOwningGln, $destOwningGln, $plantGln];

        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR EPCIS Mfr '.$suffix,
            'canonical_name' => 'SSOR EPCIS MFR '.$suffix,
            'name' => 'SSOR EPCIS Mfr '.$suffix,
            'gln' => $sourceOwningGln,
            'partner_type' => PartnerType::Manufacturer,
            'is_active' => true,
        ]);
        $this->orgIds[] = $org->id;

        $facility = FdaWddFacility::query()->create([
            'fda_organization_id' => $org->id,
            'facility_type' => FacilityType::Wdd,
            'name' => 'SSOR EPCIS Mfr Plant '.$suffix,
            'facility_name' => 'SSOR EPCIS Mfr Plant '.$suffix,
            'gln' => $plantGln,
            'address_fingerprint' => AddressFingerprint::make('8 Epcis St', 'Austin', 'TX', '78701', 'US'),
            'is_active' => true,
        ]);

        FdaWddLicense::query()->create([
            'fda_wdd_facility_id' => $facility->id,
            'license_number' => 'SSOR-EPCIS-MFR-WDD',
            'jurisdiction' => 'TX',
            'expiration_date' => now()->addYear(),
            'reporting_year' => (int) now()->year,
            'is_active' => true,
        ]);

        $locations = [
            [
                'gln' => $sourceOwningGln,
                'name' => 'SSOR EPCIS Mfr '.$suffix,
                'country_code' => 'US',
            ],
            [
                'gln' => $destOwningGln,
                'name' => 'SSOR EPCIS Dest '.$suffix,
                'country_code' => 'US',
            ],
            [
                'gln' => $plantGln,
                'name' => 'SSOR EPCIS Mfr Plant '.$suffix,
                'country_code' => 'US',
            ],
        ];

        $partyGlns = [
            'source_owning_party_gln' => $sourceOwningGln,
            'destination_owning_party_gln' => $destOwningGln,
            'source_location_gln' => $plantGln,
        ];

        $tenant = $this->initializeDemo2Tenant();
        $tenant->forceFill(['profile' => TenantProfile::Pharmacy])->save();
        tenancy()->initialize($tenant->fresh());

        try {
            app(EnsureCatalogPartiesFromEpcisLocations::class)->handle($locations, $partyGlns);

            $plant = Site::query()->where('gln', $plantGln)->first();
            $this->assertNotNull($plant);
            $this->assertNull($plant->fda_wdd_facility_id);
            $this->assertSame(0, AtpLicense::query()->where('site_id', $plant->id)->count());
        } finally {
            FdaWddLicense::query()->where('fda_wdd_facility_id', $facility->id)->delete();
            FdaWddFacility::query()->whereKey($facility->id)->delete();
            $this->cleanup();
        }
    }

    #[Test]
    public function drug_wholesaler_source_not_matching_product_manufacturer_gets_wholesaler_role(): void
    {
        $suffix = substr((string) str()->ulid(), -6);
        $sourceOwningGln = $this->uniqueTestGln('93');
        $destOwningGln = $this->uniqueTestGln('94');

        $this->cleanupGlns = [$sourceOwningGln, $destOwningGln];

        $locations = [
            [
                'gln' => $sourceOwningGln,
                'name' => 'Cardinal Health '.$suffix,
                'country_code' => 'US',
            ],
            [
                'gln' => $destOwningGln,
                'name' => 'Retail Pharmacy '.$suffix,
                'country_code' => 'US',
            ],
        ];

        $partyGlns = [
            'source_owning_party_gln' => $sourceOwningGln,
            'destination_owning_party_gln' => $destOwningGln,
        ];

        $tenant = $this->initializeDemo2Tenant();
        $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
        tenancy()->initialize($tenant->fresh());

        try {
            app(EnsureCatalogPartiesFromEpcisLocations::class)->handle($locations, $partyGlns, [
                'product_manufacturer_name' => 'Xttrium Laboratories, Inc.',
            ]);

            $sourcePartner = TradingPartner::query()->where('gln', $sourceOwningGln)->first();
            $this->assertNotNull($sourcePartner);
            $this->assertSame(PartnerType::Wholesaler, $sourcePartner->partner_type);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function drug_wholesaler_source_matching_product_manufacturer_gets_manufacturer_role(): void
    {
        $suffix = substr((string) str()->ulid(), -6);
        $sourceOwningGln = $this->uniqueTestGln('95');
        $manufacturerName = 'Xttrium Laboratories, Inc.';

        $this->cleanupGlns = [$sourceOwningGln];

        $locations = [
            [
                'gln' => $sourceOwningGln,
                'name' => $manufacturerName,
                'country_code' => 'US',
            ],
        ];

        $partyGlns = [
            'source_owning_party_gln' => $sourceOwningGln,
        ];

        $tenant = $this->initializeDemo2Tenant();
        $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
        tenancy()->initialize($tenant->fresh());

        try {
            app(EnsureCatalogPartiesFromEpcisLocations::class)->handle($locations, $partyGlns, [
                'product_manufacturer_name' => $manufacturerName,
            ]);

            $sourcePartner = TradingPartner::query()->where('gln', $sourceOwningGln)->first();
            $this->assertNotNull($sourcePartner);
            $this->assertSame(PartnerType::Manufacturer, $sourcePartner->partner_type);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function fda_gln_hit_stamps_organization_and_unknown_gln_stays_tenant_local(): void
    {
        $suffix = substr((string) str()->ulid(), -6);
        $knownGln = $this->uniqueTestGln('81');
        $unknownGln = $this->uniqueTestGln('83');
        $unknownSiteGln = $this->uniqueTestGln('84');

        $this->cleanupGlns = [$knownGln, $unknownGln, $unknownSiteGln];

        $org = FdaOrganization::query()->create([
            'original_name' => 'SSOR EPCIS Known '.$suffix,
            'canonical_name' => 'SSOR EPCIS KNOWN '.$suffix,
            'name' => 'SSOR EPCIS Known '.$suffix,
            'gln' => $knownGln,
            'partner_type' => PartnerType::Wholesaler,
            'is_active' => true,
        ]);
        $this->orgIds[] = $org->id;

        $locations = [
            [
                'gln' => $knownGln,
                'name' => 'SSOR EPCIS Known '.$suffix,
                'country_code' => 'US',
            ],
            [
                'gln' => $unknownGln,
                'name' => 'SSOR EPCIS Unknown '.$suffix,
                'street_address' => '1 Dest Ave',
                'city' => 'Dublin',
                'state' => 'OH',
                'postal_code' => '43017',
                'country_code' => 'US',
            ],
            [
                'gln' => $unknownSiteGln,
                'name' => 'SSOR EPCIS Unknown Site '.$suffix,
                'street_address' => '9 Dest Rd',
                'city' => 'Groveport',
                'state' => 'OH',
                'postal_code' => '43125',
                'country_code' => 'US',
            ],
        ];

        $partyGlns = [
            'source_owning_party_gln' => $knownGln,
            'destination_owning_party_gln' => $unknownGln,
            'destination_location_gln' => $unknownSiteGln,
        ];

        $this->initializeDemo2Tenant();

        try {
            $stats = app(EnsureCatalogPartiesFromEpcisLocations::class)->handle($locations, $partyGlns);

            $this->assertGreaterThanOrEqual(2, $stats['partners_created']);
            $this->assertGreaterThanOrEqual(1, $stats['sites_created']);

            $knownPartner = TradingPartner::query()->where('gln', $knownGln)->first();
            $unknownPartner = TradingPartner::query()->where('gln', $unknownGln)->first();
            $this->assertNotNull($knownPartner);
            $this->assertNotNull($unknownPartner);
            $this->assertSame($org->id, $knownPartner->fda_organization_id);
            $this->assertNull($unknownPartner->fda_organization_id);
            $this->assertSame(PartnerType::Wholesaler, $knownPartner->partner_type);
            $this->assertSame(PartnerType::Pharmacy, $unknownPartner->partner_type);

            $unknownSite = Site::query()->where('gln', $unknownSiteGln)->first();
            $this->assertNotNull($unknownSite);
            $this->assertSame($unknownPartner->id, $unknownSite->trading_partner_id);
            $this->assertNull($unknownSite->fda_establishment_id);
            $this->assertNull($unknownSite->fda_wdd_facility_id);

            $unknownPartner->forceFill(['name' => 'LOCKED DEST NAME'])->save();

            $second = app(EnsureCatalogPartiesFromEpcisLocations::class)->handle($locations, $partyGlns);
            $this->assertSame(0, $second['partners_created']);
            $this->assertSame(0, $second['sites_created']);
            $this->assertSame('LOCKED DEST NAME', $unknownPartner->fresh()->name);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function enrich_still_resolves_fixture_shipping_names(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $fixture = base_path('tests/Fixtures/epcis/minimal_with_shipping_refs.xml');
            $tmp = tempnam(sys_get_temp_dir(), 'epcis_loc_');
            $this->assertNotFalse($tmp);
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $uuid = (string) str()->uuid();
            $xml = str_replace('22222222-3333-4444-5555-666666666666', $uuid, $xml);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_with_shipping_refs_locations.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $this->assertTrue(Schema::hasColumn('epcis_documents', 'ship_from_name'));
            $this->assertSame('Xttrium Laboratories, Inc.', $document->ship_from_name);
            $this->assertSame('Xttrium Glenview', $document->ship_from_site_name);
            $this->assertSame('Cardinal Health - Corporate', $document->ship_to_name);
            $this->assertSame('Cardinal Groveport', $document->ship_to_site_name);

            $again = app(EnrichEpcisDocumentShippingFields::class)->handle($document->fresh());
            $this->assertSame('Cardinal Health - Corporate', $again->ship_to_name);
            $this->assertSame('Cardinal Groveport', $again->ship_to_site_name);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    private function uniqueTestGln(string $prefix2): string
    {
        do {
            $body12 = $prefix2.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            $gln = $body12.Gtin::checkDigit($body12);
        } while (
            FdaOrganization::query()->where('gln', $gln)->exists()
        );

        return $gln;
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Pharmacy',
                'profile' => TenantProfile::Pharmacy,
                'status' => 'active',
                'tenancy_db_name' => self::DEMO2_DATABASE,
            ]));

            $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
        } else {
            $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
        }

        if (! self::$demo2TenantReady) {
            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        if (tenancy()->initialized) {
            if ($this->documentId !== null) {
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }

            foreach ($this->cleanupGlns as $gln) {
                Site::query()->where('gln', $gln)->delete();
                TradingPartner::query()->where('gln', $gln)->delete();
            }

            foreach ([
                'urn:epc:id:sgtin:030116.0200116.10000082001560',
                'urn:epc:id:sscc:030116.01001227052',
            ] as $uri) {
                $epc = Epc::query()->where('epc_uri', $uri)->first();
                if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                    DB::table('epc_ilmd')->where('epc_id', $epc->id)->delete();
                    $epc->delete();
                }
            }

            tenancy()->end();
        }

        if ($this->orgIds !== []) {
            FdaOrganization::query()->whereIn('id', $this->orgIds)->delete();
            $this->orgIds = [];
        }

        Tenant::query()
            ->whereKey(self::DEMO2_TENANT_ID)
            ->update(['profile' => TenantProfile::Pharmacy->value]);
    }
}
