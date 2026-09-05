<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Integrations;

use App\Enums\OutboundTransport;
use App\Enums\SerializationProvider;
use App\Models\OutboundConnection;
use App\Support\Integrations\OutboundTransportAvailability;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboundTransportAvailabilityTest extends TestCase
{
    #[Test]
    public function sftp_outbound_transport_is_selectable(): void
    {
        $this->assertTrue(OutboundTransportAvailability::isSelectable(OutboundTransport::Sftp));
        $this->assertTrue(OutboundTransportAvailability::isSelectable(OutboundTransport::Https));
        $this->assertTrue(OutboundTransportAvailability::isSelectable(OutboundTransport::As2));
    }

    #[Test]
    public function assert_savable_allows_new_sftp_connection(): void
    {
        $connection = new OutboundConnection([
            'name' => 'Partner SFTP',
            'serialization_provider' => SerializationProvider::CustomSftp,
            'transport' => OutboundTransport::Sftp,
            'is_active' => true,
        ]);

        OutboundTransportAvailability::assertSavable($connection);

        $this->assertTrue($connection->is_active);
    }

    #[Test]
    public function assert_savable_allows_changing_transport_to_sftp(): void
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

        OutboundTransportAvailability::assertSavable($connection);

        $this->assertSame(OutboundTransport::Sftp, $connection->transport);
    }

    #[Test]
    public function assert_savable_allows_activating_sftp_connection(): void
    {
        $connection = new OutboundConnection([
            'name' => 'SFTP',
            'serialization_provider' => SerializationProvider::CustomSftp,
            'transport' => OutboundTransport::Sftp,
            'is_active' => false,
        ]);
        $connection->exists = true;
        $connection->syncOriginal();
        $connection->is_active = true;

        OutboundTransportAvailability::assertSavable($connection);

        $this->assertTrue($connection->is_active);
    }

    #[Test]
    public function sftp_outbound_transport_is_not_legacy_unavailable(): void
    {
        $this->assertFalse(OutboundTransportAvailability::isLegacyUnavailable(OutboundTransport::Sftp));
        $this->assertFalse(OutboundTransportAvailability::isLegacyUnavailable(OutboundTransport::Https));
        $this->assertFalse(OutboundTransportAvailability::isLegacyUnavailable(OutboundTransport::As2));
    }

    #[Test]
    public function assert_transmittable_allows_sftp(): void
    {
        $connection = new OutboundConnection([
            'transport' => OutboundTransport::Sftp,
        ]);

        OutboundTransportAvailability::assertTransmittable($connection);

        $this->assertSame(OutboundTransport::Sftp, $connection->transport);
    }
}
