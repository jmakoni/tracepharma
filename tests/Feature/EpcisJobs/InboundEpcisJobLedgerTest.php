<?php

declare(strict_types=1);

namespace Tests\Feature\EpcisJobs;

use App\Actions\EpcisJobs\CancelEpcisJob;
use App\Actions\EpcisJobs\EnqueueInboundEpcisJob;
use App\Actions\EpcisJobs\ForceFailEpcisJob;
use App\Actions\EpcisJobs\RequeueEpcisJob;
use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Jobs\ProcessEpcisDocumentJob;
use App\Models\Epcis\EpcisDocument;
use App\Models\EpcisJob;
use App\Models\Tenant;
use App\Services\Epcis\EpcisIngestionService;
use App\Support\EpcisJobs\SyncInboundEpcisJobFromDocument;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class InboundEpcisJobLedgerTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    /** @var list<int> */
    private array $jobIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tracepharma.epcis_jobs.enabled' => true,
            'tracepharma.epcis_jobs.queue' => 'epcis',
            'queue.default' => 'sync',
        ]);
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            if ($this->jobIds !== []) {
                EpcisJob::query()->whereIn('id', $this->jobIds)->each(function (EpcisJob $job): void {
                    $job->messages()->delete();
                    $job->delete();
                });
            }
            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function enqueue_creates_inbound_ledger_and_processes_sync(): void
    {
        $this->initializeDemo2();
        [$document] = $this->seedInboundDocument();

        $job = app(EnqueueInboundEpcisJob::class)->handle($document, sync: true);
        $this->jobIds[] = (int) $job->getKey();

        $job = $job->fresh();
        $this->assertNotNull($job);
        $this->assertSame(EpcisJobKind::InboundProcess, $job->kind);
        $this->assertContains($job->status, [EpcisJobStatus::Complete, EpcisJobStatus::Error]);
        $this->assertTrue($job->messages()->exists());
        $this->assertNotSame('received', $document->fresh()->status);
    }

    #[Test]
    public function double_enqueue_same_inbound_document_returns_one_job(): void
    {
        Bus::fake([ProcessEpcisDocumentJob::class]);
        config(['queue.default' => 'redis']);

        $this->initializeDemo2();
        [$document] = $this->seedInboundDocument();

        $first = app(EnqueueInboundEpcisJob::class)->handle($document, sync: false);
        $second = app(EnqueueInboundEpcisJob::class)->handle($document, sync: false);
        $this->jobIds[] = (int) $first->getKey();

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, EpcisJob::query()
            ->where('epcis_document_id', $document->getKey())
            ->where('kind', EpcisJobKind::InboundProcess->value)
            ->count());
        Bus::assertDispatchedTimes(ProcessEpcisDocumentJob::class, 1);
    }

    #[Test]
    public function stale_queued_inbound_job_is_redispatched_on_repeat_enqueue(): void
    {
        Bus::fake([ProcessEpcisDocumentJob::class]);
        config(['queue.default' => 'redis']);

        $this->initializeDemo2();
        [$document] = $this->seedInboundDocument();

        $job = app(EnqueueInboundEpcisJob::class)->handle($document, sync: false);
        $this->jobIds[] = (int) $job->getKey();

        $job->forceFill([
            'received_at' => now()->subMinutes(20),
        ])->save();

        Bus::assertDispatchedTimes(ProcessEpcisDocumentJob::class, 1);

        $again = app(EnqueueInboundEpcisJob::class)->handle($document, sync: false);

        $this->assertSame($job->getKey(), $again->getKey());
        Bus::assertDispatchedTimes(ProcessEpcisDocumentJob::class, 2);
    }

    #[Test]
    public function enqueue_dispatch_failure_marks_job_error_not_orphaned_queued(): void
    {
        config(['queue.default' => 'redis']);

        $this->initializeDemo2();
        [$document] = $this->seedInboundDocument();

        Bus::shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('queue unavailable'));

        try {
            app(EnqueueInboundEpcisJob::class)->handle($document, sync: false);
            $this->fail('Expected dispatch failure to rethrow.');
        } catch (RuntimeException $e) {
            $this->assertSame('queue unavailable', $e->getMessage());
        }

        $job = EpcisJob::query()
            ->where('epcis_document_id', $document->getKey())
            ->where('kind', EpcisJobKind::InboundProcess->value)
            ->first();

        $this->assertNotNull($job);
        $this->jobIds[] = (int) $job->getKey();
        $this->assertSame(EpcisJobStatus::Error, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertStringContainsString('queue unavailable', (string) $job->error_message);
    }

    #[Test]
    public function flag_off_skips_inbound_ledger_on_enqueue_path(): void
    {
        config(['tracepharma.epcis_jobs.enabled' => false]);
        Bus::fake([ProcessEpcisDocumentJob::class]);

        $this->initializeDemo2();
        [$document] = $this->seedInboundDocument();

        // Simulate ReceiveEpcisUpload::dispatchProcess flag-off branch:
        ProcessEpcisDocumentJob::dispatch(
            Tenant::query()->findOrFail(self::DEMO2_TENANT_ID),
            (int) $document->getKey(),
        );

        Bus::assertDispatched(ProcessEpcisDocumentJob::class);
        $this->assertSame(0, EpcisJob::query()
            ->where('epcis_document_id', $document->getKey())
            ->where('kind', EpcisJobKind::InboundProcess->value)
            ->count());
    }

    #[Test]
    public function cancel_queued_inbound_job_skips_process(): void
    {
        $this->initializeDemo2();
        [$document] = $this->seedInboundDocument();

        // Seed a queued ledger without invoking the process worker.
        $job = EpcisJob::query()->create([
            'receipt' => str_replace('-', '', (string) Str::uuid()),
            'kind' => EpcisJobKind::InboundProcess,
            'status' => EpcisJobStatus::Queued,
            'epcis_document_id' => $document->getKey(),
            'original_filename' => $document->original_filename,
            'received_at' => now(),
            'attempt_count' => 0,
        ]);
        $this->jobIds[] = (int) $job->getKey();

        app(CancelEpcisJob::class)->handle($job);

        $process = new ProcessEpcisDocumentJob(
            Tenant::query()->findOrFail(self::DEMO2_TENANT_ID),
            (int) $document->getKey(),
        );
        $process->handle(app(EpcisIngestionService::class));

        $this->assertSame(EpcisJobStatus::Cancelled, $job->fresh()->status);
        $this->assertSame('received', $document->fresh()->status);
    }

    #[Test]
    public function requeue_inbound_creates_new_receipt(): void
    {
        $this->initializeDemo2();
        [$document] = $this->seedInboundDocument();

        $job = app(EnqueueInboundEpcisJob::class)->handle($document, sync: true);
        $this->jobIds[] = (int) $job->getKey();

        $job->forceFill([
            'status' => EpcisJobStatus::Error,
            'error_message' => 'forced for requeue test',
            'finished_at' => now(),
        ])->save();

        $newJob = app(RequeueEpcisJob::class)->handle($job->fresh() ?? $job);
        $this->jobIds[] = (int) $newJob->getKey();

        $this->assertNotSame($job->getKey(), $newJob->getKey());
        $this->assertNotSame($job->receipt, $newJob->receipt);
        $this->assertSame(EpcisJobKind::InboundProcess, $newJob->kind);
        $this->assertContains($newJob->fresh()->status, [
            EpcisJobStatus::Complete,
            EpcisJobStatus::Error,
            EpcisJobStatus::Queued,
            EpcisJobStatus::Processing,
        ]);
    }

    #[Test]
    public function reprocess_reconciles_queued_inbound_ledger_when_flag_off(): void
    {
        config(['tracepharma.epcis_jobs.enabled' => false]);
        $this->initializeDemo2();
        [$document] = $this->seedInboundDocument();

        $document->forceFill(['status' => 'parsed'])->save();

        $job = EpcisJob::query()->create([
            'receipt' => str_replace('-', '', (string) Str::uuid()),
            'kind' => EpcisJobKind::InboundProcess,
            'status' => EpcisJobStatus::Complete,
            'epcis_document_id' => $document->getKey(),
            'original_filename' => $document->original_filename,
            'received_at' => now(),
            'finished_at' => now(),
            'attempt_count' => 1,
        ]);
        $this->jobIds[] = (int) $job->getKey();

        app(\App\Actions\Epcis\ReprocessEpcisDocument::class)->handle($document->fresh() ?? $document, sync: true);

        $job = $job->fresh();
        $this->assertNotNull($job?->archived_at);
    }

    #[Test]
    public function reprocess_cancels_and_archives_active_queued_inbound_ledger(): void
    {
        config(['tracepharma.epcis_jobs.enabled' => true]);
        $this->initializeDemo2();
        [$document] = $this->seedInboundDocument();

        $document->forceFill(['status' => 'parsed'])->save();

        $job = EpcisJob::query()->create([
            'receipt' => str_replace('-', '', (string) Str::uuid()),
            'kind' => EpcisJobKind::InboundProcess,
            'status' => EpcisJobStatus::Queued,
            'epcis_document_id' => $document->getKey(),
            'original_filename' => $document->original_filename,
            'received_at' => now(),
            'attempt_count' => 0,
        ]);
        $this->jobIds[] = (int) $job->getKey();

        app(\App\Actions\Epcis\ReprocessEpcisDocument::class)->handle($document->fresh() ?? $document, sync: true);

        $job = $job->fresh();
        $this->assertSame(EpcisJobStatus::Cancelled, $job?->status);
        $this->assertNotNull($job?->archived_at);
    }

    #[Test]
    public function sync_after_process_does_not_overwrite_force_failed_status(): void
    {
        $this->initializeDemo2();
        [$document] = $this->seedInboundDocument();

        $job = EpcisJob::query()->create([
            'receipt' => str_replace('-', '', (string) Str::uuid()),
            'kind' => EpcisJobKind::InboundProcess,
            'status' => EpcisJobStatus::Processing,
            'epcis_document_id' => $document->getKey(),
            'original_filename' => $document->original_filename,
            'received_at' => now(),
            'started_at' => now()->subSeconds(700),
            'attempt_count' => 1,
        ]);
        $this->jobIds[] = (int) $job->getKey();

        app(ForceFailEpcisJob::class)->handle($job);

        $document->forceFill(['status' => 'parsed'])->save();

        app(SyncInboundEpcisJobFromDocument::class)->syncAfterProcess(
            $job->fresh() ?? $job,
            $document->fresh() ?? $document,
        );

        $this->assertSame(EpcisJobStatus::Error, $job->fresh()->status);
    }

    #[Test]
    public function force_fail_inbound_releases_overlap_lock_allowing_requeue(): void
    {
        $tenant = $this->initializeDemo2();
        [$document] = $this->seedInboundDocument();

        $job = EpcisJob::query()->create([
            'receipt' => str_replace('-', '', (string) Str::uuid()),
            'kind' => EpcisJobKind::InboundProcess,
            'status' => EpcisJobStatus::Processing,
            'epcis_document_id' => $document->getKey(),
            'original_filename' => $document->original_filename,
            'received_at' => now(),
            'started_at' => now()->subSeconds(700),
            'attempt_count' => 1,
        ]);
        $this->jobIds[] = (int) $job->getKey();

        $queueJob = new ProcessEpcisDocumentJob($tenant, (int) $document->getKey());
        $middleware = new WithoutOverlapping($queueJob->uniqueId());
        $lockKey = $middleware->getLockKey($queueJob);
        $cache = app(Cache::class);

        $heldLock = $cache->lock($lockKey, 360);
        $this->assertTrue($heldLock->get());

        app(ForceFailEpcisJob::class)->handle($job);

        $replacementLock = $cache->lock($lockKey, 360);
        $this->assertTrue($replacementLock->get(), 'Force-fail should release inbound WithoutOverlapping lock.');
        $replacementLock->release();

        $job->forceFill([
            'status' => EpcisJobStatus::Error,
            'error_message' => 'forced for requeue test',
            'finished_at' => now(),
        ])->save();

        $newJob = app(RequeueEpcisJob::class)->handle($job->fresh() ?? $job);
        $this->jobIds[] = (int) $newJob->getKey();

        $this->assertNotSame($job->receipt, $newJob->receipt);
    }

    #[Test]
    public function force_fail_while_document_parsing_sets_error_and_allows_requeue(): void
    {
        $tenant = $this->initializeDemo2();
        [$document] = $this->seedInboundDocument();

        $document->forceFill(['status' => 'parsing'])->save();

        $job = EpcisJob::query()->create([
            'receipt' => str_replace('-', '', (string) Str::uuid()),
            'kind' => EpcisJobKind::InboundProcess,
            'status' => EpcisJobStatus::Processing,
            'epcis_document_id' => $document->getKey(),
            'original_filename' => $document->original_filename,
            'received_at' => now(),
            'started_at' => now()->subSeconds(700),
            'attempt_count' => 1,
        ]);
        $this->jobIds[] = (int) $job->getKey();

        app(ForceFailEpcisJob::class)->handle($job);

        $document->refresh();
        $this->assertSame('error', $document->status);
        $this->assertSame('Force-failed after worker timeout exceeded.', $document->error_message);

        $newJob = app(RequeueEpcisJob::class)->handle($job->fresh() ?? $job);
        $this->jobIds[] = (int) $newJob->getKey();

        $this->assertNotSame($job->receipt, $newJob->receipt);
    }

    private function initializeDemo2(): Tenant
    {
        $tenant = Tenant::query()->findOrFail(self::DEMO2_TENANT_ID);
        tenancy()->initialize($tenant);

        return $tenant;
    }

    /**
     * @return array{0: EpcisDocument}
     */
    private function seedInboundDocument(): array
    {
        Storage::fake('local');
        $path = 'epcis/inbound/test-'.Str::lower(Str::random(8)).'.xml';
        Storage::disk('local')->put($path, $this->minimalInboundXml());

        $document = EpcisDocument::query()->create([
            'document_uuid' => 'urn:uuid:'.Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'inbound',
            'format' => 'xml',
            'original_filename' => basename($path),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', 'x'),
            'status' => 'received',
            'received_at' => now(),
            'event_count' => 0,
            'epc_count' => 0,
            'reprocess_count' => 0,
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return [$document];
    }

    private function minimalInboundXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" schemaVersion="1.2" creationDate="2026-08-09T12:00:00.000Z">
  <EPCISBody><EventList></EventList></EPCISBody>
</epcis:EPCISDocument>
XML;
    }
}
