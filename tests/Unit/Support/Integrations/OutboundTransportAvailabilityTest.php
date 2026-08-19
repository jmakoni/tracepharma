<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Integrations;

use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Models\OutboundConnection;
use App\Support\Integrations\OutboundTransportAvailability;
use DomainException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundTransportAvailabilityTest extends TestCase
{
    #[Test]
    public function sftp_outbound_transport_is_not_selectable_for_new_connections(): void
    {
        $this->assertFalse(OutboundTransportAvailability::isSelectable(OutboundTransport::Sftp));
        $this->assertTrue(OutboundTransportAvailability::isSelectable(OutboundTransport::Https));
    }

    #[Test]
    public function assert_savable_rejects_new_connection_with_sftp_transport(): void
    {
        $connection = new OutboundConnection([
            'name' => 'Legacy-style SFTP',
            'serialization_provider' => SerializationProvider::TraceLink,
            'transport' => OutboundTransport::Sftp,
            'is_active' => true,
        ]);

        try {
            OutboundTransportAvailability::assertSavable($connection);
            $this->fail('Expected ValidationException when saving a new SFTP outbound connection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('transport', $e->errors());
            $this->assertStringContainsString('SFTP outbound', $e->errors()['transport'][0]);
        }
    }

    #[Test]
    public function assert_savable_rejects_changing_transport_to_sftp_on_existing_connection(): void
    {
        $connection = new OutboundConnection([
            'name' => 'HTTPS connection',
            'serialization_provider' => SerializationProvider::CustomHttps,
            'transport' => OutboundTransport::Https,
            'is_active' => true,
        ]);
        $connection->exists = true;
        $connection->syncOriginal();
        $connection->transport = OutboundTransport::Sftp;

        $this->expectException(ValidationException::class);

        OutboundTransportAvailability::assertSavable($connection);
    }

    #[Test]
    public function assert_savable_rejects_activating_legacy_sftp_connection(): void
    {
        $connection = new OutboundConnection([
            'name' => 'Legacy SFTP',
            'serialization_provider' => SerializationProvider::TraceLink,
            'transport' => OutboundTransport::Sftp,
            'is_active' => false,
        ]);
        $connection->exists = true;
        $connection->syncOriginal();
        $connection->is_active = true;

        try {
            OutboundTransportAvailability::assertSavable($connection);
            $this->fail('Expected ValidationException when activating a legacy SFTP outbound connection.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_active', $e->errors());
        }
    }

    #[Test]
    public function sftp_outbound_transport_is_legacy_unavailable(): void
    {
        $this->assertTrue(OutboundTransportAvailability::isLegacyUnavailable(OutboundTransport::Sftp));
        $this->assertFalse(OutboundTransportAvailability::isLegacyUnavailable(OutboundTransport::Https));
        $this->assertFalse(OutboundTransportAvailability::isLegacyUnavailable(OutboundTransport::As2));
    }

    #[Test]
    public function assert_savable_allows_deactivating_legacy_sftp_connection(): void
    {
        $connection = new OutboundConnection([
            'name' => 'Legacy SFTP',
            'serialization_provider' => SerializationProvider::TraceLink,
            'transport' => OutboundTransport::Sftp,
            'is_active' => true,
        ]);
        $connection->exists = true;
        $connection->syncOriginal();
        $connection->is_active = false;

        OutboundTransportAvailability::assertSavable($connection);

        $this->assertFalse($connection->is_active);
    }

    #[Test]
    public function assert_transmittable_throws_clear_domain_exception_for_sftp(): void
    {
        $connection = new OutboundConnection([
            'transport' => OutboundTransport::Sftp,
        ]);

        try {
            OutboundTransportAvailability::assertTransmittable($connection);
            $this->fail('Expected DomainException for SFTP outbound transmit.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('SFTP outbound', $e->getMessage());
            $this->assertStringContainsString('HTTPS', $e->getMessage());
        }
    }
}
