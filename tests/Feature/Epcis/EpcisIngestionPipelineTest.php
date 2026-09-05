<?php

namespace Tests\Feature\Epcis;

use App\Actions\Epcis\ReceiveEpcisUpload;
use App\Actions\Epcis\ReprocessEpcisDocument;
use App\Actions\Epcis\ValidateEpcis12Document;
use App\Enums\TenantProfile;
use App\Exceptions\DuplicateEpcisUploadException;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Tenant;
use App\Services\Epcis\EpcisIngestionService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class EpcisIngestionPipelineTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function receive_creates_received_document_without_events_when_dispatch_false(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');

            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $this->assertSame('received', $document->status);
            $this->assertSame(0, (int) $document->event_count);
            $this->assertSame(0, EpcisEvent::query()->where('document_id', $document->id)->count());
            $this->assertNotNull($document->payload_path);
            $this->assertNotNull($document->file_sha256);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function process_parses_received_document_into_events(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');

            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $processed = app(EpcisIngestionService::class)->process($document);

            $this->assertSame('validated', $processed->status);
            $this->assertSame(3, $processed->event_count);
            $this->assertSame(2, $processed->epc_count);
            $this->assertNotNull($processed->processed_at);
            $this->assertNotNull($processed->last_processed_at);
            $this->assertNull($processed->error_message);
            $this->assertSame(3, EpcisEvent::query()->where('document_id', $processed->id)->count());

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function duplicate_sha256_is_rejected_when_status_is_not_error(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');

            $first = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $first->getKey();

            $this->expectException(DuplicateEpcisUploadException::class);

            try {
                app(ReceiveEpcisUpload::class)->handle($tmp, [
                    'direction' => 'inbound',
                    'original_filename' => 'minimal_object_shipping.xml',
                    'dispatch' => false,
                ]);
            } finally {
                @unlink($tmp);
            }
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function dispatch_failure_marks_document_error_and_allows_same_hash_retry(): void
    {
        $this->initializeDemo2Tenant();

        config([
            'tracepharma.epcis_jobs.enabled' => true,
            'queue.default' => 'redis',
        ]);

        Bus::shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('queue unavailable'));

        try {
            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $sha256 = hash_file('sha256', $tmp);
            $this->assertNotFalse($sha256);

            try {
                app(ReceiveEpcisUpload::class)->handle($tmp, [
                    'direction' => 'inbound',
                    'original_filename' => 'enqueue-fail.xml',
                    'dispatch' => true,
                    'sync' => false,
                ]);
                $this->fail('Expected enqueue failure to rethrow.');
            } catch (RuntimeException $e) {
                $this->assertSame('queue unavailable', $e->getMessage());
            }

            $document = EpcisDocument::query()
                ->where('file_sha256', $sha256)
                ->where('direction', 'inbound')
                ->latest('id')
                ->first();
            $this->assertNotNull($document);
            $this->documentIds[] = (int) $document->getKey();

            $this->assertSame('error', $document->status);
            $this->assertStringContainsString('queue unavailable', (string) $document->error_message);

            $retry = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'enqueue-fail-retry.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $retry->getKey();
            $this->assertNotSame((int) $document->getKey(), (int) $retry->getKey());
            $this->assertSame('received', $retry->status);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function reprocess_dispatch_failure_marks_document_error_not_received(): void
    {
        $this->initializeDemo2Tenant();

        config([
            'tracepharma.epcis_jobs.enabled' => true,
            'queue.default' => 'redis',
        ]);

        try {
            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'reprocess-enqueue-fail.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();
            $this->assertSame('received', $document->status);

            Bus::shouldReceive('dispatch')
                ->once()
                ->andThrow(new RuntimeException('queue unavailable'));

            try {
                app(ReprocessEpcisDocument::class)->handle(
                    $document,
                    sync: false,
                    force: true,
                    authorizeExceptionsRole: false,
                );
                $this->fail('Expected reprocess enqueue failure to rethrow.');
            } catch (RuntimeException $e) {
                $this->assertSame('queue unavailable', $e->getMessage());
            }

            $document->refresh();
            $this->assertSame('error', $document->status);
            $this->assertStringContainsString('queue unavailable', (string) $document->error_message);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function outbound_reprocess_dispatch_failure_marks_transmission_failed_and_restores_status(): void
    {
        $this->initializeDemo2Tenant();

        config([
            'tracepharma.epcis_jobs.enabled' => true,
            'queue.default' => 'redis',
        ]);

        try {
            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');
            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'outbound',
                'original_filename' => 'reprocess-outbound-enqueue-fail.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();
            $document->forceFill([
                'status' => 'validated',
                'transmission_status' => 'pending',
            ])->save();

            Bus::shouldReceive('dispatch')
                ->once()
                ->andThrow(new RuntimeException('queue unavailable'));

            try {
                app(ReprocessEpcisDocument::class)->handle(
                    $document,
                    sync: false,
                    force: true,
                    authorizeExceptionsRole: false,
                );
                $this->fail('Expected reprocess enqueue failure to rethrow.');
            } catch (RuntimeException $e) {
                $this->assertSame('queue unavailable', $e->getMessage());
            }

            $document->refresh();
            $this->assertSame('validated', $document->status);
            $this->assertSame('failed', $document->transmission_status);
            $this->assertStringContainsString('queue unavailable', (string) $document->error_message);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function duplicate_sha256_is_allowed_for_different_direction(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');

            $inbound = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_object_shipping.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $inbound->getKey();

            $outbound = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'outbound',
                'original_filename' => 'minimal_object_shipping.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $outbound->getKey();

            $this->assertSame($inbound->file_sha256, $outbound->file_sha256);
            $this->assertSame('inbound', $inbound->direction);
            $this->assertSame('outbound', $outbound->direction);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function reprocess_increments_count_and_reparses(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/minimal_with_shipping_refs.xml', '22222222-3333-4444-5555-666666666666');

            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'minimal_with_shipping_refs.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            app(EpcisIngestionService::class)->process($document);
            $document->refresh();

            $this->assertSame('validated', $document->status);
            $this->assertSame(0, (int) $document->reprocess_count);
            $firstEventCount = (int) $document->event_count;

            $reprocessed = app(ReprocessEpcisDocument::class)->handle($document, sync: true);

            $this->assertSame(1, (int) $reprocessed->reprocess_count);
            $this->assertSame('validated', $reprocessed->status);
            $this->assertSame($firstEventCount, (int) $reprocessed->event_count);
            $this->assertNotNull($reprocessed->last_processed_at);
            $this->assertGreaterThanOrEqual(2, (int) $reprocessed->ingest_generation);

            $priorGen = (int) $reprocessed->ingest_generation - 1;
            $priorEvents = EpcisEvent::query()
                ->where('document_id', $reprocessed->id)
                ->where('ingest_generation', $priorGen)
                ->get();
            $this->assertGreaterThanOrEqual($firstEventCount, $priorEvents->count());
            $this->assertTrue($priorEvents->every(fn (EpcisEvent $event): bool => $event->superseded_at !== null));

            $activeEvents = EpcisEvent::query()
                ->where('document_id', $reprocessed->id)
                ->where('ingest_generation', $reprocessed->ingest_generation)
                ->get();
            $this->assertSame($firstEventCount, $activeEvents->count());
            $this->assertTrue($activeEvents->every(fn (EpcisEvent $event): bool => $event->superseded_at === null));

            // RCA: superseded event PK still returns original bizStep / times / EPCs.
            $rca = $priorEvents->first();
            $this->assertNotNull($rca);
            $this->assertNotNull($rca->biz_step);
            $this->assertNotNull($rca->event_time);
            $this->assertGreaterThan(0, $rca->eventEpcs()->count());

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    #[Test]
    public function validation_failure_rolls_back_ingest_generation_pointer(): void
    {
        $this->initializeDemo2Tenant();

        try {
            [$tmp] = $this->uniqueFixture('tests/Fixtures/epcis/minimal_object_shipping.xml');

            $document = app(ReceiveEpcisUpload::class)->handle($tmp, [
                'direction' => 'inbound',
                'original_filename' => 'rollback-generation.xml',
                'dispatch' => false,
            ]);
            $this->documentIds[] = (int) $document->getKey();

            $document->forceFill([
                'status' => 'parsed',
                'ingest_generation' => 1,
                'processed_at' => now(),
            ])->save();

            $priorGeneration = (int) $document->ingest_generation;

            $this->app->bind(ValidateEpcis12Document::class, fn () => new class
            {
                public function handle(EpcisDocument $document, ?string $absolutePath = null): array
                {
                    $document->forceFill([
                        'status' => 'error',
                        'error_message' => 'Forced validation failure for rollback test.',
                    ])->save();

                    return [];
                }
            });

            app(EpcisIngestionService::class)->process($document->fresh());

            $document = $document->fresh();
            $this->assertSame('error', $document->status);
            $this->assertSame($priorGeneration, (int) $document->ingest_generation);

            @unlink($tmp);
        } finally {
            $this->cleanup();
        }
    }

    /**
     * @return array{0: string, 1: string} [tmp path, uuid]
     */
    private function uniqueFixture(string $relativePath, string $uuidPlaceholder = '11111111-2222-3333-4444-555555555555'): array
    {
        $fixture = base_path($relativePath);
        $this->assertFileExists($fixture);

        $tmp = tempnam(sys_get_temp_dir(), 'epcis_pipe_');
        $this->assertNotFalse($tmp);
        $xmlPath = $tmp.'.xml';
        rename($tmp, $xmlPath);

        $xml = file_get_contents($fixture);
        $this->assertNotFalse($xml);
        $uuid = (string) str()->uuid();
        $xml = str_replace($uuidPlaceholder, $uuid, $xml);
        file_put_contents($xmlPath, $xml);

        return [$xmlPath, $uuid];
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

        $this->assertTrue(Schema::hasColumn('epcis_documents', 'reprocess_count'));
        $this->assertTrue(Schema::hasColumn('epcis_documents', 'notes'));
        $this->assertTrue(Schema::hasColumn('epcis_documents', 'last_processed_at'));

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

        foreach ([
            'urn:epc:id:sgtin:030116.0200116.10000082001560',
            'urn:epc:id:sscc:030116.01001227052',
        ] as $uri) {
            $epc = Epc::query()->where('epc_uri', $uri)->first();
            if ($epc !== null && ! DB::table('event_epcs')->where('epc_id', $epc->id)->exists()) {
                $epc->delete();
            }
        }

        tenancy()->end();
    }
}
