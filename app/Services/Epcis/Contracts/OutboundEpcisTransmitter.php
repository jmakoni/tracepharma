<?php

namespace App\Services\Epcis\Contracts;

use App\Models\Epcis\EpcisDocument;

interface OutboundEpcisTransmitter
{
    public function transmit(EpcisDocument $document, bool $forceRetransmit = false): void;
}
