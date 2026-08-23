<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Epcis\RecordPublishedSglnOnPartner;
use App\Domain\Gs1\CheckDigit;
use App\Enums\PartnerType;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TradingPartner;
use App\Support\Gs1\Sgln;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecordPublishedSglnOnPartnerTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $siteIds = [];

    /** @var list<int> */
    private array $partnerIds = [];

    private ?int $documentId = null;

    #[Test]
    public function records_published_urn_on_blank_partner_site_and_partner(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$gln, $urn] = $this->uniqueGlnAndSgln();
            [$partner, $site] = $this->createPartnerSite($gln);

            app(RecordPublishedSglnOnPartner::class)->handle($gln, $urn);

            $this->assertSame($urn, $site->fresh()->sgln);
            $this->assertSame($urn, $partner->fresh()->sgln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function does_not_overwrite_a_conflicting_valid_sgln(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$gln, $urn] = $this->uniqueGlnAndSgln();
            $parsed = Sgln::fromUrn($urn);
            $this->assertNotNull($parsed);
            $kept = 'urn:epc:id:sgln:'.$parsed['company_prefix'].'.'.$parsed['location_reference'].'.9';
            [$partner, $site] = $this->createPartnerSite($gln, $kept);

            app(RecordPublishedSglnOnPartner::class)->handle($gln, $urn);

            $this->assertSame($kept, $site->fresh()->sgln);
            $this->assertSame($kept, $partner->fresh()->sgln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function does_not_write_an_organization_facility(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$gln, $urn] = $this->uniqueGlnAndSgln();
            $site = Site::query()->create([
                'name' => 'Org dock '.uniqid(),
                'gln' => $gln,
                'trading_partner_id' => null,
                'is_organization_facility' => true,
                'is_active' => true,
                'country_code' => 'US',
            ]);
            $this->siteIds[] = (int) $site->getKey();
            $before = $site->fresh()->sgln;

            app(RecordPublishedSglnOnPartner::class)->handle($gln, $urn);

            $this->assertSame($before, $site->fresh()->sgln);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function ingest_copies_source_owning_sgln_onto_matching_partner_site(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$gln, $urn] = $this->uniqueGlnAndSgln();
            [, $site] = $this->createPartnerSite($gln);
            $this->assertNull($site->fresh()->sgln);

            $document = $this->ingestShippingRefsWithSource($gln, $urn);
            $this->documentId = (int) $document->getKey();

            $this->assertSame($urn, $site->fresh()->sgln);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function uniqueGlnAndSgln(): array
    {
        do {
            $prefix = '03'.str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $location = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $body12 = $prefix.$location;
            $gln = $body12.CheckDigit::mod10($body12);
            $urn = 'urn:epc:id:sgln:'.$prefix.'.'.$location.'.0';
        } while (
            Site::query()->where('gln', $gln)->exists()
            || TradingPartner::query()->where('gln', $gln)->exists()
        );

        $this->assertSame($gln, Sgln::fromUrn($urn)['gln'] ?? null);

        return [$gln, $urn];
    }

    /**
     * @return array{0: TradingPartner, 1: Site}
     */
    private function createPartnerSite(string $gln, ?string $sgln = null): array
    {
        $partner = TradingPartner::query()->create([
            'name' => 'Published SGLN partner '.uniqid(),
            'gln' => $gln,
            'partner_type' => PartnerType::Manufacturer,
            'country_code' => 'US',
            'is_active' => true,
            'sgln' => $sgln,
        ]);
        $this->partnerIds[] = (int) $partner->getKey();

        $site = Site::query()->create([
            'name' => 'Published SGLN dock '.uniqid(),
            'gln' => $gln,
            'trading_partner_id' => $partner->getKey(),
            'is_organization_facility' => false,
            'is_active' => true,
            'country_code' => 'US',
            'sgln' => $sgln,
        ]);
        $this->siteIds[] = (int) $site->getKey();

        return [$partner->fresh(), $site->fresh()];
    }

    private function ingestShippingRefsWithSource(string $gln, string $urn): EpcisDocument
    {
        do {
            $ssccUri = 'urn:epc:id:sscc:030116.0'.str_pad((string) random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
        } while (Epc::query()->where('epc_uri', $ssccUri)->exists());
        do {
            $sgtinUri = 'urn:epc:id:sgtin:030116.0200116.'.(string) random_int(10_000_000_000_000, 99_999_999_999_999);
        } while (Epc::query()->where('epc_uri', $sgtinUri)->exists());

        $xml = (string) file_get_contents(base_path('tests/Fixtures/epcis/minimal_with_shipping_refs.xml'));
        $xml = str_replace(
            [
                '22222222-3333-4444-5555-666666666666',
                'urn:epc:id:sscc:030116.01001227052',
                'urn:epc:id:sgtin:030116.0200116.10000082001560',
                'urn:epc:id:sgln:030116.000000.0',
                '0301160000009',
            ],
            [(string) Str::uuid(), $ssccUri, $sgtinUri, $urn, $gln],
            $xml,
        );

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        file_put_contents($tmp, $xml);
        $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
            'direction' => 'inbound',
            'original_filename' => 'record-published-sgln.xml',
        ]);
        @unlink($tmp);

        return $document;
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
                $eventIds = DB::table('epcis_events')->where('document_id', $this->documentId)->pluck('id');
                DB::table('event_epcs')->whereIn('event_id', $eventIds)->delete();
                DB::table('event_locations')->whereIn('event_id', $eventIds)->delete();
                DB::table('event_parties')->whereIn('event_id', $eventIds)->delete();
                DB::table('epcis_events')->where('document_id', $this->documentId)->delete();
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }

            foreach ($this->siteIds as $id) {
                Site::query()->whereKey($id)->delete();
            }
            foreach ($this->partnerIds as $id) {
                TradingPartner::query()->whereKey($id)->delete();
            }
            $this->siteIds = [];
            $this->partnerIds = [];
            tenancy()->end();
        }
    }
}
