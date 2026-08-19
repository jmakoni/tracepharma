<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\BackfillEpcisDocumentVocabulary;
use App\Actions\Epcis\PersistEpcisDocumentVocabulary;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisDocumentLocation;
use App\Models\Epcis\EpcisDocumentProductClass;
use App\Models\Epcis\EpcisDocumentVocabularyElement;
use App\Models\Tenant;
use App\Support\Epcis\EpcisXmlReader;
use App\Support\Gs1\Ndc;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisDocumentVocabularyPersistTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $epcIds = [];

    #[Test]
    public function persist_vocabulary_writes_product_and_location_rows(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('epcis_document_product_classes'));
            $this->assertTrue(Schema::hasTable('epcis_document_locations'));

            $document = $this->makeDocumentWithVocabularyPayload();
            $counts = app(PersistEpcisDocumentVocabulary::class)->handle(
                $document,
                1,
                [[
                    'idpat' => 'urn:epc:idpat:sgtin:030116.3402316.*',
                    'ndc11' => '00116402316',
                    'ndc_raw' => '00116402316',
                    'name' => 'PROMETHAZINE HYDROCHLORIDE',
                    'dosage_form' => 'SYRUP',
                    'strength' => '6.25mg/5mL',
                    'manufacturer' => 'Xttrium Laboratories, Inc.',
                    'net_content' => '473 mL in 1 BOTTLE, PLASTIC',
                    'attributes_json' => [
                        'urn:epcglobal:cbv:mda#regulatedProductName' => 'PROMETHAZINE HYDROCHLORIDE',
                    ],
                ]],
                [[
                    'gln_uri' => 'urn:epc:id:sgln:030116.000000.0',
                    'gln' => '0301160000009',
                    'name' => 'Xttrium Laboratories, Inc.',
                    'street_address' => '1200 E BUSINESS CENTER DR',
                    'city' => 'MOUNT PROSPECT',
                    'state' => 'IL',
                    'postal_code' => '60056-6041',
                    'country_code' => 'US',
                    'attributes_json' => [
                        'urn:epcglobal:cbv:mda#name' => 'Xttrium Laboratories, Inc.',
                    ],
                ]],
            );

            $this->assertSame(1, $counts['product_classes']);
            $this->assertSame(1, $counts['locations']);

            $class = EpcisDocumentProductClass::query()
                ->where('document_id', $document->id)
                ->where('ingest_generation', 1)
                ->first();
            $this->assertNotNull($class);
            $this->assertSame('30301164023166', $class->gtin14);
            $this->assertSame('PROMETHAZINE HYDROCHLORIDE', $class->name);

            $location = EpcisDocumentLocation::query()
                ->where('document_id', $document->id)
                ->where('ingest_generation', 1)
                ->first();
            $this->assertNotNull($location);
            $this->assertSame('0301160000009', $location->gln);
            $this->assertSame('MOUNT PROSPECT', $location->city);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function file_product_summaries_prefer_persisted_vocabulary_rows(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeDocumentWithVocabularyPayload();
            $epc = Epc::query()->create([
                'epc_uri' => 'urn:epc:id:sgtin:030116.3402316.persist-1',
                'epc_type' => 'sgtin',
                'company_prefix' => '030116',
                'gtin14' => '30301164023166',
                'serial_number' => 'persist-1',
                'product_id' => null,
                'first_seen_at' => now(),
            ]);
            $this->epcIds[] = (int) $epc->id;

            if (Schema::hasTable('document_epcs')) {
                DB::table('document_epcs')->insert([
                    ['document_id' => $document->id, 'epc_id' => $epc->id, 'ingest_generation' => 1],
                ]);
            }

            app(PersistEpcisDocumentVocabulary::class)->handle(
                $document,
                1,
                [[
                    'idpat' => 'urn:epc:idpat:sgtin:030116.3402316.*',
                    'ndc11' => '00116402316',
                    'ndc_raw' => '00116402316',
                    'name' => 'PROMETHAZINE HYDROCHLORIDE',
                    'dosage_form' => 'SYRUP',
                    'strength' => '6.25mg/5mL',
                    'manufacturer' => 'Xttrium Laboratories, Inc.',
                    'net_content' => '473 mL',
                    'attributes_json' => [],
                ]],
                [],
            );

            // Corrupt payload so XML fallback would fail — summaries must still work from DB.
            Storage::disk('local')->put($document->payload_path, '<broken');

            $summary = $document->fresh()->fileProductSummaries()->first();
            $this->assertNotNull($summary);
            $this->assertSame('PROMETHAZINE HYDROCHLORIDE', $summary['name']);
            $this->assertSame('SYRUP', $summary['dosage_form']);
            $this->assertContains($summary['catalog_status'], ['fda', 'none', 'assortment']);
            // FDA openFDA lists this package as 0116-4023-16 (same NDC-11 as 00116402316).
            // Without a listing, HIPAA NDC-11 still displays as FDA 4-4-2 via formatPackageDisplay.
            $this->assertSame('0116-4023-16', $summary['ndc']);
            $this->assertTrue(Ndc::equals((string) $summary['ndc'], '00116402316'));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function persist_other_vocabulary_and_header_json(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('epcis_document_vocabulary_elements'));
            $this->assertTrue(Schema::hasColumn('epcis_documents', 'header_json'));

            $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument
    xmlns:epcis="urn:epcglobal:epcis:xsd:1"
    xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader"
    schemaVersion="1.2"
    creationDate="2026-08-04T16:33:46.366Z">
  <EPCISHeader>
    <sbdh:StandardBusinessDocumentHeader>
      <sbdh:HeaderVersion>1.0</sbdh:HeaderVersion>
      <sbdh:Sender>
        <sbdh:Identifier Authority="GLN">0301160000009</sbdh:Identifier>
      </sbdh:Sender>
      <sbdh:Receiver>
        <sbdh:Identifier Authority="GLN">0096295000009</sbdh:Identifier>
      </sbdh:Receiver>
      <sbdh:DocumentIdentification>
        <sbdh:Standard>EPCglobal</sbdh:Standard>
        <sbdh:TypeVersion>1.0</sbdh:TypeVersion>
        <sbdh:InstanceIdentifier>aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee</sbdh:InstanceIdentifier>
        <sbdh:Type>Events</sbdh:Type>
        <sbdh:CreationDateAndTime>2026-08-04T16:33:46.366Z</sbdh:CreationDateAndTime>
      </sbdh:DocumentIdentification>
    </sbdh:StandardBusinessDocumentHeader>
    <extension>
      <EPCISMasterData>
        <VocabularyList>
          <Vocabulary type="urn:epcglobal:epcis:vtype:EPCClass">
            <VocabularyElementList>
              <VocabularyElement id="urn:epc:idpat:sgtin:030116.3402316.*">
                <attribute id="urn:epcglobal:cbv:mda#regulatedProductName">PROMETHAZINE</attribute>
              </VocabularyElement>
            </VocabularyElementList>
          </Vocabulary>
          <Vocabulary type="urn:epcglobal:epcis:vtype:ReadPoint">
            <VocabularyElementList>
              <VocabularyElement id="urn:epc:id:sgln:030116.000099.0">
                <attribute id="urn:epcglobal:cbv:mda#name">Pack Line 1</attribute>
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

            $path = 'epcis/inbound/vocab-other-'.(string) str()->uuid().'.xml';
            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) str()->uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'inbound',
                'format' => 'xml',
                'original_filename' => 'vocab-other.xml',
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

            $header = (new EpcisXmlReader)->parseHeader(Storage::disk('local')->path($path));

            $this->assertNotEmpty($header['other_vocabulary'] ?? []);
            $this->assertSame(
                'urn:epcglobal:epcis:vtype:ReadPoint',
                $header['other_vocabulary'][0]['vocabulary_type'],
            );
            $this->assertIsArray($header['header_json'] ?? null);
            $this->assertSame('1.0', $header['header_json']['HeaderVersion'] ?? null);
            $this->assertSame('EPCglobal', $header['header_json']['DocumentIdentification']['Standard'] ?? null);
            $this->assertArrayNotHasKey('InstanceIdentifier', $header['header_json']['DocumentIdentification'] ?? []);

            $counts = app(PersistEpcisDocumentVocabulary::class)->handle(
                $document,
                1,
                $header['product_classes'] ?? [],
                $header['locations'] ?? [],
                $header['other_vocabulary'] ?? [],
            );
            $this->assertSame(1, $counts['other_vocabulary']);

            $document->forceFill(['header_json' => $header['header_json']])->save();

            $row = EpcisDocumentVocabularyElement::query()
                ->where('document_id', $document->id)
                ->where('ingest_generation', 1)
                ->first();
            $this->assertNotNull($row);
            $this->assertSame('urn:epc:id:sgln:030116.000099.0', $row->element_id);
            $this->assertSame('Pack Line 1', $row->attributes_json['urn:epcglobal:cbv:mda#name'] ?? null);

            $fresh = $document->fresh();
            $this->assertSame('1.0', $fresh->header_json['HeaderVersion'] ?? null);
            $this->assertSame('Events', $fresh->header_json['DocumentIdentification']['Type'] ?? null);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function over_length_identifiers_are_skipped_instead_of_truncated_into_the_unique_key(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeDocumentWithVocabularyPayload();

            $longSuffix = str_repeat('a', 200);

            $counts = app(PersistEpcisDocumentVocabulary::class)->handle(
                $document,
                1,
                [
                    [
                        'idpat' => 'urn:epc:idpat:sgtin:030116.3402316.*',
                        'name' => 'Keeps its row',
                        'attributes_json' => [],
                    ],
                    [
                        'idpat' => 'urn:epc:idpat:sgtin:030116.3402316.'.$longSuffix,
                        'name' => 'Would collide once truncated',
                        'attributes_json' => [],
                    ],
                ],
                [
                    [
                        'gln_uri' => 'urn:epc:id:sgln:030116.000000.0',
                        'gln' => '0301160000009',
                        'name' => 'Keeps its row',
                        'attributes_json' => [],
                    ],
                    [
                        'gln_uri' => 'urn:epc:id:sgln:030116.000000.'.$longSuffix,
                        'name' => 'Would collide once truncated',
                        'attributes_json' => [],
                    ],
                ],
                [
                    [
                        'vocabulary_type' => 'urn:epcglobal:epcis:vtype:ReadPoint',
                        'element_id' => 'urn:epc:id:sgln:030116.000099.0',
                        'attributes_json' => [],
                    ],
                    [
                        'vocabulary_type' => 'urn:epcglobal:epcis:vtype:ReadPoint',
                        'element_id' => 'urn:epc:id:sgln:030116.000099.'.$longSuffix,
                        'attributes_json' => [],
                    ],
                ],
            );

            $this->assertSame(1, $counts['product_classes']);
            $this->assertSame(1, $counts['locations']);
            $this->assertSame(1, $counts['other_vocabulary']);

            $this->assertSame(
                'Keeps its row',
                EpcisDocumentProductClass::query()->where('document_id', $document->id)->value('name'),
            );
            $this->assertSame(
                'Keeps its row',
                EpcisDocumentLocation::query()->where('document_id', $document->id)->value('name'),
            );
            $this->assertSame(
                'urn:epc:id:sgln:030116.000099.0',
                EpcisDocumentVocabularyElement::query()->where('document_id', $document->id)->value('element_id'),
            );
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function backfill_skips_when_rows_already_exist_unless_forced(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeDocumentWithVocabularyPayload();
            $backfill = app(BackfillEpcisDocumentVocabulary::class);

            $first = $backfill->handle($document);
            $this->assertFalse($first['skipped']);
            $this->assertGreaterThan(0, $first['product_classes']);

            $second = $backfill->handle($document);
            $this->assertTrue($second['skipped']);

            $forced = $backfill->handle($document, force: true);
            $this->assertFalse($forced['skipped']);
            $this->assertGreaterThan(0, $forced['product_classes']);
        } finally {
            $this->cleanup();
        }
    }

    private function makeDocumentWithVocabularyPayload(): EpcisDocument
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
                <attribute id="urn:epcglobal:cbv:mda#manufacturerOfTradeItemPartyName">Xttrium Laboratories, Inc.</attribute>
                <attribute id="urn:epcglobal:cbv:mda#regulatedProductName">PROMETHAZINE HYDROCHLORIDE</attribute>
                <attribute id="urn:epcglobal:cbv:mda#dosageFormType">SYRUP</attribute>
                <attribute id="urn:epcglobal:cbv:mda#strengthDescription">6.25mg/5mL</attribute>
              </VocabularyElement>
            </VocabularyElementList>
          </Vocabulary>
          <Vocabulary type="urn:epcglobal:epcis:vtype:Location">
            <VocabularyElementList>
              <VocabularyElement id="urn:epc:id:sgln:030116.000000.0">
                <attribute id="urn:epcglobal:cbv:mda#name">Xttrium Laboratories, Inc.</attribute>
                <attribute id="urn:epcglobal:cbv:mda#city">MOUNT PROSPECT</attribute>
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

        $path = 'epcis/inbound/vocab-persist-'.(string) str()->uuid().'.xml';
        Storage::disk('local')->put($path, $xml);

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'vocab-persist.xml',
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

        tenancy()->end();
    }
}
