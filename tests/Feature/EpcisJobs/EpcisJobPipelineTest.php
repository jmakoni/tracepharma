<?php

declare(strict_types=1);

namespace Tests\Feature\EpcisJobs;

use App\Actions\EpcisJobs\ArchiveEpcisJob;
use App\Actions\EpcisJobs\CancelEpcisJob;
use App\Actions\EpcisJobs\EnqueueEpcisJob;
use App\Actions\EpcisJobs\ForceFailEpcisJob;
use App\Actions\EpcisJobs\RequeueEpcisJob;
use App\Enums\EpcisAuthoredKind;
use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Enums\TenantRole;
use App\Jobs\EpcisJobs\TransmitEpcisJob;
use App\Models\Epcis\EpcisDocument;
use App\Models\EpcisJob;
use App\Models\OutboundConnection;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Epcis\ConnectionOutboundEpcisTransmitter;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use App\Support\Auth\TenantRoleSeeder;
use App\Support\Epcis\ScheduleOutboundEpcisTransmission;
use App\Support\EpcisJobs\EpcisJobLogger;
use App\Support\EpcisJobs\EpcisJobSla;
use App\Support\EpcisJobs\EpcisJobStats;
use App\Support\Tenancy\TenantKillSwitches;
use App\Support\TenantSettings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class EpcisJobPipelineTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $jobIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

    /** @var list<int> */
    private array $connectionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tracepharma.epcis_jobs.enabled' => true,
            'tracepharma.epcis_jobs.queue' => 'epcis',
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
            if ($this->sessionIds !== []) {
                OutboundShippingSession::query()->whereIn('id', $this->sessionIds)->delete();
            }
            if ($this->connectionIds !== []) {
                OutboundConnection::query()->whereIn('id', $this->connectionIds)->delete();
            }
            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function transmit_retry_after_post_send_crash_does_not_double_post(): void
    {
        $tenant = $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();

        Http::fake([
            'https://partner.example/epcis' => Http::response('OK', 202),
        ]);

        $connection = OutboundConnection::query()->create([
            'name' => 'Retry Idempotency HTTPS',
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => OutboundTransport::Https,
            'is_active' => true,
            'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            'credentials' => ['webhook_token' => 'retry-token'],
        ]);
        $this->connectionIds[] = (int) $connection->getKey();
        $document->forceFill(['outbound_connection_id' => $connection->getKey()])->save();

        Bus::fake([TransmitEpcisJob::class]);
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        (new TransmitEpcisJob($tenant, (int) $job->getKey()))->handle(
            app(ConnectionOutboundEpcisTransmitter::class),
            app(EpcisJobLogger::class),
            app(EpcisJobStats::class),
        );

        Http::assertSentCount(1);
        $this->assertSame('sent', $document->fresh()->transmission_status);

        $document->forceFill([
            'transmission_status' => 'sending',
            'sent_at' => null,
        ])->save();
        $document->touch();

        $job->forceFill([
            'status' => EpcisJobStatus::Sending,
            'finished_at' => null,
            'error_message' => null,
        ])->save();

        (new TransmitEpcisJob($tenant, (int) $job->getKey()))->handle(
            app(ConnectionOutboundEpcisTransmitter::class),
            app(EpcisJobLogger::class),
            app(EpcisJobStats::class),
        );

        Http::assertSentCount(1);
        $this->assertSame(EpcisJobStatus::Queued, $job->fresh()->status);
    }

    #[Test]
    public function transmit_force_requeue_bypasses_recent_heartbeat_guard(): void
    {
        $tenant = $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();

        Http::fake([
            'https://partner.example/epcis' => Http::response('OK', 202),
        ]);

        $connection = OutboundConnection::query()->create([
            'name' => 'Force Requeue HTTPS',
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => OutboundTransport::Https,
            'is_active' => true,
            'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            'credentials' => ['webhook_token' => 'force-token'],
        ]);
        $this->connectionIds[] = (int) $connection->getKey();
        $document->forceFill([
            'outbound_connection_id' => $connection->getKey(),
            'transmission_status' => 'sending',
            'sent_at' => null,
        ])->save();
        $document->touch();

        Bus::fake([TransmitEpcisJob::class]);
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        (new TransmitEpcisJob($tenant, (int) $job->getKey(), forceRequeue: true))->handle(
            app(ConnectionOutboundEpcisTransmitter::class),
            app(EpcisJobLogger::class),
            app(EpcisJobStats::class),
        );

        Http::assertSentCount(1);
        $this->assertSame('sent', $document->fresh()->transmission_status);
    }

    #[Test]
    public function enqueue_creates_queued_job_and_dispatches_transmit(): void
    {
        Bus::fake([TransmitEpcisJob::class]);

        $tenant = $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();

        $job = app(EnqueueEpcisJob::class)->handle($document);

        $this->jobIds[] = (int) $job->getKey();

        $this->assertSame(EpcisJobStatus::Queued, $job->status);
        $this->assertSame(32, strlen($job->receipt));
        $this->assertSame('queued', $document->fresh()->transmission_status);
        $this->assertTrue($job->messages()->exists());

        Bus::assertDispatched(TransmitEpcisJob::class, function (TransmitEpcisJob $queued) use ($tenant, $job): bool {
            return $queued->tenant->is($tenant) && $queued->epcisJobId === (int) $job->getKey();
        });
    }

    #[Test]
    public function stale_queued_outbound_job_is_redispatched_on_repeat_enqueue(): void
    {
        Bus::fake([TransmitEpcisJob::class]);

        $tenant = $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();

        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        $job->forceFill([
            'received_at' => now()->subMinutes(20),
        ])->save();

        $this->assertTrue(EpcisJobSla::isStaleQueued($job->fresh()));

        Bus::assertDispatchedTimes(TransmitEpcisJob::class, 1);

        $again = app(EnqueueEpcisJob::class)->handle($document);

        $this->assertSame($job->getKey(), $again->getKey());
        Bus::assertDispatchedTimes(TransmitEpcisJob::class, 2);
        Bus::assertDispatched(TransmitEpcisJob::class, function (TransmitEpcisJob $queued) use ($tenant, $job): bool {
            return $queued->tenant->is($tenant) && $queued->epcisJobId === (int) $job->getKey();
        });
    }

    #[Test]
    public function cancel_queued_job_skips_document_transmission(): void
    {
        Bus::fake([TransmitEpcisJob::class]);

        $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        $cancelled = app(CancelEpcisJob::class)->handle($job);

        $this->assertSame(EpcisJobStatus::Cancelled, $cancelled->status);
        $this->assertSame('skipped', $document->fresh()->transmission_status);
    }

    #[Test]
    public function transmit_job_marks_complete_when_transmitter_sends(): void
    {
        $tenant = $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();

        Bus::fake([TransmitEpcisJob::class]);
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        $this->mock(OutboundEpcisTransmitter::class, function ($mock) use ($document): void {
            $mock->shouldReceive('transmit')
                ->once()
                ->andReturnUsing(function () use ($document): void {
                    $document->forceFill([
                        'transmission_status' => 'sent',
                        'sent_at' => now(),
                        'error_message' => null,
                    ])->save();
                });
        });

        (new TransmitEpcisJob($tenant, (int) $job->getKey()))->handle(
            app(OutboundEpcisTransmitter::class),
            app(EpcisJobLogger::class),
            app(EpcisJobStats::class),
        );

        $job->refresh();
        $this->assertSame(EpcisJobStatus::Complete, $job->status);
        $this->assertNotNull($job->stats_json);
    }

    #[Test]
    public function requeue_from_error_creates_new_receipt(): void
    {
        Bus::fake([TransmitEpcisJob::class]);

        $this->initializeDemo2();
        [$document, $session] = $this->seedShippingDocument();
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        $job->forceFill([
            'status' => EpcisJobStatus::Error,
            'finished_at' => now(),
            'error_message' => 'failed',
            'kind' => EpcisJobKind::OutboundReceiving,
            'outbound_shipping_session_id' => $session->getKey(),
        ])->save();
        $document->forceFill(['transmission_status' => 'failed'])->save();

        // Receiving kind uses existing-payload rebuild path (no full-history builder).
        $newJob = app(RequeueEpcisJob::class)->handle($job->fresh());
        $this->jobIds[] = (int) $newJob->getKey();

        $this->assertNotSame($job->receipt, $newJob->receipt);
        $this->assertSame(EpcisJobStatus::Queued, $newJob->status);
        $this->assertSame(EpcisJobStatus::Error, $job->fresh()->status);
    }

    #[Test]
    public function cancel_stuck_sending_job_after_worker_timeout(): void
    {
        Bus::fake([TransmitEpcisJob::class]);

        $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        $job->forceFill([
            'status' => EpcisJobStatus::Sending,
            'started_at' => now()->subSeconds(400),
        ])->save();

        $cancelled = app(CancelEpcisJob::class)->handle($job);

        $this->assertSame(EpcisJobStatus::Cancelled, $cancelled->status);
        $this->assertSame('skipped', $document->fresh()->transmission_status);
    }

    #[Test]
    public function force_fail_stuck_sending_job_after_worker_timeout(): void
    {
        Bus::fake([TransmitEpcisJob::class]);

        $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        $job->forceFill([
            'status' => EpcisJobStatus::Sending,
            'started_at' => now()->subSeconds(400),
        ])->save();

        $failed = app(ForceFailEpcisJob::class)->handle($job);

        $this->assertSame(EpcisJobStatus::Error, $failed->status);
        $this->assertSame('failed', $document->fresh()->transmission_status);
    }

    #[Test]
    public function transmit_job_does_not_overwrite_force_failed_status_after_io(): void
    {
        $tenant = $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();

        Bus::fake([TransmitEpcisJob::class]);
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        $job->forceFill([
            'status' => EpcisJobStatus::Sending,
            'started_at' => now()->subSeconds(400),
        ])->save();

        $this->mock(OutboundEpcisTransmitter::class, function ($mock) use ($document, $job): void {
            $mock->shouldReceive('transmit')
                ->once()
                ->andReturnUsing(function () use ($document, $job): void {
                    app(ForceFailEpcisJob::class)->handle($job->fresh() ?? $job);

                    $document->forceFill([
                        'transmission_status' => 'sent',
                        'sent_at' => now(),
                        'error_message' => null,
                    ])->save();
                });
        });

        (new TransmitEpcisJob($tenant, (int) $job->getKey()))->handle(
            app(OutboundEpcisTransmitter::class),
            app(EpcisJobLogger::class),
            app(EpcisJobStats::class),
        );

        $job->refresh();
        $this->assertSame(EpcisJobStatus::Error, $job->status);
        $this->assertStringContainsString('Force-failed', (string) $job->error_message);
    }

    #[Test]
    public function force_fail_releases_overlap_lock_allowing_requeue(): void
    {
        Bus::fake([TransmitEpcisJob::class]);

        $tenant = $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        $job->forceFill([
            'status' => EpcisJobStatus::Sending,
            'started_at' => now()->subSeconds(400),
        ])->save();

        $queueJob = new TransmitEpcisJob($tenant, (int) $job->getKey(), false, (int) $document->getKey());
        $middleware = new WithoutOverlapping($queueJob->uniqueId());
        $lockKey = $middleware->getLockKey($queueJob);
        $cache = app(Cache::class);

        $heldLock = $cache->lock($lockKey, 360);
        $this->assertTrue($heldLock->get(), 'Overlap lock should be acquirable before simulating a stuck worker.');

        app(ForceFailEpcisJob::class)->handle($job);

        $replacementLock = $cache->lock($lockKey, 360);
        $this->assertTrue(
            $replacementLock->get(),
            'Force-fail should release the WithoutOverlapping lock so requeue is not blocked.',
        );
        $replacementLock->release();

        $newJob = app(RequeueEpcisJob::class)->handle($job->fresh());
        $this->jobIds[] = (int) $newJob->getKey();

        $this->assertSame(EpcisJobStatus::Queued, $newJob->status);
        $this->assertNotSame($job->receipt, $newJob->receipt);
    }

    #[Test]
    public function cancel_stuck_sending_job_releases_overlap_lock(): void
    {
        Bus::fake([TransmitEpcisJob::class]);

        $tenant = $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        $job->forceFill([
            'status' => EpcisJobStatus::Sending,
            'started_at' => now()->subSeconds(400),
        ])->save();

        $queueJob = new TransmitEpcisJob($tenant, (int) $job->getKey(), false, (int) $document->getKey());
        $middleware = new WithoutOverlapping($queueJob->uniqueId());
        $lockKey = $middleware->getLockKey($queueJob);
        $cache = app(Cache::class);

        $heldLock = $cache->lock($lockKey, 360);
        $this->assertTrue($heldLock->get());

        app(CancelEpcisJob::class)->handle($job);

        $replacementLock = $cache->lock($lockKey, 360);
        $this->assertTrue($replacementLock->get(), 'Cancel should release the WithoutOverlapping lock.');
        $replacementLock->release();
    }

    #[Test]
    public function archive_terminal_job_hides_from_default_scope(): void
    {
        Bus::fake([TransmitEpcisJob::class]);

        $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();
        $job->forceFill([
            'status' => EpcisJobStatus::Complete,
            'finished_at' => now(),
        ])->save();

        app(ArchiveEpcisJob::class)->handle($job);

        $this->assertNotNull($job->fresh()->archived_at);
        $this->assertFalse(EpcisJob::query()->notArchived()->whereKey($job->getKey())->exists());
    }

    #[Test]
    public function enqueue_throw_marks_document_failed_not_silent_success(): void
    {
        $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();

        // Force EnqueueEpcisJob to throw (final class — cannot mock under the type hint).
        $document->forceFill([
            'authored_kind' => null,
            'notes' => 'Unclassified outbound document.',
            'original_filename' => 'unclassified.xml',
        ])->save();

        app(ScheduleOutboundEpcisTransmission::class)->afterPersist($document, true);

        $document->refresh();
        $this->assertSame('failed', $document->transmission_status);
        $this->assertNotEmpty($document->error_message);
        $this->assertSame(0, EpcisJob::query()->where('epcis_document_id', $document->getKey())->count());
    }

    #[Test]
    public function storage_io_error_marks_transmit_job_error_not_cancelled(): void
    {
        $tenant = $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();

        Bus::fake([TransmitEpcisJob::class]);
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        $driver = \Mockery::mock(\League\Flysystem\FilesystemOperator::class);
        $driver->shouldReceive('has')
            ->andThrow(\League\Flysystem\UnableToCheckFileExistence::forLocation((string) $document->payload_path));

        Storage::set($document->payloadFilesystemDisk(), new \Illuminate\Filesystem\FilesystemAdapter(
            $driver,
            \Mockery::mock(\League\Flysystem\FilesystemAdapter::class),
            ['root' => storage_path('framework/testing')],
        ));

        $queueJob = new TransmitEpcisJob($tenant, (int) $job->getKey());
        $queueJob->tries = 1;

        try {
            $queueJob->handle(
                app(OutboundEpcisTransmitter::class),
                app(EpcisJobLogger::class),
                app(EpcisJobStats::class),
            );
        } catch (\Throwable) {
            // Transient I/O may rethrow for queue retry; status must still not be Cancelled.
        }

        $job->refresh();
        $this->assertNotSame(EpcisJobStatus::Cancelled, $job->status);
        $this->assertSame(EpcisJobStatus::Error, $job->status);
        $this->assertSame('failed', $document->fresh()->transmission_status);
    }

    #[Test]
    public function double_enqueue_same_document_returns_one_job(): void
    {
        Bus::fake([TransmitEpcisJob::class]);

        $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();

        $first = app(EnqueueEpcisJob::class)->handle($document);
        $second = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $first->getKey();

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, EpcisJob::query()->where('epcis_document_id', $document->getKey())->count());
        Bus::assertDispatchedTimes(TransmitEpcisJob::class, 1);
    }

    #[Test]
    public function sync_path_used_when_jobs_disabled(): void
    {
        config(['tracepharma.epcis_jobs.enabled' => false]);

        $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();

        $called = false;
        $this->mock(OutboundEpcisTransmitter::class, function ($mock) use (&$called, $document): void {
            $mock->shouldReceive('transmit')
                ->once()
                ->andReturnUsing(function () use (&$called, $document): void {
                    $called = true;
                    $document->forceFill(['transmission_status' => 'sent', 'sent_at' => now()])->save();
                });
        });

        app(ScheduleOutboundEpcisTransmission::class)
            ->afterPersist($document, true);

        $this->assertTrue($called);
        $this->assertSame(0, EpcisJob::query()->where('epcis_document_id', $document->getKey())->count());
    }

    #[Test]
    public function enqueue_requires_integrations_job_role_when_enabled(): void
    {
        Bus::fake([TransmitEpcisJob::class]);
        config(['tracepharma.epcis_jobs.enabled' => true]);

        $tenant = $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();

        app(TenantRoleSeeder::class)->seedForProfile(TenantProfile::DrugWholesaler);
        TenantSettings::forTenant($tenant)->setJobRolesEnabled(true);
        $tenant->save();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $receiver = User::factory()->create();
        $receiver->assignRole(TenantRole::VrsAnalyst->value);
        $this->actingAs($receiver);

        try {
            app(EnqueueEpcisJob::class)->handle($document);
            $this->fail('Expected integrations role gate to reject enqueue.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Integrations are not authorized', $exception->getMessage());
        }
    }

    private function initializeDemo2(): Tenant
    {
        if (! self::$demo2TenantReady) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            $this->assertNotNull($tenant);
            if ($tenant->profile !== TenantProfile::DrugWholesaler) {
                $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
            }
            self::$demo2TenantReady = true;
        }

        $tenant = Tenant::query()->findOrFail(self::DEMO2_TENANT_ID);
        TenantSettings::forTenant($tenant)->setKillSwitch(TenantKillSwitches::OUTBOUND_EPCIS, false);
        $tenant->save();
        tenancy()->initialize($tenant);

        return $tenant;
    }

    /**
     * @return array{0: EpcisDocument, 1: OutboundShippingSession}
     */
    private function seedShippingDocument(): array
    {
        Storage::fake('local');

        $site = Site::query()->whereNotNull('gln')->where('is_organization_facility', true)->first()
            ?? Site::query()->whereNotNull('gln')->first();
        $this->assertNotNull($site);

        $path = 'epcis/outbound/test-ship-'.Str::lower(Str::random(8)).'.xml';
        Storage::disk('local')->put($path, '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" creationDate="2026-08-09T00:00:00.000Z"></epcis:EPCISDocument>');

        $document = EpcisDocument::query()->create([
            'document_uuid' => 'urn:uuid:'.Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Shipping,
            'format' => 'xml',
            'original_filename' => basename($path),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', 'x'),
            'status' => 'generated',
            'received_at' => now(),
            'ship_from_site_id' => $site->getKey(),
            'event_count' => 0,
            'epc_count' => 0,
            'reprocess_count' => 0,
            'notes' => 'Generated outbound shipping EPCIS for ship order session #test.',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $session = OutboundShippingSession::query()->create([
            'site_id' => $site->getKey(),
            'status' => 'completed',
            'dscsa_affirm' => true,
            'expected_count' => 0,
            'confirmed_count' => 0,
            'epcis_document_id' => $document->getKey(),
            'shipping_events_generated_at' => now(),
            'opened_at' => now(),
            'completed_at' => now(),
            'asn_number' => 'TEST-ASN',
            'customer_po' => 'TEST-PO',
        ]);
        $this->sessionIds[] = (int) $session->getKey();

        return [$document, $session];
    }
}
