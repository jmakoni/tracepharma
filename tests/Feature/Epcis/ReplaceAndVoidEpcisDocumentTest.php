<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ReplaceEpcisDocumentPayload;
use App\Actions\Epcis\VoidEpcisDocument;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Tenant;
use DomainException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReplaceAndVoidEpcisDocumentTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function void_marks_error_document_voided(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $this->assertTrue(Schema::hasTable('epcis_documents'));

            $document = $this->makeErrorDocument();
            $voided = app(VoidEpcisDocument::class)->handle($document, 'Operator discarded bad file');

            $this->assertSame('voided', $voided->status);
            $this->assertStringContainsString('Operator discarded bad file', (string) $voided->error_message);

            // Idempotent when already voided.
            $again = app(VoidEpcisDocument::class)->handle($voided);
            $this->assertSame('voided', $again->status);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function void_rejects_non_error_status(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->makeErrorDocument(['status' => 'validated']);

            try {
                app(VoidEpcisDocument::class)->handle($document);
                $this->fail('Expected DomainException');
            } catch (DomainException $e) {
                $this->assertStringContainsString('can only be voided from status [error]', $e->getMessage());
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function replace_payload_updates_hash_and_reprocesses(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $fixture = base_path('tests/Fixtures/epcis/commissioning_sscc_missing_locations.xml');
            $this->assertFileExists($fixture);

            $document = $this->makeErrorDocument([
                'payload_path' => 'epcis/inbound/replace-test-old.xml',
                'file_sha256' => hash('sha256', 'old-bytes'),
            ]);
            Storage::disk('local')->put($document->payload_path, '<old/>');

            $tmp = tempnam(sys_get_temp_dir(), 'epcis_fix_');
            $this->assertNotFalse($tmp);
            $xml = file_get_contents($fixture);
            $this->assertNotFalse($xml);
            $xml = str_replace('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', (string) str()->uuid(), $xml);
            file_put_contents($tmp, $xml);

            $updated = app(ReplaceEpcisDocumentPayload::class)->handle($document, $tmp, [
                'original_filename' => 'corrected_commissioning.xml',
                'sync' => true,
            ]);

            $this->assertSame('corrected_commissioning.xml', $updated->original_filename);
            $this->assertNotSame(hash('sha256', 'old-bytes'), $updated->file_sha256);
            $this->assertSame(hash_file('sha256', $tmp), $updated->file_sha256);
            $this->assertGreaterThan(0, (int) $updated->reprocess_count);
            // Fixture is intentionally invalid (missing locations) → remains error after validate.
            $this->assertSame('error', $updated->status);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeErrorDocument(array $attributes = []): EpcisDocument
    {
        $path = 'epcis/inbound/void-replace-'.(string) str()->uuid().'.xml';
        Storage::disk('local')->put($path, '<epcis/>');

        $document = EpcisDocument::query()->create(array_merge([
            'document_uuid' => (string) str()->uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => 'bad.xml',
            'file_sha256' => hash('sha256', (string) str()->uuid()),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'dscsa_affirm' => false,
            'status' => 'error',
            'error_message' => 'test error',
            'event_count' => 0,
            'epc_count' => 0,
            'received_at' => now(),
            'ingest_generation' => 1,
            'reprocess_count' => 0,
        ], $attributes));

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
            ]);
            self::$demo2TenantReady = true;
        }

        tenancy()->initialize($tenant);

        return $tenant;
    }

    private function cleanup(): void
    {
        if ($this->documentIds !== []) {
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if (tenancy()->initialized) {
            tenancy()->end();
        }
    }
}
