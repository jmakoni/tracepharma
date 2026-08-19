<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisDocumentProductClass;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Epcis\EpcisXmlReader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisVocabularyNdcTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $productId = null;

    #[Test]
    public function reader_extracts_product_classes_with_fda_ndc_11(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader" schemaVersion="1.2" creationDate="2026-07-15T20:15:49.056Z">
  <EPCISHeader>
    <sbdh:StandardBusinessDocumentHeader>
      <sbdh:HeaderVersion>1.0</sbdh:HeaderVersion>
      <sbdh:Sender><sbdh:Identifier Authority="GLN">0301160000009</sbdh:Identifier></sbdh:Sender>
      <sbdh:Receiver><sbdh:Identifier Authority="GLN">0096295000009</sbdh:Identifier></sbdh:Receiver>
      <sbdh:DocumentIdentification>
        <sbdh:Standard>EPCglobal</sbdh:Standard>
        <sbdh:TypeVersion>1.0</sbdh:TypeVersion>
        <sbdh:InstanceIdentifier>aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee</sbdh:InstanceIdentifier>
        <sbdh:Type>Events</sbdh:Type>
        <sbdh:CreationDateAndTime>2026-07-15T20:15:49.056Z</sbdh:CreationDateAndTime>
      </sbdh:DocumentIdentification>
    </sbdh:StandardBusinessDocumentHeader>
    <extension>
      <EPCISMasterData>
        <VocabularyList>
          <Vocabulary type="urn:epcglobal:epcis:vtype:EPCClass">
            <VocabularyElementList>
              <VocabularyElement id="urn:epc:idpat:sgtin:030116.0200116.*">
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentification">00116200116</attribute>
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
                <attribute id="urn:epcglobal:cbv:mda#regulatedProductName">Chlorhexidine Gluconate 0.12% Oral Rinse, USP</attribute>
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

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_vocab_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $xml);

        try {
            $parsed = app(EpcisXmlReader::class)->parse($tmp);

            $this->assertCount(1, $parsed['product_classes']);
            $productClass = $parsed['product_classes'][0];
            $this->assertSame('urn:epc:idpat:sgtin:030116.0200116.*', $productClass['idpat']);
            $this->assertSame('00116200116', $productClass['ndc11']);
            $this->assertSame('00116200116', $productClass['ndc_raw']);
            $this->assertStringContainsString('Chlorhexidine', (string) $productClass['name']);
            $this->assertArrayHasKey('dosage_form', $productClass);
            $this->assertArrayHasKey('strength', $productClass);
            $this->assertArrayHasKey('manufacturer', $productClass);
            $this->assertArrayHasKey('net_content', $productClass);
            $this->assertIsArray($productClass['attributes_json']);
            $this->assertNotEmpty($productClass['attributes_json']);
            $this->assertArrayHasKey(
                'urn:epcglobal:cbv:mda#additionalTradeItemIdentification',
                $productClass['attributes_json'],
            );
            $this->assertSame(
                '00116200116',
                $productClass['attributes_json']['urn:epcglobal:cbv:mda#additionalTradeItemIdentification'],
            );
        } finally {
            @unlink($tmp);
        }
    }

    #[Test]
    public function ingest_links_sgtin_epcs_to_product_via_vocabulary_ndc(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $product = Product::query()->create([
                'gtin' => '00301160200116',
                'name' => 'Vocab NDC Product '.uniqid(),
                'ndc11' => '00116200116',
                'is_active' => true,
            ]);
            $this->productId = (int) $product->getKey();

            $uuid = (string) str()->uuid();
            $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader" xmlns:cbvmda="urn:epcglobal:cbv:mda" schemaVersion="1.2" creationDate="2026-07-15T20:15:49.056Z">
  <EPCISHeader>
    <sbdh:StandardBusinessDocumentHeader>
      <sbdh:HeaderVersion>1.0</sbdh:HeaderVersion>
      <sbdh:Sender><sbdh:Identifier Authority="GLN">0301160000009</sbdh:Identifier></sbdh:Sender>
      <sbdh:Receiver><sbdh:Identifier Authority="GLN">0096295000009</sbdh:Identifier></sbdh:Receiver>
      <sbdh:DocumentIdentification>
        <sbdh:Standard>EPCglobal</sbdh:Standard>
        <sbdh:TypeVersion>1.0</sbdh:TypeVersion>
        <sbdh:InstanceIdentifier>{$uuid}</sbdh:InstanceIdentifier>
        <sbdh:Type>Events</sbdh:Type>
        <sbdh:CreationDateAndTime>2026-07-15T20:15:49.056Z</sbdh:CreationDateAndTime>
      </sbdh:DocumentIdentification>
    </sbdh:StandardBusinessDocumentHeader>
    <extension>
      <EPCISMasterData>
        <VocabularyList>
          <Vocabulary type="urn:epcglobal:epcis:vtype:EPCClass">
            <VocabularyElementList>
              <VocabularyElement id="urn:epc:idpat:sgtin:030116.0200116.*">
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentification">00116200116</attribute>
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
                <attribute id="urn:epcglobal:cbv:mda#regulatedProductName">Chlorhexidine Gluconate 0.12% Oral Rinse, USP</attribute>
              </VocabularyElement>
            </VocabularyElementList>
          </Vocabulary>
        </VocabularyList>
      </EPCISMasterData>
    </extension>
  </EPCISHeader>
  <EPCISBody>
    <EventList>
      <ObjectEvent>
        <eventTime>2026-06-18T23:27:32.897Z</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <epcList>
          <epc>urn:epc:id:sgtin:030116.0200116.VOCABNDC0001</epc>
        </epcList>
        <action>ADD</action>
        <bizStep>urn:epcglobal:cbv:bizstep:commissioning</bizStep>
        <disposition>urn:epcglobal:cbv:disp:active</disposition>
      </ObjectEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_ndc_');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'vocab_ndc.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $epc = Epc::query()->where('epc_uri', 'urn:epc:id:sgtin:030116.0200116.VOCABNDC0001')->first();
            $this->assertNotNull($epc);
            $this->assertSame($this->productId, (int) $epc->product_id);

            $class = EpcisDocumentProductClass::query()
                ->where('document_id', $document->getKey())
                ->where('ingest_generation', (int) $document->ingest_generation)
                ->where('idpat', 'urn:epc:idpat:sgtin:030116.0200116.*')
                ->first();
            $this->assertNotNull($class);
            $this->assertSame('00116200116', $class->ndc11);
            $this->assertSame('00116200116', $class->ndc_raw);
            $this->assertStringContainsString('Chlorhexidine', (string) $class->name);
            $this->assertSame('00301162001165', $class->gtin14);
            $this->assertIsArray($class->attributes_json);
            $this->assertNotEmpty($class->attributes_json);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
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

        if ($this->documentId !== null) {
            EpcisDocument::query()->whereKey($this->documentId)->delete();
        }

        $epc = Epc::query()->where('epc_uri', 'urn:epc:id:sgtin:030116.0200116.VOCABNDC0001')->first();
        if ($epc !== null) {
            $epc->delete();
        }

        if ($this->productId !== null) {
            Product::query()->whereKey($this->productId)->delete();
        }

        tenancy()->end();
    }
}
