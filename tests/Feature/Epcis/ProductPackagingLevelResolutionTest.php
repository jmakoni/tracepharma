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

/**
 * GTIN-14 carries the packaging level in its indicator digit. These tests pin the
 * rules that keep a case scan off the unit product and vice versa.
 */
class ProductPackagingLevelResolutionTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<string> */
    private array $epcUris = [];

    private ?int $documentId = null;

    protected function tearDown(): void
    {
        ResolveProductFromIdentifier::clearCache();
        parent::tearDown();
    }

    #[Test]
    public function exact_gtin_match_wins_over_a_conflicting_ndc_hint(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $ids = $this->uniquePackagingIds();

            $unit = $this->createProduct($ids['unit_gtin'], $ids['unit_ndc11'], 'Unit');
            $case = $this->createProduct($ids['case_gtin'], $ids['case_ndc11'], 'Case');

            ResolveProductFromIdentifier::clearCache();
            $resolver = app(ResolveProductFromIdentifier::class);

            // The NDC hint points at the unit, but the scan is unmistakably the case.
            $resolved = $resolver->handle(gtin14: $ids['case_gtin'], ndc11: $ids['unit_ndc11']);
            $this->assertNotNull($resolved);
            $this->assertSame((int) $case->getKey(), (int) $resolved->getKey());

            $resolved = $resolver->handle(gtin14: $ids['unit_gtin'], ndc11: $ids['case_ndc11']);
            $this->assertNotNull($resolved);
            $this->assertSame((int) $unit->getKey(), (int) $resolved->getKey());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_refuses_to_guess_when_both_packaging_levels_share_a_gtin_body(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $ids = $this->uniquePackagingIds();

            $this->createProduct($ids['unit_gtin'], $ids['unit_ndc11'], 'Unit');
            $this->createProduct($ids['case_gtin'], $ids['case_ndc11'], 'Case');

            ResolveProductFromIdentifier::clearCache();

            // A third indicator digit on the same company prefix + item reference:
            // matching on the body alone would arbitrarily pick the unit or the case.
            $palletGtin = $this->gtin14('4', $ids['gcp'], $ids['item']);

            $this->assertNull(app(ResolveProductFromIdentifier::class)->handle(gtin14: $palletGtin));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function the_ndc_hint_resolves_only_after_the_gtin_misses(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $ids = $this->uniquePackagingIds();
            $unit = $this->createProduct($ids['unit_gtin'], $ids['unit_ndc11'], 'Unit');

            ResolveProductFromIdentifier::clearCache();

            $unrelatedGtin = $this->gtin14('0', '061414', (string) random_int(100000, 999999));

            $resolved = app(ResolveProductFromIdentifier::class)
                ->handle(gtin14: $unrelatedGtin, ndc11: $ids['unit_ndc11']);

            $this->assertNotNull($resolved);
            $this->assertSame((int) $unit->getKey(), (int) $resolved->getKey());
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function vocabulary_linking_does_not_touch_epcs_outside_the_document(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $ids = $this->uniquePackagingIds();
            $product = $this->createProduct($ids['unit_gtin'], $ids['unit_ndc11'], 'Unit');

            // Tenant history: same company prefix + item reference, never in this document.
            $historicUri = "urn:epc:id:sgtin:{$ids['gcp']}.0{$ids['item']}.HIST".random_int(1000, 9999);
            $historic = Epc::fromUri($historicUri);
            $historic->product_id = null;
            $historic->first_seen_at = now();
            $historic->save();
            $this->epcUris[] = $historicUri;

            $documentUri = "urn:epc:id:sgtin:{$ids['gcp']}.0{$ids['item']}.DOC".random_int(1000, 9999);
            $this->epcUris[] = $documentUri;

            ResolveProductFromIdentifier::clearCache();

            $document = $this->ingest($this->vocabularyXml($ids, $documentUri));
            $this->documentId = (int) $document->getKey();

            $inDocument = Epc::query()->where('epc_uri', $documentUri)->first();
            $this->assertNotNull($inDocument);
            $this->assertSame((int) $product->getKey(), (int) $inDocument->product_id);

            $this->assertNull($historic->fresh()->product_id);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function vocabulary_linking_keeps_case_epcs_off_the_unit_product(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $ids = $this->uniquePackagingIds();
            $unit = $this->createProduct($ids['unit_gtin'], $ids['unit_ndc11'], 'Unit');
            $case = $this->createProduct($ids['case_gtin'], $ids['case_ndc11'], 'Case');

            $caseUri = "urn:epc:id:sgtin:{$ids['gcp']}.5{$ids['item']}.CASE".random_int(1000, 9999);
            $this->epcUris[] = $caseUri;

            ResolveProductFromIdentifier::clearCache();

            // Vocabulary declares the unit idpat while the event carries a case EPC.
            $document = $this->ingest($this->vocabularyXml($ids, $caseUri));
            $this->documentId = (int) $document->getKey();

            $epc = Epc::query()->where('epc_uri', $caseUri)->first();
            $this->assertNotNull($epc);
            $this->assertSame(5, (int) $epc->indicator_digit);
            $this->assertNotSame((int) $unit->getKey(), (int) $epc->product_id);
            $this->assertSame((int) $case->getKey(), (int) $epc->product_id);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @param  array{gcp: string, item: string, unit_ndc11: string}  $ids
     */
    private function vocabularyXml(array $ids, string $epcUri): string
    {
        $uuid = (string) str()->uuid();

        return <<<XML
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
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentification">{$ids['unit_ndc11']}</attribute>
                <attribute id="urn:epcglobal:cbv:mda#additionalTradeItemIdentificationTypeCode">FDA_NDC_11</attribute>
                <attribute id="urn:epcglobal:cbv:mda#regulatedProductName">Packaging Level Test Product</attribute>
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
          <epc>{$epcUri}</epc>
        </epcList>
        <action>ADD</action>
        <bizStep>urn:epcglobal:cbv:bizstep:commissioning</bizStep>
        <disposition>urn:epcglobal:cbv:disp:active</disposition>
      </ObjectEvent>
    </EventList>
  </EPCISBody>
</epcis:EPCISDocument>
XML;
    }

    private function ingest(string $xml): EpcisDocument
    {
        $tmp = tempnam(sys_get_temp_dir(), 'epcis_pkg_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'packaging_level.xml',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function createProduct(string $gtin, string $ndc11, string $label): Product
    {
        $product = Product::query()->create([
            'gtin' => $gtin,
            'name' => 'Packaging Level '.$label.' '.uniqid(),
            'ndc11' => $ndc11,
            'is_active' => true,
        ]);

        $this->productIds[] = (int) $product->getKey();

        return $product;
    }

    /**
     * @return array{gcp: string, item: string, unit_ndc11: string, case_ndc11: string, unit_gtin: string, case_gtin: string}
     */
    private function uniquePackagingIds(): array
    {
        $suffix = (string) random_int(10000000, 99999999);
        $gcp = '061414';
        $item = substr($suffix, 0, 6);

        return [
            'gcp' => $gcp,
            'item' => $item,
            'unit_ndc11' => '77'.substr($suffix, 0, 9),
            'case_ndc11' => '78'.substr($suffix, 0, 9),
            'unit_gtin' => $this->gtin14('0', $gcp, $item),
            'case_gtin' => $this->gtin14('5', $gcp, $item),
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

        if ($this->epcUris !== []) {
            Epc::query()->whereIn('epc_uri', $this->epcUris)->delete();
            $this->epcUris = [];
        }

        if ($this->productIds !== []) {
            Product::query()->whereIn('id', $this->productIds)->delete();
            $this->productIds = [];
        }

        tenancy()->end();
    }
}
