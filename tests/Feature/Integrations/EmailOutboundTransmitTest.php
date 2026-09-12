<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Enums\TenantProfile;
use App\Mail\OutboundEpcisAttachmentMail;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Epcis\TransmissionMdn;
use App\Models\OutboundConnection;
use App\Models\Tenant;
use App\Services\Epcis\Contracts\OutboundEpcisTransmitter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailOutboundTransmitTest extends TestCase
{
    private const DEMO2_TENANT_ID = '13fe9068-cb05-4bab-9e0e-a89f2a458832';

    private const DEMO2_DOMAIN = 'demo2.internal.vatengi.com';

    private const DEMO2_DATABASE = 'tenant_demo2_internal_vatengi_com';

    private static bool $demo2TenantReady = false;

    private ?int $connectionId = null;

    /** @var list<int> */
    private array $documentIds = [];

    /** @var list<string> */
    private array $payloadPaths = [];

    #[Test]
    public function successful_email_transmit_attaches_mail_and_marks_document_sent(): void
    {
        $this->initializeDemo2Tenant();
        Mail::fake();
        config(['logging.default' => 'null']);

        try {
            $connection = $this->createEmailConnection([
                'to_emails' => ['partner@example.com'],
                'max_attachment_mb' => 15,
            ]);
            $xml = $this->schemaValidOutboundXml();
            $document = $this->createOutboundDocument($connection, $xml);

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('sent', $document->transmission_status);
            $this->assertNotNull($document->sent_at);

            Mail::assertSent(OutboundEpcisAttachmentMail::class, function (OutboundEpcisAttachmentMail $mail) use ($xml): bool {
                return $mail->hasTo('partner@example.com')
                    && $mail->attachmentContent === $xml;
            });
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function missing_to_emails_marks_failed_with_to_emails_message(): void
    {
        $this->initializeDemo2Tenant();
        Mail::fake();
        config(['logging.default' => 'null']);

        try {
            $connection = $this->createEmailConnection([
                'to_emails' => [],
                'max_attachment_mb' => 15,
            ]);
            $document = $this->createOutboundDocument($connection, $this->schemaValidOutboundXml());

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('failed', $document->transmission_status);
            $this->assertStringContainsString('to_emails', (string) $document->error_message);
            Mail::assertNothingSent();
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    #[Test]
    public function oversized_payload_fails_with_portal_or_sftp_guidance(): void
    {
        $this->initializeDemo2Tenant();
        Mail::fake();
        config(['logging.default' => 'null']);

        try {
            $connection = $this->createEmailConnection([
                'to_emails' => ['partner@example.com'],
                'max_attachment_mb' => 1,
            ]);
            // Trailing whitespace keeps the EPCIS document schema-valid while exceeding the limit.
            $oversized = $this->schemaValidOutboundXml().str_repeat(' ', (1024 * 1024) + 1);
            $document = $this->createOutboundDocument($connection, $oversized);

            app(OutboundEpcisTransmitter::class)->transmit($document->fresh());

            $document->refresh();
            $this->assertSame('failed', $document->transmission_status);
            $this->assertStringContainsString('Client portal or SFTP', (string) $document->error_message);
            Mail::assertNothingSent();
        } finally {
            $this->cleanup();
            tenancy()->end();
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function createEmailConnection(array $settings): OutboundConnection
    {
        $connection = OutboundConnection::query()->create([
            'name' => 'Email outbound '.Str::random(4),
            'serialization_provider' => SerializationProvider::Other,
            'transport' => OutboundTransport::Email,
            'is_active' => true,
            'settings' => $settings,
            'credentials' => [],
        ]);
        $this->connectionId = (int) $connection->getKey();

        return $connection;
    }

    private function createOutboundDocument(OutboundConnection $connection, string $payload): EpcisDocument
    {
        $path = 'epcis/outbound/email-'.Str::uuid().'.xml';
        Storage::disk('local')->put($path, $payload);
        $this->payloadPaths[] = $path;

        $document = EpcisDocument::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'schema_version' => '1.2',
            'creation_date' => now(),
            'direction' => 'outbound',
            'format' => 'xml',
            'original_filename' => 'shipment.xml',
            'payload_disk' => 'local',
            'payload_path' => $path,
            'file_sha256' => hash('sha256', $payload),
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
        foreach ($this->payloadPaths as $path) {
            Storage::disk('local')->delete($path);
        }
        if ($this->connectionId !== null) {
            OutboundConnection::query()->whereKey($this->connectionId)->delete();
        }
        $this->documentIds = [];
        $this->payloadPaths = [];
        $this->connectionId = null;
    }
}
