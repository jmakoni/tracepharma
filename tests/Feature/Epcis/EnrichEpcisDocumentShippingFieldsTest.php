<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\EnrichEpcisDocumentShippingFields;
use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Enums\TenantProfile;
use App\Filament\App\Resources\EpcisDocuments\Pages\ListEpcisDocuments;
use App\Filament\App\Resources\EpcisDocuments\Tables\EpcisDocumentsTable;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\TenantSettings;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EnrichEpcisDocumentShippingFieldsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    #[Test]
    public function enrich_sets_asn_and_customer_po_on_xttrium_document(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasColumn('epcis_documents', 'customer_po'));

            $document = $this->findXttriumDocumentContaining('C7174125NLC');
            if ($document === null) {
                $this->markTestSkipped('Demo2 has no Xttrium payload containing PO C7174125NLC.');
            }

            $document->forceFill([
                'customer_po' => null,
                'asn_number' => null,
            ])->save();

            $enriched = app(EnrichEpcisDocumentShippingFields::class)->handle($document->fresh());

            $this->assertSame('C7174125NLC', $enriched->customer_po);
            $this->assertSame('02647871016', $enriched->asn_number);
            $this->assertNotNull($enriched->ship_from_gln);
            $this->assertNotNull($enriched->ship_to_gln);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function enrich_skips_inbound_ship_to_site_when_matching_disabled(): void
    {
        $this->initializeDemo2Tenant();

        $settings = TenantSettings::forTenant(tenant());
        $prior = $settings->matchInboundShipToSite();

        try {
            $settings->setMatchInboundShipToSite(false);

            $document = EpcisDocument::query()
                ->where('direction', 'inbound')
                ->whereNotNull('ship_to_gln')
                ->orderByDesc('id')
                ->first();

            if ($document === null) {
                $this->markTestSkipped('Demo2 has no inbound document with ship_to_gln.');
            }

            $partnerSite = Site::factory()->create([
                'code' => 'ENR-PART-'.fake()->unique()->numerify('###'),
            ]);

            $document->forceFill([
                'ship_to_site_id' => $partnerSite->getKey(),
            ])->save();

            $enriched = app(EnrichEpcisDocumentShippingFields::class)->handle($document->fresh());

            $this->assertNotNull($enriched->ship_to_gln);
            $this->assertNull($enriched->ship_to_site_id);

            $partnerSite->delete();
        } finally {
            $settings->setMatchInboundShipToSite($prior);
            tenancy()->end();
        }
    }

    #[Test]
    public function enrich_backfills_cardinal_names_from_xttrium_payload(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasColumn('epcis_documents', 'ship_to_name'));

            $document = $this->findXttriumDocumentContaining('Xttrium Laboratories, Inc.');
            if ($document === null) {
                $this->markTestSkipped('Demo2 has no Xttrium payload containing seller name.');
            }
            $this->assertNotNull($document->payload_path);

            $document->forceFill([
                'ship_from_name' => null,
                'ship_from_site_name' => null,
                'ship_to_name' => null,
                'ship_to_site_name' => null,
            ])->save();

            $enriched = app(EnrichEpcisDocumentShippingFields::class)->handle($document->fresh());

            $this->assertSame('Xttrium Laboratories, Inc.', $enriched->ship_from_name);
            $this->assertSame('Xttrium Glenview', $enriched->ship_from_site_name);
            $this->assertSame('Cardinal Health - Corporate', $enriched->ship_to_name);
            $this->assertSame('Cardinal Groveport', $enriched->ship_to_site_name);
        } finally {
            tenancy()->end();
        }
    }

    #[Test]
    public function ingest_with_shipping_refs_enriches_asn_and_po(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $fixture = base_path('tests/Fixtures/epcis/minimal_with_shipping_refs.xml');
            $this->assertFileExists($fixture);

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_ship_');
            $this->assertNotFalse($tmp);
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $uuid = (string) str()->uuid();
            $xml = str_replace('22222222-3333-4444-5555-666666666666', $uuid, $xml);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_with_shipping_refs.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $this->assertSame('validated', $document->status);
            $this->assertSame(4, $document->event_count);
            $this->assertSame('PO-TEST-7174', $document->customer_po);
            $this->assertSame('ASN-TEST-4787', $document->asn_number);
            $this->assertSame('0301160000016', $document->ship_from_gln);
            $this->assertSame('0096295000993', $document->ship_to_gln);

            @unlink($tmp);
        } finally {
            $this->cleanupIngestFixtures();
        }
    }

    #[Test]
    public function reprocess_keeps_asn_and_po_when_prior_generation_is_superseded(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $fixture = base_path('tests/Fixtures/epcis/minimal_with_shipping_refs.xml');
            $this->assertFileExists($fixture);

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_ship_rp_');
            $this->assertNotFalse($tmp);
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $uuid = (string) str()->uuid();
            $xml = str_replace('22222222-3333-4444-5555-666666666666', $uuid, $xml);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_with_shipping_refs.xml',
            ]);
            $this->documentId = (int) $document->getKey();
            @unlink($tmp);

            $this->assertSame('PO-TEST-7174', $document->customer_po);
            $this->assertSame('ASN-TEST-4787', $document->asn_number);

            $reprocessed = app(ReprocessEpcisDocument::class)->handle(
                $document,
                sync: true,
                force: true,
                authorizeExceptionsRole: false,
            );

            $this->assertSame('validated', $reprocessed->status);
            $this->assertGreaterThan(1, (int) $reprocessed->ingest_generation);
            $this->assertSame('PO-TEST-7174', $reprocessed->customer_po);
            $this->assertSame('ASN-TEST-4787', $reprocessed->asn_number);
        } finally {
            $this->cleanupIngestFixtures();
        }
    }

    #[Test]
    public function list_table_exposes_shipping_columns(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $table = EpcisDocumentsTable::configure(Table::make(new ListEpcisDocuments));
            $columns = collect($table->getColumns());
            $names = $columns->map(fn ($column) => $column->getName())->all();
            $labels = $columns->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])->all();

            $this->assertContains('creation_date', $names);
            $this->assertContains('seller_display', $names);
            $this->assertContains('asn_number', $names);
            $this->assertContains('customer_po', $names);
            $this->assertContains('ship_from_display', $names);
            $this->assertContains('sold_to_display', $names);
            $this->assertContains('ship_to_site_display', $names);

            $this->assertSame('Date', $labels['creation_date']);
            $this->assertSame('Seller', $labels['seller_display']);
            $this->assertSame('ASN', $labels['asn_number']);
            $this->assertSame('Customer PO', $labels['customer_po']);
        } finally {
            tenancy()->end();
        }
    }

    private function findXttriumDocumentContaining(string $needle): ?EpcisDocument
    {
        $documents = EpcisDocument::query()
            ->where('original_filename', 'like', '%xttrium%')
            ->whereNotNull('payload_path')
            ->orderBy('id')
            ->get();

        foreach ($documents as $document) {
            $disk = filled($document->payload_disk) ? (string) $document->payload_disk : 'local';
            $path = (string) $document->payload_path;
            if ($path === '' || $disk === 's3') {
                continue;
            }

            try {
                if (! Storage::disk($disk)->exists($path)) {
                    continue;
                }

                if (str_contains((string) Storage::disk($disk)->get($path), $needle)) {
                    return $document;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
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

    private function cleanupIngestFixtures(): void
    {
        if (tenancy()->initialized) {
            if ($this->documentId !== null) {
                EpcisDocument::query()->whereKey($this->documentId)->delete();
            }

            foreach ([
                'urn:epc:id:sgtin:030116.0200116.10000082001560',
                'urn:epc:id:sscc:030116.01001227052',
            ] as $uri) {
                $epc = Epc::query()->where('epc_uri', $uri)->first();
                if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                    $epc->delete();
                }
            }

            tenancy()->end();
        }
    }
}
