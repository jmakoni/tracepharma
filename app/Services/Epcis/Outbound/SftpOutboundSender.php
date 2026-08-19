<?php

namespace App\Services\Epcis\Outbound;

use App\Models\OutboundConnection;
use App\Support\Integrations\OutboundTransportAvailability;
use DomainException;

final class SftpOutboundSender
{
    public function send(OutboundConnection $connection, string $content, string $filename): void
    {
        throw new DomainException(OutboundTransportAvailability::sftpTransmitMessage());
    }
}
