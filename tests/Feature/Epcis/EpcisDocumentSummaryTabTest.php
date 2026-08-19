<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\PersistEpcisDocumentVocabulary;
use App\Enums\TenantProfile;
use App\Filament\App\Resources\EpcisDocuments\Pages\ViewEpcisDocument;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisDocumentSummaryTabTest extends TestCase
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
    private array $eventIds = [];

    #[Test]
    public function view_page_uses_summary_as_first_combined_content_tab(): void
    {
        $page = app(ViewEpcisDocument::class);

        $this->assertTrue($page->hasCombinedRelationManagerTabsWithContent());
        $this->assertSame('Summary', $page->getContentTabLabel());
    }

    #[Test]
    public function file_shipment_summary_returns_products_lots_items_and_transactions(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeSummaryDocument();

            $summary = $document->fileShipmentSummary();

            $this->assertSame(1, $summary['product_count']);
            $this->assertNotEmpty($summary['product_ndcs']);
            $this->assertSame(1, $summary['lot_count']);
            $this->assertSame(['695117'], $summary['lots']);
            $this->assertSame(3, $summary['item_count']);
            $this->assertSame('198167', $summary['customer_po']);
            $this->assertSame('02647664966', $summary['asn_number']);
            $this->assertStringContainsString('FDCA', (string) $summary['legal_notice']);
            $this->assertTrue($summary['dscsa_affirm']);
            $this->assertSame(1, $summary['case_count']);
            $this->assertSame(1, $summary['unit_count']);
            $this->assertSame('1 case · 1 unit', $summary['case_unit_label']);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function summary_tab_renders_on_view_page(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $user = User::query()->first();
            $this->assertNotNull($user);

            Filament::setCurrentPanel(Filament::getPanel('app'));
            $this->actingAs($user);

            $document = $this->makeSummaryDocument();

            Livewire::test(ViewEpcisDocument::class, ['record' => $document->getKey()])
                ->assertSuccessful()
                ->assertSee('Summary')
                ->assertSee('Products')
                ->assertSee('Lots')
                ->assertSee('Items')
                ->assertSee('Purchase Order')
                ->assertSee('Despatch Advice')
                ->assertSee('198167')
                ->assertSee('02647664966');
        } finally {
            $this->cleanup();
        }
    }

    private function makeSummaryDocument(): EpcisDocument
    {
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
                <attribute id="urn:epcglobal:cbv:mda#regulatedProductName">PROMETHAZINE HYDROCHLORIDE</attribute>
              </VocabularyElement>
              <VocabularyElement id="urn:epc:idpat:sgtin:030116.5402316.*">
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentification">00116402316</attribute>
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
                <attribute id="urn:epcglobal:cbv:mda#regulatedProductName">PROMETHAZINE HYDROCHLORIDE</attribute>
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

        $path = 'epcis/inbound/summary-tab-'.(string) str()->uuid().'.xml';
        Storage::disk('local')->put($path, $xml);

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'summary-tab.xml',
            'file_sha256' => hash('sha256', $xml),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'dscsa_affirm' => true,
            'legal_notice' => 'Seller has complied with each applicable subsection of FDCA Sec. 581(27)(A)-(G).',
            'customer_po' => '198167',
            'asn_number' => '02647664966',
            'status' => 'validated',
            'event_count' => 1,
            'epc_count' => 3,
            'received_at' => now(),
            'ingest_generation' => 1,
            'reprocess_count' => 0,
        ]);
        $this->documentIds[] = (int) $document->id;

        app(PersistEpcisDocumentVocabulary::class)->handle(
            $document,
            1,
            [
                [
                    'idpat' => 'urn:epc:idpat:sgtin:030116.3402316.*',
                    'ndc11' => '00116402316',
                    'ndc_raw' => '00116402316',
                    'name' => 'PROMETHAZINE HYDROCHLORIDE',
                    'attributes_json' => [],
                ],
                [
                    'idpat' => 'urn:epc:idpat:sgtin:030116.5402316.*',
                    'ndc11' => '00116402316',
                    'ndc_raw' => '00116402316',
                    'name' => 'PROMETHAZINE HYDROCHLORIDE',
                    'attributes_json' => [],
                ],
            ],
            [],
        );

        $suffix = str_replace('-', '', (string) str()->uuid());
        $unitSerial = 'su'.$suffix;
        $caseSerial = 'sc'.$suffix;
        $ssccSerial = substr($suffix, 0, 10);

        $unit = Epc::query()->create([
            'epc_uri' => 'urn:epc:id:sgtin:030116.3402316.'.$unitSerial,
            'epc_type' => 'sgtin',
            'company_prefix' => '030116',
            'indicator_digit' => 3,
            'gtin14' => '30301164023166',
            'serial_number' => $unitSerial,
            'product_id' => null,
            'first_seen_at' => now(),
        ]);
        $case = Epc::query()->create([
            'epc_uri' => 'urn:epc:id:sgtin:030116.5402316.'.$caseSerial,
            'epc_type' => 'sgtin',
            'company_prefix' => '030116',
            'indicator_digit' => 5,
            'gtin14' => '50301164023160',
            'serial_number' => $caseSerial,
            'product_id' => null,
            'first_seen_at' => now(),
        ]);
        $sscc = Epc::query()->create([
            'epc_uri' => 'urn:epc:id:sscc:030116.0'.$ssccSerial,
            'epc_type' => 'sscc',
            'company_prefix' => '030116',
            'sscc18' => '00301160'.str_pad($ssccSerial, 10, '0'),
            'serial_number' => $ssccSerial,
            'product_id' => null,
            'first_seen_at' => now(),
        ]);
        $this->epcIds = [(int) $unit->id, (int) $case->id, (int) $sscc->id];

        if (Schema::hasTable('document_epcs')) {
            DB::table('document_epcs')->insert([
                ['document_id' => $document->id, 'epc_id' => $unit->id, 'ingest_generation' => 1],
                ['document_id' => $document->id, 'epc_id' => $case->id, 'ingest_generation' => 1],
                ['document_id' => $document->id, 'epc_id' => $sscc->id, 'ingest_generation' => 1],
            ]);
        }

        $event = EpcisEvent::query()->create([
            'document_id' => $document->id,
            'event_type' => 'ObjectEvent',
            'event_time' => now(),
            'action' => 'ADD',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:commissioning',
            'disposition' => 'urn:epcglobal:cbv:disp:active',
            'ingest_generation' => 1,
        ]);
        $this->eventIds[] = (int) $event->id;

        if (Schema::hasTable('event_epc_ilmd')) {
            DB::table('event_epc_ilmd')->insert([
                'event_id' => $event->id,
                'epc_id' => $unit->id,
                'lot_number' => '695117',
                'expiry_date' => '2027-10-31',
            ]);
        } elseif (Schema::hasTable('epc_ilmd')) {
            DB::table('epc_ilmd')->insert([
                'epc_id' => $unit->id,
                'lot_number' => '695117',
                'expiry_date' => '2027-10-31',
            ]);
        }

        if (Schema::hasTable('aggregation_links')) {
            DB::table('aggregation_links')->insert([
                'parent_epc_id' => $case->id,
                'child_epc_id' => $unit->id,
                'established_by_event_id' => $event->id,
                'link_type' => 'aggregation',
                'valid_from' => now(),
                'valid_to' => null,
                'created_at' => now(),
            ]);
        }

        return $document->fresh();
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

        if ($this->eventIds !== [] && Schema::hasTable('aggregation_links')) {
            DB::table('aggregation_links')->whereIn('established_by_event_id', $this->eventIds)->delete();
        }

        if ($this->eventIds !== [] && Schema::hasTable('event_epc_ilmd')) {
            DB::table('event_epc_ilmd')->whereIn('event_id', $this->eventIds)->delete();
        }

        if ($this->documentIds !== [] && Schema::hasTable('document_epcs')) {
            DB::table('document_epcs')->whereIn('document_id', $this->documentIds)->delete();
        }

        if ($this->eventIds !== []) {
            EpcisEvent::query()->whereIn('id', $this->eventIds)->delete();
            $this->eventIds = [];
        }

        if ($this->epcIds !== []) {
            if (Schema::hasTable('epc_ilmd')) {
                DB::table('epc_ilmd')->whereIn('epc_id', $this->epcIds)->delete();
            }
            Epc::query()->whereIn('id', $this->epcIds)->delete();
            $this->epcIds = [];
        }

        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        tenancy()->end();
    }
}
