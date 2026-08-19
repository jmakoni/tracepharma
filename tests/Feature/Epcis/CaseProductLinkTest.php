<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Actions\Epcis\ResolveProductFromIdentifier;
use App\Enums\TenantProfile;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Gs1\Gtin;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CaseProductLinkTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    private ?int $productId = null;

    private ?string $caseEpcUri = null;

    private ?string $unitGtin = null;

    protected function tearDown(): void
    {
        ResolveProductFromIdentifier::clearCache();
        parent::tearDown();
    }

    #[Test]
    public function resolve_product_matches_case_gtin_to_unit_packaging_body(): void
    {
        $this->initializeDemo2Tenant();

        try {
            ResolveProductFromIdentifier::clearCache();

            $ids = $this->uniquePackagingIds();
            $this->unitGtin = $ids['unit_gtin'];

            $product = Product::query()->create([
                'gtin' => $ids['unit_gtin'],
                'name' => 'Case Link Unit Product '.uniqid(),
                'ndc11' => $ids['ndc11'],
                'is_active' => true,
            ]);
            $this->productId = (int) $product->getKey();

            $resolved = app(ResolveProductFromIdentifier::class)->handle(gtin14: $ids['case_gtin']);

            $this->assertNotNull($resolved);
            $this->assertSame($this->productId, (int) $resolved->getKey());
            $this->assertSame($ids['unit_gtin'], $resolved->gtin);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function ingest_links_case_sgtin_to_unit_product_across_indicator(): void
    {
        $this->initializeDemo2Tenant();

        try {
            ResolveProductFromIdentifier::clearCache();

            $ids = $this->uniquePackagingIds();
            $this->unitGtin = $ids['unit_gtin'];
            $this->caseEpcUri = $ids['case_uri'];

            $product = Product::query()->create([
                'gtin' => $ids['unit_gtin'],
                'name' => 'Case Link Vocab Product '.uniqid(),
                'ndc11' => $ids['ndc11'],
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
              <VocabularyElement id="urn:epc:idpat:sgtin:{$ids['gcp']}.0{$ids['item']}.*">
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentification">{$ids['ndc11']}</attribute>
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
                <attribute id="urn:epcglobal:cbv:mda#regulatedProductName">Case Link Test Product</attribute>
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
          <epc>{$ids['case_uri']}</epc>
        </epcList>
        <action>ADD</action>
        <bizStep>urn:epcglobal:cbv:bizstep:commissioning</bizStep>
        <disposition>urn:epcglobal:cbv:disp:active</disposition>
      </ObjectEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_case_');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'case_product_link.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $epc = Epc::query()->where('epc_uri', $ids['case_uri'])->first();
            $this->assertNotNull($epc);
            $this->assertSame(5, (int) $epc->indicator_digit);
            $this->assertSame($ids['item'], $epc->item_reference);
            $this->assertSame($ids['case_gtin'], $epc->gtin14);
            $this->assertSame($this->productId, (int) $epc->product_id);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function upsert_does_not_overwrite_existing_product_id_with_null(): void
    {
        $this->initializeDemo2Tenant();

        try {
            ResolveProductFromIdentifier::clearCache();

            $ids = $this->uniquePackagingIds();
            $this->caseEpcUri = $ids['case_uri'];

            // Unrelated packaging body so case GTIN resolve misses → upsert row has null product_id.
            $otherItem = (string) random_int(100000, 999999);
            $product = Product::query()->create([
                'gtin' => $this->gtin14('0', '061414', $otherItem),
                'name' => 'Preserve Product Link '.uniqid(),
                'ndc11' => '9'.substr((string) random_int(1000000000, 9999999999), 0, 10),
                'is_active' => true,
            ]);
            $this->productId = (int) $product->getKey();
            $this->unitGtin = $product->gtin;

            $epc = Epc::fromUri($ids['case_uri']);
            $epc->product_id = $this->productId;
            $epc->save();

            $uuid = (string) str()->uuid();
            $xml = <<<XML
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
        <sbdh:InstanceIdentifier>{$uuid}</sbdh:InstanceIdentifier>
        <sbdh:Type>Events</sbdh:Type>
        <sbdh:CreationDateAndTime>2026-07-15T20:15:49.056Z</sbdh:CreationDateAndTime>
      </sbdh:DocumentIdentification>
    </sbdh:StandardBusinessDocumentHeader>
  </EPCISHeader>
  <EPCISBody>
    <EventList>
      <ObjectEvent>
        <eventTime>2026-06-18T23:27:32.897Z</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <epcList>
          <epc>{$ids['case_uri']}</epc>
        </epcList>
        <action>OBSERVE</action>
        <bizStep>urn:epcglobal:cbv:bizstep:shipping</bizStep>
        <disposition>urn:epcglobal:cbv:disp:in_transit</disposition>
      </ObjectEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_preserve_');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'preserve_product_id.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $epc->refresh();
            $this->assertSame($this->productId, (int) $epc->product_id);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function upsert_fills_null_product_id_when_product_now_resolves(): void
    {
        $this->initializeDemo2Tenant();

        try {
            ResolveProductFromIdentifier::clearCache();

            $ids = $this->uniquePackagingIds();
            $this->caseEpcUri = $ids['case_uri'];
            $this->unitGtin = $ids['unit_gtin'];

            $epc = Epc::fromUri($ids['case_uri']);
            $epc->product_id = null;
            $epc->first_seen_at = now();
            $epc->save();
            $this->assertNull($epc->fresh()->product_id);

            $product = Product::query()->create([
                'gtin' => $ids['unit_gtin'],
                'name' => 'Fill Null Product Link '.uniqid(),
                'ndc11' => $ids['ndc11'],
                'is_active' => true,
            ]);
            $this->productId = (int) $product->getKey();

            $uuid = (string) str()->uuid();
            $xml = <<<XML
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
        <sbdh:InstanceIdentifier>{$uuid}</sbdh:InstanceIdentifier>
        <sbdh:Type>Events</sbdh:Type>
        <sbdh:CreationDateAndTime>2026-07-15T20:15:49.056Z</sbdh:CreationDateAndTime>
      </sbdh:DocumentIdentification>
    </sbdh:StandardBusinessDocumentHeader>
  </EPCISHeader>
  <EPCISBody>
    <EventList>
      <ObjectEvent>
        <eventTime>2026-06-18T23:27:32.897Z</eventTime>
        <eventTimeZoneOffset>-05:00</eventTimeZoneOffset>
        <epcList>
          <epc>{$ids['case_uri']}</epc>
        </epcList>
        <action>OBSERVE</action>
        <bizStep>urn:epcglobal:cbv:bizstep:shipping</bizStep>
        <disposition>urn:epcglobal:cbv:disp:in_transit</disposition>
      </ObjectEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_fill_');
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, $xml);

            $document = app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'fill_null_product_id.xml',
            ]);
            $this->documentId = (int) $document->getKey();

            $epc->refresh();
            $this->assertSame($this->productId, (int) $epc->product_id);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function resolve_product_cache_clear_allows_hit_after_miss(): void
    {
        $this->initializeDemo2Tenant();

        try {
            ResolveProductFromIdentifier::clearCache();

            $ids = $this->uniquePackagingIds();
            $this->unitGtin = $ids['unit_gtin'];
            $resolver = app(ResolveProductFromIdentifier::class);

            $this->assertNull($resolver->handle(gtin14: $ids['unit_gtin']));

            $product = Product::query()->create([
                'gtin' => $ids['unit_gtin'],
                'name' => 'Cache Clear Product '.uniqid(),
                'ndc11' => $ids['ndc11'],
                'is_active' => true,
            ]);
            $this->productId = (int) $product->getKey();

            // Negative cache still returns null until cleared.
            $this->assertNull($resolver->handle(gtin14: $ids['unit_gtin']));

            ResolveProductFromIdentifier::clearCache();
            $resolved = $resolver->handle(gtin14: $ids['unit_gtin']);

            $this->assertNotNull($resolved);
            $this->assertSame($this->productId, (int) $resolved->getKey());
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{gcp: string, item: string, ndc11: string, unit_gtin: string, case_gtin: string, case_uri: string}
     */
    private function uniquePackagingIds(): array
    {
        $suffix = (string) random_int(10000000, 99999999);
        $gcp = '061414';
        $item = substr($suffix, 0, 6);
        $serial = 'CL'.$suffix;
        $ndc11 = '88'.substr($suffix, 0, 9);

        return [
            'gcp' => $gcp,
            'item' => $item,
            'ndc11' => $ndc11,
            'unit_gtin' => $this->gtin14('0', $gcp, $item),
            'case_gtin' => $this->gtin14('5', $gcp, $item),
            'case_uri' => "urn:epc:id:sgtin:{$gcp}.5{$item}.{$serial}",
        ];
    }

    private function gtin14(string $indicator, string $gcp, string $item): string
    {
        $body13 = $indicator.$gcp.$item;

        return $body13.Gtin::checkDigit($body13);
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
            $this->documentId = null;
        }

        if ($this->caseEpcUri !== null) {
            Epc::query()->where('epc_uri', $this->caseEpcUri)->delete();
            $this->caseEpcUri = null;
        }

        if ($this->productId !== null) {
            Product::query()->whereKey($this->productId)->delete();
            $this->productId = null;
        }

        if ($this->unitGtin !== null) {
            Product::query()->where('gtin', $this->unitGtin)->delete();
            $this->unitGtin = null;
        }

        tenancy()->end();
    }
}
