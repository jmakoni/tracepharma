<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\PurgeTestEpcisDocuments;
use App\Enums\InboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\InboundConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * demo2 is a shared tenant database that may already contain genuine leaked
 * test artifacts from prior manual testing (webhook-test.xml, inbound-*.xml,
 * "Webhook Test" / "Cardinal HTTPS" connections). These tests intentionally
 * assert against specific document/connection ids they create rather than
 * aggregate totals, since a purge run here will also legitimately clean up
 * any pre-existing leaked rows.
 */
class PurgeTestEpcisDocumentsTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $connectionIds = [];

    #[Test]
    public function dry_run_reports_matches_without_deleting_anything(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $testDoc = $this->makeDocument('webhook-test.xml');
            $realDoc = $this->makeDocument('ou_xttrium_prod_dc_systechcitadel_dc_com_2026_07_15-processed_data.xml');

            $result = app(PurgeTestEpcisDocuments::class)->handle(dryRun: true);

            $this->assertTrue($result['dry_run']);
            $this->assertGreaterThanOrEqual(1, $result['documents_deleted']);
            $this->assertSame(count($result['dry_run_documents']), $result['documents_deleted']);

            $matchedIds = array_column($result['dry_run_documents'], 'id');
            $this->assertContains($testDoc->id, $matchedIds);
            $this->assertNotContains($realDoc->id, $matchedIds);

            $this->assertNotNull(EpcisDocument::query()->find($testDoc->id));
            $this->assertNotNull(EpcisDocument::query()->find($realDoc->id));
            $this->assertTrue(Storage::disk('local')->exists($testDoc->payload_path));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function purge_deletes_test_webhook_document_and_leaves_real_partner_document(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $testDoc = $this->makeDocument('webhook-test.xml');
            $realDoc = $this->makeDocument('ou_xttrium_prod_dc_systechcitadel_dc_com_2026_07_15-processed_data.xml');

            $connection = InboundConnection::query()->create([
                'name' => 'Webhook Test',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => InboundTransport::Https,
                'is_active' => true,
            ]);
            $this->connectionIds[] = (int) $connection->id;
            $testDoc->forceFill(['inbound_connection_id' => $connection->id])->save();

            $result = app(PurgeTestEpcisDocuments::class)->handle();

            $this->assertFalse($result['dry_run']);
            $this->assertGreaterThanOrEqual(1, $result['documents_deleted']);
            $this->assertGreaterThanOrEqual(1, $result['connections_deleted']);

            $this->assertNull(EpcisDocument::query()->find($testDoc->id));
            $this->assertFalse(Storage::disk('local')->exists($testDoc->payload_path));

            $this->assertNotNull(EpcisDocument::query()->find($realDoc->id));
            $this->assertTrue(Storage::disk('local')->exists($realDoc->payload_path));

            $this->assertNull(InboundConnection::query()->find($connection->id));

            $this->documentIds = array_values(array_filter(
                $this->documentIds,
                fn (int $id): bool => $id !== (int) $testDoc->id,
            ));
            $this->connectionIds = [];
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function purge_matches_inbound_timestamp_and_resource_test_filenames(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $timestampDoc = $this->makeDocument('inbound-20260807234810.xml');
            $resourceTestDoc = $this->makeDocument('inbound-resource-test-Ab3dF9.xml');
            $realDoc = $this->makeDocument('abc-epcis-1_2-sample_nov22.xml');

            $result = app(PurgeTestEpcisDocuments::class)->handle();

            $this->assertNull(EpcisDocument::query()->find($timestampDoc->id));
            $this->assertNull(EpcisDocument::query()->find($resourceTestDoc->id));
            $this->assertNotNull(EpcisDocument::query()->find($realDoc->id));

            $this->documentIds = array_values(array_filter(
                $this->documentIds,
                fn (int $id): bool => ! in_array($id, [(int) $timestampDoc->id, (int) $resourceTestDoc->id], true),
            ));
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function purge_command_requires_force_or_dry_run(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->artisan('tracepharma:purge-test-epcis', [
                '--tenants' => [self::DEMO2_TENANT_ID],
            ])->assertFailed();
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function purge_command_deletes_with_force(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $testDoc = $this->makeDocument('webhook-test.xml');

            $this->artisan('tracepharma:purge-test-epcis', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            $this->assertNull(EpcisDocument::query()->find($testDoc->id));

            $this->documentIds = array_values(array_filter(
                $this->documentIds,
                fn (int $id): bool => $id !== (int) $testDoc->id,
            ));
        } finally {
            $this->cleanup();
        }
    }

    private function makeDocument(string $filename, string $status = 'validated'): EpcisDocument
    {
        $path = 'epcis/inbound/purge-test-'.(string) str()->uuid().'.xml';
        Storage::disk('local')->put($path, '<epcis/>');

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => $filename,
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'dscsa_affirm' => false,
            'status' => $status,
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

        foreach ($this->documentIds as $id) {
            $document = EpcisDocument::query()->find($id);
            if ($document !== null) {
                if (filled($document->payload_path)) {
                    Storage::disk($document->payloadFilesystemDisk())->delete($document->payload_path);
                }
                $document->delete();
            }
        }
        $this->documentIds = [];

        foreach ($this->connectionIds as $id) {
            InboundConnection::query()->find($id)?->delete();
        }
        $this->connectionIds = [];

        tenancy()->end();
    }
}
