<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Actions\Epcis\ArchiveAgedEpcisEvents;
use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundPreTransmitValidationTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $connectionId = null;

    /** @var list<int> */
    private array $documentIds = [];

    #[Test]
    public function schema_valid_outbound_payload_transmits(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = $this->createHttpsConnection();
            $document = $this->createOutboundDocument($connection, $this->schemaValidOutboundXml());

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('sent', $document->transmission_status);
            $this->assertNotNull($document->sent_at);
            $this->assertSame('validated', $document->status);
            Http::assertSentCount(1);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function schema_invalid_outbound_payload_does_not_transmit_and_opens_exception(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = $this->createHttpsConnection();
            $badXml = '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>';
            $document = $this->createOutboundDocument($connection, $badXml);

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('failed', $document->transmission_status);
            $this->assertNull($document->sent_at);
            $this->assertSame('error', $document->status);
            $this->assertStringContainsString('Pre-transmit EPCIS validation failed', (string) $document->error_message);
            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('status', 'open')
                    ->where('exception_type', 'INGESTION_PARSE_ERROR')
                    ->exists(),
            );
            Http::assertNothingSent();
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function blocking_business_rule_on_outbound_document_does_not_transmit(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = $this->createHttpsConnection();
            $document = $this->createOutboundDocument($connection, $this->schemaValidOutboundXml());

            DB::table('epcis_events')->insert([
                'document_id' => $document->getKey(),
                'ingest_generation' => (int) ($document->ingest_generation ?? 1),
                'event_id' => (string) Str::uuid(),
                'event_type' => 'ObjectEvent',
                'event_time' => now()->addYears(2)->toDateTimeString(),
                'action' => 'OBSERVE',
                'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
                'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('failed', $document->transmission_status);
            $this->assertNull($document->sent_at);
            $this->assertTrue(
                EpcisException::query()
                    ->where('document_id', $document->getKey())
                    ->where('status', 'open')
                    ->where('exception_type', 'FUTURE_EVENT_TIME')
                    ->exists(),
            );
            Http::assertNothingSent();
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function failed_validation_retry_does_not_double_send(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = $this->createHttpsConnection();
            $badXml = '<?xml version="1.0"?><epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"></epcis:EPCISDocument>';
            $document = $this->createOutboundDocument($connection, $badXml);

            $transmitter = app(OutboundEpcisTransmitter::class);
            $transmitter->transmit($document->fresh());
            $transmitter->transmit($document->fresh());
            $transmitter->transmit($document->fresh(), forceRetransmit: true);

            $document->refresh();
            $this->assertSame('failed', $document->transmission_status);
            $this->assertNull($document->sent_at);
            Http::assertNothingSent();
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function replay_accepted_event_id_does_not_transmit_second_file(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = $this->createHttpsConnection();
            $eventId = 'urn:uuid:'.(string) Str::uuid();
            $first = $this->createOutboundDocument($connection, $this->schemaValidOutboundXml());
            $this->insertLiveEvent($first, $eventId);

            $transmitter = app(OutboundEpcisTransmitter::class);
            $transmitter->transmit($first->fresh());
            $first->refresh();
            $this->assertSame('sent', $first->transmission_status);
            Http::assertSentCount(1);

            // Age + MOVE so live_event_id unique frees the id; archive must still block replay.
            config(['tracepharma.epcis.retention_years' => 1]);
            DB::table('epcis_events')
                ->where('document_id', $first->getKey())
                ->update(['event_time' => now()->subYears(2)->toDateTimeString()]);
            app(ArchiveAgedEpcisEvents::class)->handle();

            $second = $this->createOutboundDocument($connection, $this->schemaValidOutboundXml());
            $this->insertLiveEvent($second, $eventId);
            $transmitter->transmit($second->fresh());

            $second->refresh();
            $this->assertNull($second->sent_at);
            $this->assertNotSame('sent', $second->transmission_status);
            Http::assertSentCount(1);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function new_event_id_transmits_once(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = $this->createHttpsConnection();
            $document = $this->createOutboundDocument($connection, $this->schemaValidOutboundXml());
            $this->insertLiveEvent($document, 'urn:uuid:'.(string) Str::uuid());

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('sent', $document->transmission_status);
            $this->assertNotNull($document->sent_at);
            Http::assertSentCount(1);
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function successful_transmit_replay_without_force_does_not_double_send(): void
    {
        $this->initializeDemo2Tenant();

        try {
            Http::fake([
                'https://partner.example/epcis' => Http::response('OK', 202),
            ]);

            $connection = $this->createHttpsConnection();
            $document = $this->createOutboundDocument($connection, $this->schemaValidOutboundXml());

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

    private function createHttpsConnection(): OutboundConnection
    {
        $connection = OutboundConnection::query()->create([
            'name' => 'Pre-transmit HTTPS',
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => OutboundTransport::Https,
            'is_active' => true,
            'settings' => ['endpoint_url' => 'https://partner.example/epcis'],
            'credentials' => ['webhook_token' => 'outbound-token'],
        ]);
        $this->connectionId = (int) $connection->getKey();

        return $connection;
    }

    private function createOutboundDocument(OutboundConnection $connection, string $xml): EpcisDocument
    {
        $path = 'epcis/outbound/pretransmit-'.Str::uuid().'.xml';
        Storage::disk('local')->put($path, $xml);

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => 'pretransmit.xml',
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

    private function insertLiveEvent(EpcisDocument $document, string $eventId, bool $superseded = false): void
    {
        DB::table('epcis_events')->insert([
            'document_id' => $document->getKey(),
            'ingest_generation' => (int) ($document->ingest_generation ?? 1),
            'event_id' => $eventId,
            'event_type' => 'ObjectEvent',
            'event_time' => now()->subMinute()->toDateTimeString(),
            'action' => 'OBSERVE',
            'biz_step' => 'urn:epcglobal:cbv:bizstep:shipping',
            'disposition' => 'urn:epcglobal:cbv:disp:in_transit',
            'superseded_at' => $superseded ? now()->toDateTimeString() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            $eventIds = DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->pluck('id')->all();
            $archiveIds = Schema::hasTable('epcis_events_archive')
                ? DB::table('epcis_events_archive')->whereIn('document_id', $this->documentIds)->pluck('id')->all()
                : [];
            $allEventIds = array_values(array_unique(array_map('intval', [...$eventIds, ...$archiveIds])));
            if ($allEventIds !== []) {
                foreach (['event_epcs_archive', 'event_parties_archive', 'event_locations_archive', 'event_biz_transactions_archive', 'event_quantities_archive', 'event_epc_ilmd_archive'] as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->whereIn('event_id', $allEventIds)->delete();
                    }
                }
                if (Schema::hasTable('epcis_events_archive')) {
                    DB::table('epcis_events_archive')->whereIn('id', $allEventIds)->delete();
                }
            }
            DB::table('epcis_events')->whereIn('document_id', $this->documentIds)->delete();
            EpcisDocument::query()->whereIn('id', $this->documentIds)->delete();
            $this->documentIds = [];
        }

        if ($this->connectionId !== null) {
            OutboundConnection::query()->whereKey($this->connectionId)->delete();
            $this->connectionId = null;
        }
    }
}
