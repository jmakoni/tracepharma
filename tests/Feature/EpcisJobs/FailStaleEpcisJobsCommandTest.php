<?php

declare(strict_types=1);

namespace Tests\Feature\EpcisJobs;

use App\Actions\EpcisJobs\EnqueueEpcisJob;
use App\Enums\EpcisAuthoredKind;
use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Enums\TenantProfile;
use App\Jobs\EpcisJobs\TransmitEpcisJob;
use App\Models\Epcis\EpcisDocument;
use App\Models\EpcisJob;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FailStaleEpcisJobsCommandTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private static bool $demo2TenantReady = false;

    /** @var list<int> */
    private array $jobIds = [];

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<int> */
    private array $sessionIds = [];

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
        Carbon::setTestNow();

        if (! tenancy()->initialized && ($this->jobIds !== [] || $this->documentIds !== [] || $this->sessionIds !== [])) {
            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));
        }

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
            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function command_force_fails_sending_job_past_worker_sla(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00:00', 'UTC'));

        Bus::fake([TransmitEpcisJob::class]);

        $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        $job->forceFill([
            'status' => EpcisJobStatus::Sending,
            'started_at' => now()->subSeconds(400),
        ])->save();

        tenancy()->end();

        $exitCode = Artisan::call('epcis:fail-stale-jobs', [
            '--tenant' => self::DEMO2_TENANT_ID,
        ]);

        $this->assertSame(0, $exitCode);

        tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

        $job->refresh();
        $this->assertSame(EpcisJobStatus::Error, $job->status);
        $this->assertNotNull($job->finished_at);
        $this->assertStringContainsString('Force-failed', (string) $job->error_message);
        $this->assertSame('failed', $document->fresh()->transmission_status);
        $this->assertTrue(
            $job->messages()->where('level', 'error')->exists(),
            'Expected SLA recovery to write an audit log message.',
        );
    }

    #[Test]
    public function command_leaves_sending_job_alone_when_within_sla(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00:00', 'UTC'));

        Bus::fake([TransmitEpcisJob::class]);

        $this->initializeDemo2();
        [$document] = $this->seedShippingDocument();
        $job = app(EnqueueEpcisJob::class)->handle($document);
        $this->jobIds[] = (int) $job->getKey();

        $job->forceFill([
            'status' => EpcisJobStatus::Sending,
            'started_at' => now()->subSeconds(120),
        ])->save();

        tenancy()->end();

        Artisan::call('epcis:fail-stale-jobs', [
            '--tenant' => self::DEMO2_TENANT_ID,
        ]);

        tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

        $job->refresh();
        $this->assertSame(EpcisJobStatus::Sending, $job->status);
        $this->assertNull($job->finished_at);
        $this->assertSame('queued', $document->fresh()->transmission_status);
    }

    #[Test]
    public function command_force_fails_processing_inbound_job_past_worker_sla(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-16 12:00:00', 'UTC'));

        $this->initializeDemo2();

        $job = EpcisJob::query()->create([
            'receipt' => Str::lower(Str::random(32)),
            'kind' => EpcisJobKind::InboundProcess,
            'status' => EpcisJobStatus::Processing,
            'started_at' => now()->subSeconds(700),
            'received_at' => now()->subMinutes(20),
            'attempt_count' => 1,
        ]);
        $this->jobIds[] = (int) $job->getKey();

        tenancy()->end();

        Artisan::call('epcis:fail-stale-jobs', [
            '--tenant' => self::DEMO2_TENANT_ID,
        ]);

        tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

        $job->refresh();
        $this->assertSame(EpcisJobStatus::Error, $job->status);
        $this->assertStringContainsString('Force-failed', (string) $job->error_message);
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
