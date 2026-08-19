<?php

namespace Tests\Feature\Epcis;

use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use App\Support\Epcis\EpcisDocumentXmlDownload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EpcisDocumentXmlDownloadTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private static bool $demo2TenantReady = false;

    private ?int $documentId = null;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            if ($this->documentId !== null) {
                $doc = EpcisDocument::query()->find($this->documentId);
                if ($doc !== null && filled($doc->payload_path)) {
                    Storage::disk($doc->payloadFilesystemDisk())->delete((string) $doc->payload_path);
                }
                EpcisDocument::query()->whereKey($this->documentId)->delete();
                $this->documentId = null;
            }
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function download_xml_streams_stored_payload(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $path = 'epcis/outbound/test-download-'.uniqid('', true).'.xml';
            $xml = '<?xml version="1.0" encoding="UTF-8"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1">test</epcis:EPCISDocument>';

            Storage::disk('local')->put($path, $xml);

            $document = EpcisDocument::query()->create([
                'document_uuid' => (string) Str::uuid(),
                'schema_version' => '1.2',
                'creation_date' => now(),
                'direction' => 'outbound',
                'format' => 'xml',
                'original_filename' => 'ship-test.xml',
                'payload_disk' => 'local',
                'payload_path' => $path,
                'status' => 'parsed',
                'event_count' => 0,
                'epc_count' => 0,
                'received_at' => now(),
            ]);
            $this->documentId = (int) $document->getKey();

            $this->assertTrue(EpcisDocumentXmlDownload::available($document->fresh()));
            $this->assertSame('ship-test.xml', EpcisDocumentXmlDownload::filename($document));

            $response = EpcisDocumentXmlDownload::response($document->fresh());
            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('ship-test.xml', (string) $response->headers->get('content-disposition'));
            $this->assertStringContainsString('xml', (string) $response->headers->get('content-type'));
        } finally {
            if (tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    private function initializeDemo2Tenant(): Tenant
    {
        $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);

        if ($tenant === null) {
            $tenant = Tenant::withoutEvents(fn () => Tenant::query()->create([
                'id' => self::DEMO2_TENANT_ID,
                'name' => 'Demo Distributor',
                'profile' => TenantProfile::DrugWholesaler,
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
}
