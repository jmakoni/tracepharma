<?php

declare(strict_types=1);

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Support\Epcis\EpcisSchemaVersion;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReceiveEpcisUploadVersionGateTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function rejects_json_2_0_when_accept_20_is_off(): void
    {
        config(['tracepharma.epcis.accept_20' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EPCIS 2.0 JSON-LD uploads are disabled');

        app(ReceiveEpcisUpload::class)->handle(
            base_path('tests/Fixtures/epcis/minimal_object_packing_2.0.json'),
            [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_packing_2.0.json',
                'dispatch' => false,
            ],
        );
    }

    #[Test]
    public function rejects_xml_schema_version_2_0_when_flag_off(): void
    {
        config(['tracepharma.epcis.accept_20' => false]);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis20xml_');
        $this->assertNotFalse($tmp);
        $dest = $tmp.'.xml';
        rename($tmp, $dest);
        file_put_contents($dest, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="2.0" creationDate="2026-01-01T00:00:00Z">
  <EPCISBody><EventList></EventList></EPCISBody>
</epcis:EPCISDocument>
XML);

        try {
            $this->expectException(\InvalidArgumentException::class);

            app(ReceiveEpcisUpload::class)->handle($dest, [
                'direction' => 'inbound',
                'original_filename' => 'epcis-2.0.xml',
                'dispatch' => false,
            ]);
        } finally {
            @unlink($dest);
        }
    }

    #[Test]
    public function accepts_xml_schema_version_2_0_when_flag_on(): void
    {
        $this->initializeDemo2Tenant();
        config(['tracepharma.epcis.accept_20' => true]);

        try {
            $tmp = tempnam(sys_get_temp_dir(), 'epcis20xmlok_');
            $this->assertNotFalse($tmp);
            $dest = $tmp.'.xml';
            rename($tmp, $dest);
            $xml = file_get_contents(base_path('tests/Fixtures/epcis/minimal_object_shipping.xml'));
            $this->assertNotFalse($xml);
            $xml = str_replace('schemaVersion="1.2"', 'schemaVersion="2.0"', $xml);
            $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
            file_put_contents($dest, $xml);

            $document = app(ReceiveEpcisUpload::class)->handle($dest, [
                'direction' => 'inbound',
                'original_filename' => 'epcis-2.0.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $this->assertSame(EpcisSchemaVersion::V20, $document->schema_version);
            $this->assertSame(EpcisSchemaVersion::FORMAT_XML, $document->format);

            @unlink($dest);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function accepts_json_2_0_when_flag_on(): void
    {
        $this->initializeDemo2Tenant();
        config(['tracepharma.epcis.accept_20' => true]);

        try {
            $tmp = $this->uniqueJsonFixture();
            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_packing_2.0.json',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $this->assertSame(EpcisSchemaVersion::V20, $document->schema_version);
            $this->assertSame(EpcisSchemaVersion::FORMAT_JSON, $document->format);
            $this->assertSame('received', $document->status);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function still_accepts_xml_1_3_when_flag_off(): void
    {
        $this->initializeDemo2Tenant();
        config(['tracepharma.epcis.accept_20' => false]);

        try {
            $tmp = tempnam(sys_get_temp_dir(), 'epcis13_');
            $this->assertNotFalse($tmp);
            $dest = $tmp.'.xml';
            rename($tmp, $dest);
            $xml = file_get_contents(base_path('tests/Fixtures/epcis/minimal_object_shipping_1.3.xml'));
            $this->assertNotFalse($xml);
            $xml = str_replace('11111111-2222-3333-4444-555555555555', (string) str()->uuid(), $xml);
            file_put_contents($dest, $xml);

            $document = app(ReceiveEpcisUpload::class)->handle($dest, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping_1.3.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $this->assertSame('1.3', $document->schema_version);
            $this->assertSame('xml', $document->format);

            @unlink($dest);
        } finally {
            $this->cleanup();
        }
    }

    private function uniqueJsonFixture(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'epcis20_');
        $this->assertNotFalse($tmp);
        $dest = $tmp.'.json';
        rename($tmp, $dest);
        $json = file_get_contents(base_path('tests/Fixtures/epcis/minimal_object_packing_2.0.json'));
        $this->assertNotFalse($json);
        $json = str_replace('22222222-3333-4444-5555-666666666666', (string) str()->uuid(), $json);
        file_put_contents($dest, $json);

        return $dest;
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

        $this->assertTrue(Schema::hasTable('epcis_documents'));

        return $tenant;
    }

    private function cleanup(): void
    {
        if (! tenancy()->initialized) {
            return;
        }

        foreach ($this->documentIds as $id) {
            EpcisDocument::query()->whereKey($id)->delete();
        }
        $this->documentIds = [];
        tenancy()->end();
    }
}
