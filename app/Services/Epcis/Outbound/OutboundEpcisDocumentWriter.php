<?php

declare(strict_types=1);

namespace App\Services\Epcis\Outbound;

/**
 * Edge writer for authored EPCIS documents (1.2 XML default; 2.0 JSON-LD opt-in).
 */
interface OutboundEpcisDocumentWriter
{
    public function schemaVersion(): string;

    public function format(): string;

    /**
     * Wrap event payload fragments into a full EPCIS document.
     *
     * @param  string  $eventsPayload  XML EventList children (1.2) or JSON events array string (2.0)
     */
    public function buildDocument(
        string $eventTime,
        string $eventsPayload,
        ?string $correlationId = null,
        ?string $senderGln = null,
        ?string $receiverGln = null,
    ): string;
}
