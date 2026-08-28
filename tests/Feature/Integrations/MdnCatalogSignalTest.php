<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Epcis\RecordOperationalEpcisCatalogSignal;
use App\Enums\As2MdnAckMode;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\TransmissionMdn;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MdnCatalogSignalTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private const AS2_MDN_WEBHOOK_SECRET = 'test-as2-mdn-webhook-secret';

    private static bool $demo2TenantReady = false;

    private ?int $connectionId = null;

    private ?int $documentId = null;

    /** @var list<int> */
    private array $transmissionMdnIds = [];

    /** @var list<int> */
    private array $exceptionIds = [];

    #[Test]
    public function open_row_dedupe_returns_existing_partner_rejected_exception(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $document = $this->createOutboundDocument();

            $signal = app(RecordOperationalEpcisCatalogSignal::class);
            $first = $signal->partnerRejected($document, 'first reject');
            $second = $signal->partnerRejected($document, 'second reject');

            $this->exceptionIds[] = (int) $first->getKey();

            $this->assertSame($first->getKey(), $second->getKey());
            $this->assertSame(
                1,
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', 'PARTNER_REJECTED_FILE')
                    ->where('status', 'open')
                    ->count(),
            );
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function async_failed_mdn_creates_partner_rejected_file_once(): void
    {
        $tenant = $this->initializeDemo2Tenant();

        try {
            [$connection, $document, $mdn, $capturedMessageId] = $this->transmitAsyncMdnFixture();

            $asyncMdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: {$capturedMessageId}\r\nDisposition: automatic-action/MDN-sent-automatically; failed/failure: authentication-failed";

            $this->postAsyncMdnWebhook($tenant, $connection, $asyncMdnBody)
                ->assertOk()
                ->assertJson([
                    'status' => 'failed',
                    'transmission_mdn_id' => $mdn->getKey(),
                ]);

            $exceptions = EpcisException::query()
                ->where('document_id', $document->getKey())
                ->where('exception_type', 'PARTNER_REJECTED_FILE')
                ->where('status', 'open')
                ->get();

            $this->assertCount(1, $exceptions);
            $this->exceptionIds[] = (int) $exceptions->first()->getKey();
            $this->assertStringContainsString('authentication-failed', (string) $exceptions->first()->description);

            // Second signal for same document must de-dupe (e.g. operator re-run / double emit).
            app(RecordOperationalEpcisCatalogSignal::class)->partnerRejected($document, 'duplicate');

            $this->assertSame(
                1,
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('exception_type', 'PARTNER_REJECTED_FILE')
                    ->where('status', 'open')
                    ->count(),
            );
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function sync_failed_mdn_creates_partner_rejected_file(): void
    {
        $this->initializeDemo2Tenant();

        try {
            $mdnBody = "Reporting-UA: partner-as2.example\r\nOriginal-Message-ID: <test@tracepharma>\r\nDisposition: automatic-action/MDN-sent-automatically; failed/failure: decryption-failed";

            Http::fake([
                'https://partner-as2.example/as2' => Http::response($mdnBody, 200, [
                    'Content-Type' => 'multipart/report; report-type=disposition-notification',
                ]),
            ]);

            $connection = OutboundConnection::query()->create([
                'name' => 'Partner AS2 Failed MDN Catalog',
                'serialization_provider' => SerializationProvider::CustomHttps,
                'transport' => OutboundTransport::As2,
                'is_active' => true,
                'settings' => [
                    'as2_url' => 'https://partner-as2.example/as2',
                    'as2_from' => 'tracepharma-as2-id',
                    'as2_to' => 'partner-as2-id',
                    'as2_mdn_ack_mode' => As2MdnAckMode::Sync->value,
                ],
            ]);
            $this->connectionId = (int) $connection->getKey();

            $document = $this->createOutboundDocument($connection);

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $exceptions = EpcisException::query()
                ->where('document_id', $document->getKey())
                ->where('exception_type', 'PARTNER_REJECTED_FILE')
                ->where('status', 'open')
                ->get();

            $this->assertCount(1, $exceptions);
            $this->exceptionIds[] = (int) $exceptions->first()->getKey();

            $mdn = TransmissionMdn::query()->where('document_id', $document->getKey())->first();
            $this->assertNotNull($mdn);
            $this->transmissionMdnIds[] = (int) $mdn->getKey();
            $this->assertSame('failed', $mdn->mdn_status);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function emit_pending_mdn_signals_emits_missing_then_late_with_dedupe(): void
    {
        $this->initializeDemo2Tenant();

        try {
            config([
                'tracepharma.as2_mdn.missing_after_hours' => 24,
                'tracepharma.as2_mdn.late_after_hours' => 72,
            ]);

            $missingDoc = $this->createOutboundDocument();
            $lateDoc = $this->createOutboundDocument();
            $freshDoc = $this->createOutboundDocument();

            $missingMdn = TransmissionMdn::query()->create([
                'document_id' => $missingDoc->getKey(),
                'mdn_status' => 'pending',
                'mdn_payload' => ['message_id' => 'missing-'.Str::uuid()],
            ]);
            $missingMdn->forceFill(['created_at' => now()->subHours(30)])->save();
            $this->transmissionMdnIds[] = (int) $missingMdn->getKey();

            $lateMdn = TransmissionMdn::query()->create([
                'document_id' => $lateDoc->getKey(),
                'mdn_status' => 'pending',
                'mdn_payload' => ['message_id' => 'late-'.Str::uuid()],
            ]);
            $lateMdn->forceFill(['created_at' => now()->subHours(80)])->save();
            $this->transmissionMdnIds[] = (int) $lateMdn->getKey();

            $freshMdn = TransmissionMdn::query()->create([
                'document_id' => $freshDoc->getKey(),
                'mdn_status' => 'pending',
                'mdn_payload' => ['message_id' => 'fresh-'.Str::uuid()],
            ]);
            $this->transmissionMdnIds[] = (int) $freshMdn->getKey();

            tenancy()->end();

            $this->artisan('epcis:emit-pending-mdn-signals', [
                '--tenant' => self::DEMO2_TENANT_ID,
            ])->assertSuccessful();

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

            $this->assertSame(
                1,
                EpcisException::query()
                    ->where('document_id', $missingDoc->getKey())
                    ->where('exception_type', 'MISSING_MDN')
                    ->where('status', 'open')
                    ->count(),
            );
            $this->assertSame(
                0,
                EpcisException::query()
                    ->where('document_id', $missingDoc->getKey())
                    ->where('exception_type', 'LATE_MDN')
                    ->count(),
            );

            $this->assertSame(
                1,
                EpcisException::query()
                    ->where('document_id', $lateDoc->getKey())
                    ->where('exception_type', 'LATE_MDN')
                    ->where('status', 'open')
                    ->count(),
            );
            $this->assertSame(
                0,
                EpcisException::query()
                    ->where('document_id', $lateDoc->getKey())
                    ->where('exception_type', 'MISSING_MDN')
                    ->count(),
            );

            $this->assertSame(
                0,
                EpcisException::query()
                    ->where('document_id', $freshDoc->getKey())
                    ->whereIn('exception_type', ['MISSING_MDN', 'LATE_MDN'])
                    ->count(),
            );

            $this->exceptionIds = array_merge(
                $this->exceptionIds,
                EpcisException::query()
                    ->whereIn('document_id', [$missingDoc->getKey(), $lateDoc->getKey()])
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all(),
            );

            tenancy()->end();

            $this->artisan('epcis:emit-pending-mdn-signals', [
                '--tenant' => self::DEMO2_TENANT_ID,
            ])->assertSuccessful();

            tenancy()->initialize(Tenant::query()->findOrFail(self::DEMO2_TENANT_ID));

            $this->assertSame(
                1,
                EpcisException::query()
                    ->where('document_id', $missingDoc->getKey())
                    ->where('exception_type', 'MISSING_MDN')
                    ->where('status', 'open')
                    ->count(),
            );
            $this->assertSame(
                1,
                EpcisException::query()
                    ->where('document_id', $lateDoc->getKey())
                    ->where('exception_type', 'LATE_MDN')
                    ->where('status', 'open')
                    ->count(),
            );
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    /**
     * @return array{0: OutboundConnection, 1: EpcisDocument, 2: TransmissionMdn, 3: string}
     */
    private function transmitAsyncMdnFixture(): array
    {
        $capturedMessageId = null;

        Http::fake([
            'https://partner-as2.example/as2' => Http::response('', 200),
        ]);

        $connection = OutboundConnection::query()->create([
            'name' => 'Partner AS2 Async MDN Catalog',
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => OutboundTransport::As2,
            'is_active' => true,
            'settings' => [
                'as2_url' => 'https://partner-as2.example/as2',
                'as2_from' => 'tracepharma-as2-id',
                'as2_to' => 'partner-as2-id',
                'as2_mdn_ack_mode' => As2MdnAckMode::Async->value,
                'disposition_notification_to' => 'https://tracepharma.example/as2/mdn',
            ],
            'credentials' => [
                'as2_mdn_webhook_secret' => self::AS2_MDN_WEBHOOK_SECRET,
            ],
        ]);
        $this->connectionId = (int) $connection->getKey();

        $document = $this->createOutboundDocument($connection);

        app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

        Http::assertSent(function ($request) use (&$capturedMessageId): bool {
            $messageId = $request->header('Message-ID')[0] ?? null;

            if (is_string($messageId) && $messageId !== '') {
                $capturedMessageId = $messageId;
            }

            return true;
        });

        $this->assertNotNull($capturedMessageId);

        $mdn = TransmissionMdn::query()
            ->where('document_id', $document->getKey())
            ->first();

        $this->assertNotNull($mdn);
        $this->transmissionMdnIds[] = (int) $mdn->getKey();
        $this->assertSame('pending', $mdn->mdn_status);

        return [$connection, $document, $mdn, $capturedMessageId];
    }

    private function createOutboundDocument(?OutboundConnection $connection = null): EpcisDocument
    {
        $path = 'epcis/outbound/test-mdn-catalog-'.Str::uuid().'.xml';
        $xml = '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>';
        Storage::disk('local')->put($path, $xml);

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => 'test-mdn-catalog.xml',
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', $xml),
            'dscsa_affirm' => false,
            'status' => 'parsed',
            'reprocess_count' => 0,
            'event_count' => 1,
            'epc_count' => 0,
            'received_at' => now(),
            'outbound_connection_id' => $connection?->getKey(),
        ]);

        // Track latest for cleanup; multi-doc tests clean via transmission + exception queries.
        $this->documentId = (int) $document->getKey();

        return $document;
    }

    private function postAsyncMdnWebhook(Tenant $tenant, OutboundConnection $connection, string $body): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            "/api/webhooks/as2/mdn/{$tenant->getKey()}/{$connection->getKey()}",
            content: $body,
            server: [
                'CONTENT_TYPE' => 'multipart/report; report-type=disposition-notification',
                'HTTP_X_AS2_MDN_SECRET' => self::AS2_MDN_WEBHOOK_SECRET,
            ],
        );
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
            $tenant = Tenant::query()->find(self::DEMO2_TENANT_ID);
            if ($tenant !== null) {
                tenancy()->initialize($tenant);
            }
        }

        if (! tenancy()->initialized) {
            return;
        }

        if ($this->exceptionIds !== []) {
            EpcisException::query()->whereIn('id', $this->exceptionIds)->delete();
            $this->exceptionIds = [];
        }

        if ($this->transmissionMdnIds !== []) {
            TransmissionMdn::query()->whereIn('id', $this->transmissionMdnIds)->delete();
            $this->transmissionMdnIds = [];
        }

        if ($this->documentId !== null) {
            EpcisException::query()->where('document_id', $this->documentId)->delete();
            TransmissionMdn::query()->where('document_id', $this->documentId)->delete();
            EpcisDocument::query()->whereKey($this->documentId)->delete();
            $this->documentId = null;
        }

        // Multi-document tests may leave extras — sweep by payload path prefix.
        $docs = EpcisDocument::query()
            ->where('original_filename', 'test-mdn-catalog.xml')
            ->pluck('id');

        if ($docs->isNotEmpty()) {
            EpcisException::query()->whereIn('document_id', $docs)->delete();
            TransmissionMdn::query()->whereIn('document_id', $docs)->delete();
            EpcisDocument::query()->whereIn('id', $docs)->delete();
        }

        if ($this->connectionId !== null) {
            OutboundConnection::query()->whereKey($this->connectionId)->delete();
            $this->connectionId = null;
        }
    }
}
