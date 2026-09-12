<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\TransmissionMdn;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Services\Epcis\ConnectionOutboundEpcisTransmitter;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HTTPS outbound at-most-once delivery.
 *
 * Approach: after a successful HTTPS POST, persist a TransmissionMdn row with
 * mdn_status=https_ack before marking the document sent. On retry,
 * recoverSentFromPersistedEvidence (and the early sent-status no-op) skip a
 * second POST when the partner already accepted the payload but the DB mark
 * failed. Mirrors the AS2 MDN recovery path.
 */
class HttpsOutboundTransmitIdempotencyTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $connectionId = null;

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function already_sent_https_document_does_not_post_again(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = $this->createHttpsConnection();
            $document = $this->createOutboundDocument($connection);

            $transmitter = app(OutboundEpcisTransmitter::class);
            $transmitter->transmit($document->fresh());
            $transmitter->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('sent', $document->transmission_status);
            Http::assertSentCount(1);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function https_ack_evidence_recovers_without_second_post_after_mark_sent_failure(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = $this->createHttpsConnection();
            $document = $this->createOutboundDocument($connection);

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());
            $document->refresh();
            $this->assertSame('sent', $document->transmission_status);

            $mdn = TransmissionMdn::query()
                ->where('document_id', $document->getKey())
                ->where('mdn_status', 'https_ack')
                ->first();
            $this->assertNotNull($mdn, 'HTTPS send must persist https_ack evidence');

            // Simulate crash after HTTPS success / evidence write but before durable sent mark.
            $document->forceFill([
                'transmission_status' => 'sending',
                'sent_at' => null,
            ])->save();

            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            /** @var ConnectionOutboundEpcisTransmitter $transmitter */
            $transmitter = app(OutboundEpcisTransmitter::class);
            $this->assertTrue($transmitter->recoverSentFromPersistedEvidence($document->fresh()));
            $transmitter->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('sent', $document->transmission_status);
            $this->assertNotNull($document->sent_at);
            Http::assertSentCount(0);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    private function createHttpsConnection(): OutboundConnection
    {
        $connection = OutboundConnection::query()->create([
            'name' => 'HTTPS Idempotency '.Str::random(4),
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => OutboundTransport::Https,
            'is_active' => true,
            'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            'credentials' => ['webhook_token' => 'outbound-token'],
        ]);
        $this->connectionId = (int) $connection->getKey();

        return $connection;
    }

    private function createOutboundDocument(OutboundConnection $connection): EpcisDocument
    {
        $xml = $this->schemaValidOutboundXml();
        $path = 'epcis/outbound/idempotency-'.Str::uuid().'.xml';
        Storage::disk('local')->put($path, $xml);

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => 'shipment.xml',
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', $xml),
            'dscsa_affirm' => true,
            'status' => 'parsed',
            'reprocess_count' => 0,
            'event_count' => 1,
            'epc_count' => 1,
            'received_at' => now(),
            'outbound_connection_id' => $connection->getKey(),
        ]);
        $this->documentIds[] = (int) $document->getKey();

        return $document;
    }

    private function schemaValidOutboundXml(): string
    {
        $xml = file_get_contents(base_path('tests/Fixtures/epcis/minimal_object_shipping.xml'));
        $this->assertNotFalse($xml);

        return str_replace(
            '11111111-2222-3333-4444-555555555555',
            (string) Str::uuid(),
            $xml,
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
        if ($this->documentIds !== []) {
            EpcisException::query()->whereIn('document_id', $this->documentIds)->delete();
            TransmissionMdn::query()->whereIn('document_id', $this->documentIds)->delete();
            if (Schema::hasTable('epcis_events')) {
                DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();
            }
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
        }
        if ($this->connectionId !== null) {
            OutboundConnection::query()->whereKey($this->connectionId)->delete();
        }
        $this->documentIds = [];
        $this->connectionId = null;
    }
}
