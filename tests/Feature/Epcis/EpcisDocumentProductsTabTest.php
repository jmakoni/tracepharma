<?php

namespace Tests\Feature\Epcis;

use App\Enums\TenantProfile;
use App\Filament\App\Resources\EpcisDocuments\EpcisDocumentResource;
use App\Filament\App\Resources\EpcisDocuments\Pages\ViewEpcisDocument;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\EpcsRelationManager;
use App\Filament\App\Resources\EpcisDocuments\RelationManagers\ProductsRelationManager;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Product;
use App\Models\Tenant;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisDocumentProductsTabTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    /** @var list<int> */
    private array $productIds = [];

    #[Test]
    public function products_relation_manager_is_registered_after_epcs(): void
    {
        $relations = EpcisDocumentResource::getRelations();
        $this->assertContains(ProductsRelationManager::class, $relations);

        $epcsIndex = array_search(
            EpcsRelationManager::class,
            $relations,
            true,
        );
        $productsIndex = array_search(ProductsRelationManager::class, $relations, true);

        $this->assertNotFalse($epcsIndex);
        $this->assertNotFalse($productsIndex);
        $this->assertSame($epcsIndex + 1, $productsIndex);
    }

    #[Test]
    public function products_query_returns_distinct_linked_products_for_document(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('document_epcs'));

            $product = Product::factory()->create([
                'name' => 'Chlorhexidine Gluconate 4%',
                'gtin' => '00301162001165',
                'ndc11' => '00116200116',
                'package_ndc' => '0116-2001-16',
                'ndc' => '0116-2001-16',
                'dosage_form' => 'solution',
                'strength' => '4%',
            ]);
            $this->productIds[] = (int) $product->id;

            $otherProduct = Product::factory()->create([
                'name' => 'Other Product Not In File',
                'gtin' => '00301162999999',
            ]);
            $this->productIds[] = (int) $otherProduct->id;

            $document = $this->makeDocument();
            $epcA = $this->makeEpc('urn:epc:id:sgtin:030116.0200116.prod-a', $product->id);
            $epcB = $this->makeEpc('urn:epc:id:sgtin:030116.0200116.prod-b', $product->id);
            $epcUnlinked = $this->makeEpc('urn:epc:id:sgtin:030116.0200116.unlinked', null);

            DB::table('document_epcs')->insert([
                ['document_id' => $document->id, 'epc_id' => $epcA->id, 'ingest_generation' => 1],
                ['document_id' => $document->id, 'epc_id' => $epcB->id, 'ingest_generation' => 1],
                ['document_id' => $document->id, 'epc_id' => $epcUnlinked->id, 'ingest_generation' => 1],
            ]);

            $summaries = $document->fileProductSummaries();
            $this->assertCount(1, $summaries);
            $this->assertSame((int) $product->id, $summaries->first()['product_id']);
            $this->assertTrue($summaries->first()['linked']);
            $this->assertSame(3, $summaries->first()['document_epc_count']);
            $this->assertSame('3 units', $summaries->first()['epc_breakdown']);
            $this->assertSame('Unknown product', $summaries->first()['name']);
            $this->assertNull($summaries->first()['ndc']);
            $this->assertSame('assortment', $summaries->first()['catalog_status']);

            $ids = $document->productsQuery()->pluck('products.id')->map(fn ($id) => (int) $id)->all();
            $this->assertSame([(int) $product->id], $ids);
            $this->assertNotContains((int) $otherProduct->id, $ids);

            $manager = app(ProductsRelationManager::class);
            $manager->pageClass = ViewEpcisDocument::class;
            $manager->ownerRecord = $document;

            $table = $manager->table(Table::make($manager));
            $columnNames = collect($table->getColumns())->map(fn ($column) => $column->getName())->all();

            $this->assertContains('name', $columnNames);
            $this->assertContains('ndc', $columnNames);
            $this->assertContains('gtin', $columnNames);
            $this->assertContains('manufacturer', $columnNames);
            $this->assertContains('epc_breakdown', $columnNames);
            $this->assertContains('catalog_status', $columnNames);

            $this->assertSame('1', ProductsRelationManager::getBadge($document, ViewEpcisDocument::class));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function file_product_summaries_include_unknown_gtins_without_master_data(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeDocument();
            $epc = $this->makeEpc('urn:epc:id:sgtin:030116.0200116.none', null);

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    ['document_id' => $document->id, 'epc_id' => $epc->id, 'ingest_generation' => 1],
                ]);
            }

            $summaries = $document->fileProductSummaries();
            $this->assertCount(1, $summaries);
            $this->assertFalse($summaries->first()['linked']);
            $this->assertSame('Unknown product', $summaries->first()['name']);
            $this->assertSame('00301162001165', $summaries->first()['gtin']);
            $this->assertSame('1', ProductsRelationManager::getBadge($document, ViewEpcisDocument::class));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function file_product_summaries_prefer_epcis_master_data_vocabulary(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="1.2" creationDate="2026-08-04T16:33:46.366Z">
  <EPCISHeader>
    <extension>
      <EPCISMasterData>
        <VocabularyList>
          <Vocabulary type="urn:epcglobal:epcis:vtype:EPCClass">
            <VocabularyElementList>
              <VocabularyElement id="urn:epc:idpat:sgtin:030116.3402316.*">
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentification">00116402316</attribute>
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
                <attribute id="urn:epcglobal:cbv:mda#manufacturerOfTradeItemPartyName">Xttrium Laboratories, Inc.</attribute>
                <attribute id="urn:epcglobal:cbv:mda#regulatedProductName">PROMETHAZINE HYDROCHLORIDE</attribute>
                <attribute id="urn:epcglobal:cbv:mda#dosageFormType">SYRUP</attribute>
                <attribute id="urn:epcglobal:cbv:mda#strengthDescription">6.25mg/5mL</attribute>
                <attribute id="urn:epcglobal:cbv:mda#netContentDescription">473 mL in 1 BOTTLE, PLASTIC</attribute>
              </VocabularyElement>
            </VocabularyElementList>
          </Vocabulary>
        </VocabularyList>
      </EPCISMasterData>
    </extension>
  </EPCISHeader>
  <EPCISBody><EventList/></EPCISBody>
</epcis:EPCISDocument>
XML;

            $path = 'epcis/inbound/products-vocab-'.(string) str()->uuid().'.xml';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'products-vocab.xml',
                'file_sha256' => hash('sha256', $xml),
                'payload_disk' => 'local',
                'payload_path' => $path,
                'dscsa_affirm' => false,
                'status' => 'validated',
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
                'ingest_generation' => 1,
                'reprocess_count' => 0,
            ]);
            $this->documentIds[] = (int) $document->id;

            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.3402316.unit-1',
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '30301164023166',
                'serial_number' => 'unit-1',
                'product_id' => null,
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    ['document_id' => $document->id, 'epc_id' => $epc->id, 'ingest_generation' => 1],
                ]);
            }

            $summary = $document->fileProductSummaries()->first();
            $this->assertNotNull($summary);
            $this->assertSame('PROMETHAZINE HYDROCHLORIDE', $summary['name']);
            // FDA_NDC_11 source → HIPAA reverse to FDA 4-4-2 (or FDA listing as-is when present)
            $this->assertSame('0116-4023-16', $summary['ndc']);
            $this->assertSame('SYRUP', $summary['dosage_form']);
            $this->assertSame('6.25mg/5mL', $summary['strength']);
            $this->assertSame('Xttrium Laboratories, Inc.', $summary['manufacturer']);
            $this->assertSame('473 mL in 1 BOTTLE, PLASTIC', $summary['net_content']);
            $this->assertFalse($summary['linked']);
            $this->assertContains($summary['catalog_status'], ['none', 'fda']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function file_product_summaries_preserve_us_fda_ndc_dashed_source(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="1.2" creationDate="2026-08-04T16:33:46.366Z">
  <EPCISHeader>
    <extension>
      <EPCISMasterData>
        <VocabularyList>
          <Vocabulary type="urn:epcglobal:epcis:vtype:EPCClass">
            <VocabularyElementList>
              <VocabularyElement id="urn:epc:idpat:sgtin:061414.1078901.*">
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentification">12345-678-01</attribute>
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">US_FDA_NDC</attribute>
                <attribute id="urn:epcglobal:cbv:mda#regulatedProductName">SAMPLE 5-3-2 PRODUCT</attribute>
              </VocabularyElement>
            </VocabularyElementList>
          </Vocabulary>
        </VocabularyList>
      </EPCISMasterData>
    </extension>
  </EPCISHeader>
  <EPCISBody><EventList/></EPCISBody>
</epcis:EPCISDocument>
XML;

            $path = 'epcis/inbound/products-us-fda-ndc-'.(string) str()->uuid().'.xml';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'products-us-fda-ndc.xml',
                'file_sha256' => hash('sha256', $xml),
                'payload_disk' => 'local',
                'payload_path' => $path,
                'dscsa_affirm' => false,
                'status' => 'validated',
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
                'ingest_generation' => 1,
                'reprocess_count' => 0,
            ]);
            $this->documentIds[] = (int) $document->id;

            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:061414.1078901.usfda-1',
                'epc_type' => 'sgtin',
                'company_prefix' => '061414',
                'gtin14' => '10614140789014',
                'serial_number' => 'usfda-1',
                'product_id' => null,
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    ['document_id' => $document->id, 'epc_id' => $epc->id, 'ingest_generation' => 1],
                ]);
            }

            $summary = $document->fileProductSummaries()->first();
            $this->assertNotNull($summary);
            $this->assertSame('SAMPLE 5-3-2 PRODUCT', $summary['name']);
            $this->assertSame('12345-678-01', $summary['ndc']);
        } finally {
            $this->cleanup();
        }
    }

    private function makeDocument(): EpcisDocument
    {
        $path = 'epcis/inbound/products-tab-'.(string) str()->uuid().'.xml';
        Storage::disk('local')->put($path, '<epcis/>');

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'products-tab.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'dscsa_affirm' => false,
            'status' => 'validated',
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => now(),
            'ingest_generation' => 1,
            'reprocess_count' => 0,
        ]);

        $this->documentIds[] = (int) $document->id;

        return $document;
    }

    private function makeEpc(string $uri, ?int $productId): Epc
    {
        $epc = Epc::query()->create([
            'epc_uri' => $uri,
            'epc_type' => 'sgtin',
            'company_prefix' => '030116',
            'gtin14' => '00301162001165',
            'serial_number' => substr(md5($uri), 0, 12),
            'product_id' => $productId,
            'first_seen_at' => now(),
        ]);

        $this->epcIds[] = (int) $epc->id;

        return $epc;
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
        if (! tenancy()->initialized) {
            return;
        }

        if ($this->documentIds !== [] && Schema::hasTable('document_epcs')) {
            DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
        }

        if ($this->epcIds !== []) {
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->productIds !== []) {
            Product::query()->whereIn('id', $this->productIds)->delete();
            $this->productIds = [];
        }

        tenancy()->end();
    }
}
