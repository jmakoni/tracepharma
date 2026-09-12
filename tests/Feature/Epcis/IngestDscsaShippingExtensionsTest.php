<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\IngestEpcisXmlDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use Illuminate\Support\Facades\Schema;
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
