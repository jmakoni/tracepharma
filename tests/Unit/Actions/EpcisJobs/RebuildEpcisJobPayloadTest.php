<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\EpcisJobs;

use App\Actions\Epcis\PrepareOutboundEpcisForRetransmit;
use App\Actions\Epcis\RemintOutboundEpcisIdentityForRetransmit;
use App\Actions\Epcis\ValidateEpcis12Document;
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
use App\Support\Epcis\PersistEpcisXmlPayload;
use App\Support\Shipping\AssertOutermostSsccHasChildren;
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
    public function requeueing_prepares_via_prepare_action_then_asserts_payload(): void
    {
        $this->initializeDemo2();

        Storage::fake('local');
        config(['tracepharma.epcis.authored_payload_disk' => 'local']);

        $oldUuid = 'urn:uuid:aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $path = EpcisStoragePath::onDisk('local', 'epcis/outbound/remint-'.Str::lower(Str::random(6)).'.xml');
        $xml = $this->minimalSbdhXml($oldUuid);
        Storage::disk('local')->put($path, $xml);

        $document = EpcisDocument::query()->create([
            'document_uuid' => $oldUuid,
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Receiving,
            'format' => 'xml',
            'original_filename' => basename($path),
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', $xml),
            'status' => 'generated',
            'received_at' => now(),
            'event_count' => 0,
            'epc_count' => 0,
            'reprocess_count' => 0,
            'notes' => 'remint fixture',
        ]);
        $this->documentIds[] = (int) $document->getKey();

        $job = EpcisJob::query()->create([
            'kind' => EpcisJobKind::OutboundReceiving,
            'status' => EpcisJobStatus::Error,
            'receipt' => bin2hex(random_bytes(16)),
            'epcis_document_id' => $document->getKey(),
            'error_message' => 'fixture',
            'received_at' => now(),
            'finished_at' => now(),
        ]);
        $this->jobIds[] = (int) $job->getKey();

        $this->app->instance(
            PrepareOutboundEpcisForRetransmit::class,
            new class(
                app(BuildFullHistoryShippingEpcisXml::class),
                app(PersistEpcisXmlPayload::class),
                app(RemintOutboundEpcisIdentityForRetransmit::class),
                app(ValidateEpcis12Document::class),
                app(AssertOutermostSsccHasChildren::class),
            ) extends PrepareOutboundEpcisForRetransmit
            {
                protected function assertGs1ValidOrFail(EpcisDocument $document): void
                {
                    // Minimal SBDH fixture is not a full GS1 US R1.3 shipping TI.
                }
            },
        );

        app(RebuildEpcisJobPayload::class)->handle($job->fresh(['document']) ?? $job);

        $document->refresh();
        $this->assertNotSame($oldUuid, (string) $document->document_uuid);
        $this->assertNotSame(basename($path), (string) $document->original_filename);
        $this->assertTrue(Storage::disk('local')->exists((string) $document->payload_path));
        $this->assertTrue(
            $job->messages()->where('message', 'like', '%Prepared outbound payload%')->exists(),
        );
    }

    #[Test]
    public function skip_prepare_only_asserts_existing_payload(): void
    {
        $this->initializeDemo2();

        Storage::fake('local');

        $path = EpcisStoragePath::onDisk('local', 'epcis/outbound/skip-'.Str::lower(Str::random(6)).'.xml');
        Storage::disk('local')->put($path, '<ok/>');

        [$document, $session, $job] = $this->seedShippingJob($path, 'local');

        $this->app->bind(
            BuildFullHistoryShippingEpcisXml::class,
            fn () => $this->fail('skipPrepare must not rebuild shipping EPCIS.'),
        );

        app(RebuildEpcisJobPayload::class)->handle(
            $job->fresh(['document', 'shippingSession']) ?? $job,
            skipPrepare: true,
        );

        $document->refresh();
        $this->assertSame($path, $document->payload_path);
        $this->assertTrue(
            $job->messages()->where('message', 'like', '%Skipping payload prepare%')->exists(),
        );
    }

    private function minimalSbdhXml(string $instanceId): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1" xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader" schemaVersion="1.2" creationDate="2026-01-01T00:00:00Z">
  <EPCISHeader>
    <sbdh:StandardBusinessDocumentHeader>
      <sbdh:HeaderVersion>1.0</sbdh:HeaderVersion>
      <sbdh:DocumentIdentification>
        <sbdh:Standard>EPCglobal</sbdh:Standard>
        <sbdh:TypeVersion>1.0</sbdh:TypeVersion>
        <sbdh:InstanceIdentifier>{$instanceId}</sbdh:InstanceIdentifier>
        <sbdh:Type>Events</sbdh:Type>
        <sbdh:CreationDateAndTime>2026-01-01T00:00:00Z</sbdh:CreationDateAndTime>
      </sbdh:DocumentIdentification>
    </sbdh:StandardBusinessDocumentHeader>
  </EPCISHeader>
  <EPCISBody><EventList/></EPCISBody>
</epcis:EPCISDocument>
XML;
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
