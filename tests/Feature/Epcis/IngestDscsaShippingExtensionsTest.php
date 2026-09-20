<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\EpcisGuideline;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IngestDscsaShippingExtensionsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    #[Test]
    public function it_persists_direct_purchase_columns_from_shipping_extension(): void
    {
        $this->initializeDemo2Tenant();

        if (! Schema::hasColumn('epcis_documents', 'direct_purchase_statement')) {
            $this->markTestSkipped('DSCSA shipping extension columns are not migrated.');
        }

        try {
            $document = $this->ingestFixture('shipping_direct_purchase_entirely_direct.xml');
            $this->documentId = (int) $document->getKey();

            $document->refresh();

            $this->assertSame('ENTIRELY_DIRECT', $document->direct_purchase_qualifier);
            $this->assertSame(EpcisGuideline::R13, $document->dscsa_guideline_release);
            $this->assertStringContainsString(
                'purchased directly from the manufacturer',
                (string) $document->direct_purchase_statement,
            );
            $this->assertNull($document->received_prev_wholesaler_statement);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_persists_mixed_direct_and_prev_wholesaler_statements(): void
    {
        $this->initializeDemo2Tenant();

        if (! Schema::hasColumn('epcis_documents', 'received_prev_wholesaler_statement')) {
            $this->markTestSkipped('DSCSA shipping extension columns are not migrated.');
        }

        try {
            $document = $this->ingestFixture('shipping_mixed_direct_indirect.xml');
            $this->documentId = (int) $document->getKey();

            $document->refresh();

            $this->assertSame('PARTIALLY_DIRECT', $document->direct_purchase_qualifier);
            $this->assertStringContainsString('direct purchase', strtolower((string) $document->direct_purchase_statement));
            $this->assertSame('PARTIALLY_DIRECT', $document->received_prev_wholesaler_qualifier);
            $this->assertStringContainsString(
                'previous wholesaler distributor',
                (string) $document->received_prev_wholesaler_statement,
            );
            $this->assertNotEmpty($document->direct_purchase_indirect_epc_uris);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function r12_boolean_direct_purchase_does_not_store_entirely_direct_qualifier(): void
    {
        $this->initializeDemo2Tenant();

        if (! Schema::hasColumn('epcis_documents', 'direct_purchase_qualifier')) {
            $this->markTestSkipped('DSCSA shipping extension columns are not migrated.');
        }

        try {
            $document = $this->ingestBooleanDirectPurchaseFixture();
            $this->documentId = (int) $document->getKey();

            $document->refresh();

            $this->assertNull($document->direct_purchase_qualifier);
            $this->assertSame(EpcisGuideline::R12, $document->dscsa_guideline_release);
            $stored = (string) Storage::disk($document->payload_disk)->get($document->payload_path);
            $this->assertStringContainsString('<gs1ushc:directPurchase>true</gs1ushc:directPurchase>', $stored);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function ingest_persists_r12_on_fda_ndc_11_file_without_rewriting_payload(): void
    {
        $this->initializeDemo2Tenant();

        if (! Schema::hasColumn('epcis_documents', 'dscsa_guideline_release')) {
            $this->markTestSkipped('DSCSA guideline release column is not migrated.');
        }

        try {
            $document = $this->ingestFixture('minimal_with_shipping_refs.xml');
            $this->documentId = (int) $document->getKey();
            $document->refresh();

            $this->assertSame(EpcisGuideline::R12, $document->dscsa_guideline_release);
            $stored = (string) Storage::disk($document->payload_disk)->get($document->payload_path);
            $this->assertSame((string) $document->file_sha256, hash('sha256', $stored));
            $this->assertStringNotContainsString('guidelineVersion', $stored);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function it_parses_gs1ushc_direct_purchase_as_objectevent_sibling(): void
    {
        $this->initializeDemo2Tenant();

        if (! Schema::hasColumn('epcis_documents', 'direct_purchase_statement')) {
            $this->markTestSkipped('DSCSA shipping extension columns are not migrated.');
        }

        try {
            $document = $this->ingestSiblingDirectPurchaseFixture();
            $this->documentId = (int) $document->getKey();
            $document->refresh();

            $this->assertSame('ENTIRELY_DIRECT', $document->direct_purchase_qualifier);
            $this->assertStringContainsString(
                'purchased directly from the manufacturer',
                (string) $document->direct_purchase_statement,
            );
        } finally {
            $this->cleanup();
        }
    }

    private function ingestBooleanDirectPurchaseFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/shipping_direct_purchase_entirely_direct.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_dp_bool_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('22222222-3333-4444-5555-666666666666', (string) str()->uuid(), $xml);
        $xml = (string) preg_replace(
            '/<gs1ushc:directPurchase qualifier="ENTIRELY_DIRECT">.*?<\/gs1ushc:directPurchase>/s',
            '<gs1ushc:directPurchase>true</gs1ushc:directPurchase>',
            $xml,
            1,
        );
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'shipping_direct_purchase_boolean.xml',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function ingestSiblingDirectPurchaseFixture(): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/shipping_direct_purchase_entirely_direct.xml');
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_dp_sib_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $xml = str_replace('22222222-3333-4444-5555-666666666666', (string) str()->uuid(), $xml);
        $purchase = <<<'XML'
          <gs1ushc:directPurchase qualifier="ENTIRELY_DIRECT">
            <gs1ushc:directPurchaseStatement>Cardinal Health affirms that indicated product(s) were purchased directly from the manufacturer, exclusive distributor of the manufacturer, or a repackager who purchased directly unless noted as being indirectly sourced.</gs1ushc:directPurchaseStatement>
          </gs1ushc:directPurchase>
XML;
        $this->assertStringContainsString($purchase, $xml);
        $xml = str_replace($purchase, '', $xml);
        $xml = str_replace(
            "        </extension>\n      </ObjectEvent>",
            "        </extension>\n".$purchase.'      </ObjectEvent>',
            $xml,
        );
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'shipping_direct_purchase_sibling.xml',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    private function ingestFixture(string $name): EpcisDocument
    {
        $fixture = base_path('tests/Fixtures/epcis/'.$name);
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_');
        $this->assertNotFalse($tmp);
        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace('22222222-3333-4444-5555-666666666666', $uuid, $xml);
        file_put_contents($tmp, $xml);

        try {
            return app(IngestEpcisXmlDocument::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => $name,
            ]);
        } finally {
            @unlink($tmp);
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
        if (! tenancy()->initialized || $this->documentId === null) {
            return;
        }

        EpcisDocument::query()->whereKey($this->documentId)->delete();
        $this->documentId = null;
    }
}
