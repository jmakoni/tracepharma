<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\EpcisJobs;

use App\Actions\EpcisJobs\RebuildEpcisJobPayload;
use App\Enums\EpcisAuthoredKind;
use App\Enums\EpcisJobKind;
use App\Enums\EpcisJobStatus;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\EpcisJob;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Epcis\BuildFullHistoryShippingEpcisXml;
use App\Support\Epcis\EpcisStoragePath;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RebuildEpcisJobPayloadTest extends TestCase
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
            if ($this->documentIds !== []) {
                EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            }
            tenancy()->end();
        }

        parent::tearDown();
    }

    #[Test]
    public function requeueing_a_shipping_job_retransmits_the_existing_payload_untouched(): void
    {
        $this->initializeDemo2();

        Storage::fake('local');

        $path = EpcisStoragePath::onDisk('local', 'epcis/outbound/ship-'.Str::lower(Str::random(6)).'.xml');
        $xml = '<?xml version="1.0"?><EPCISDocument>as-transmitted</EPCISDocument>';
        Storage::disk('local')->put($path, $xml);

        [$document, $session, $job] = $this->seedShippingJob($path, 'local');
        $document->forceFill(['file_sha256' => hash('sha256', $xml)])->save();
        $document->refresh();

        $originalUuid = (string) $document->document_uuid;
        $originalFilename = (string) $document->original_filename;
        $originalSha = (string) $document->file_sha256;
        $originalCreationDate = $document->creation_date?->toIso8601String();

        $this->app->bind(
            BuildFullHistoryShippingEpcisXml::class,
            fn () => $this->fail('Requeue must not rebuild shipping EPCIS from full history.'),
        );

        app(RebuildEpcisJobPayload::class)->handle($job->fresh(['document', 'shippingSession']) ?? $job);

        $document->refresh();

        $this->assertSame($path, $document->payload_path);
        $this->assertSame('local', $document->payload_disk);
        $this->assertSame($originalUuid, (string) $document->document_uuid);
        $this->assertSame($originalFilename, (string) $document->original_filename);
        $this->assertSame($originalSha, (string) $document->file_sha256);
        $this->assertSame($originalCreationDate, $document->creation_date?->toIso8601String());

        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertSame($xml, Storage::disk('local')->get($path));

        $this->assertTrue(
            $job->messages()->where('message', 'like', '%existing payload%')->exists(),
        );
    }

    #[Test]
    public function assert_existing_payload_resolves_local_path_for_exists_check(): void
    {
        $this->initializeDemo2();

        Storage::fake('local');

        $relative = 'epcis/outbound/existing-'.Str::lower(Str::random(6)).'.xml';
        $path = EpcisStoragePath::onDisk('local', $relative);
        Storage::disk('local')->put($path, '<ok/>');

        [$document, $session, $job] = $this->seedShippingJob($relative, 'local');
        $job->forceFill(['kind' => EpcisJobKind::OutboundReceiving])->save();

        app(RebuildEpcisJobPayload::class)->handle($job->fresh(['document']) ?? $job);

        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertTrue(
            $job->messages()->where('message', 'like', '%existing payload%')->exists(),
        );
    }

    #[Test]
    public function assert_existing_payload_passes_through_legacy_s3_tenant_key(): void
    {
        $this->initializeDemo2();

        config([
            'filesystems.disks.epcis_s3' => [
                'driver' => 's3',
                'key' => 'testing',
                'secret' => 'testing',
                'region' => 'us-east-1',
                'bucket' => 'testing',
                'throw' => true,
            ],
        ]);
        Storage::fake('epcis_s3');

        $legacy = 'tenants/'.self::DEMO2_TENANT_ID.'/epcis/outbound/legacy-'.Str::lower(Str::random(6)).'.xml';
        Storage::disk('epcis_s3')->put($legacy, '<ok/>');

        [$document, $session, $job] = $this->seedShippingJob($legacy, 'epcis_s3');
        $job->forceFill(['kind' => EpcisJobKind::OutboundReceiving])->save();

        app(RebuildEpcisJobPayload::class)->handle($job->fresh(['document']) ?? $job);

        $this->assertTrue(Storage::disk('epcis_s3')->exists($legacy));
        $this->assertTrue(
            $job->messages()->where('message', 'like', '%existing payload%')->exists(),
        );
    }

    private function initializeDemo2(): Tenant
    {
        if (! self::$demo2TenantReady) {
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant === null) {
                $tenant = Tenant::query()->create(array_merge(Tenant::defaultTrialAttributes(), [
                    'id' => self::DEMO2_TENANT_ID,
                    'name' => 'Demo 2',
                    'profile' => TenantProfile::DrugWholesaler,
                    'status' => 'active',
                    'tenancy_db_name' => self::DEMO2_DATABASE,
                ]));
                $tenant->domains()->create(['domain' => self::DEMO2_DOMAIN]);
            } else {
                $tenant->domains()->firstOrCreate(['domain' => self::DEMO2_DOMAIN]);
                if ($tenant->profile !== TenantProfile::DrugWholesaler) {
                    $tenant->forceFill(['profile' => TenantProfile::DrugWholesaler])->save();
                }
            }

            $this->artisan('tenants:migrate', [
                '--tenants' => [self::DEMO2_TENANT_ID],
                '--force' => true,
            ])->assertSuccessful();

            self::$demo2TenantReady = true;
        }

        $tenant = Tenant::query()->findOrFail(self::DEMO2_TENANT_ID);
        tenancy()->initialize($tenant);

        return $tenant;
    }

    /**
     * @return array{0: EpcisDocument, 1: OutboundShippingSession, 2: EpcisJob}
     */
    private function seedShippingJob(string $payloadPath, string $payloadDisk): array
    {
        $site = Site::query()->whereNotNull('gln')->where('is_organization_facility', true)->first()
            ?? Site::query()->whereNotNull('gln')->first();
        $this->assertNotNull($site);

        $document = EpcisDocument::query()->create([
            'document_uuid' => 'urn:uuid:'.Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Shipping,
            'format' => 'xml',
            'original_filename' => basename($payloadPath),
            'payload_disk' => $payloadDisk,
            'payload_path' => $payloadPath,
            'file_sha256' => hash('sha256', 'x'),
            'status' => 'generated',
            'received_at' => now(),
            'ship_from_site_id' => $site->getKey(),
            'event_count' => 0,
            'epc_count' => 0,
            'reprocess_count' => 0,
            'notes' => 'RebuildEpcisJobPayloadTest fixture.',
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
            'asn_number' => 'TEST-ASN-REBUILD',
            'customer_po' => 'TEST-PO-REBUILD',
        ]);
        $this->sessionIds[] = (int) $session->getKey();

        $job = EpcisJob::query()->create([
            'kind' => EpcisJobKind::OutboundShipping,
            'status' => EpcisJobStatus::Error,
            'receipt' => bin2hex(random_bytes(16)),
            'epcis_document_id' => $document->getKey(),
            'outbound_shipping_session_id' => $session->getKey(),
            'error_message' => 'fixture',
            'received_at' => now(),
            'finished_at' => now(),
        ]);
        $this->jobIds[] = (int) $job->getKey();

        return [$document, $session, $job];
    }
}
