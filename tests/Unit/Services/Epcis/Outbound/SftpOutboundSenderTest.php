<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Epcis\Outbound;

use App\Enums\OutboundTransport;
use App\Models\OutboundConnection;
use App\Services\Epcis\Outbound\SftpOutboundSender;
use App\Support\Integrations\OutboundTransportAvailability;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SftpOutboundSenderTest extends TestCase
{
    #[Test]
    public function send_throws_actionable_domain_exception(): void
    {
        $connection = new OutboundConnection([
            'transport' => OutboundTransport::Sftp,
        ]);

        try {
            app(SftpOutboundSender::class)->send($connection, '<epcis/>', 'shipment.xml');
            $this->fail('Expected DomainException from SFTP outbound sender.');
        } catch (DomainException $e) {
            $this->assertSame(OutboundTransportAvailability::sftpTransmitMessage(), $e->getMessage());
        }
    }
}
