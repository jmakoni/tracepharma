<?php

declare(strict_types=1);

namespace App\Services\Epcis\Outbound;

use App\Support\Epcis\ShippingTiTsFragments;
use App\Support\Gs1\Sgln;
use DomainException;

/**
 * Minimal EPCIS 1.2 document wrapper for authored SSCC / outbound event XML.
 */
final class OutboundEpcisXmlBuilder
{
    private const PLACEHOLDER_GLN = '0000000000000';

    public function buildDocument(
        string $eventTime,
        string $eventsXml,
        ?string $correlationId = null,
        ?string $senderGln = null,
        ?string $receiverGln = null,
    ): string {
        $escapedTime = htmlspecialchars($eventTime, ENT_XML1);
        $header = $this->buildEpcisHeaderXml($correlationId, $eventTime, $senderGln, $receiverGln);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"
    xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader"
    xmlns:tracepharma="https://tracepharma.com/epcis"
    creationDate="{$escapedTime}" schemaVersion="1.2">
{$header}    <EPCISBody>
        <EventList>
{$eventsXml}
        </EventList>
    </EPCISBody>
</epcis:EPCISDocument>
XML;
    }

    public function buildEpcisHeaderXml(
        ?string $correlationId,
        ?string $creationDate = null,
        ?string $senderGln = null,
        ?string $receiverGln = null,
    ): string {
        if ($correlationId === null || trim($correlationId) === '') {
            return '';
        }

        $trimmed = trim($correlationId);
        $escaped = htmlspecialchars($trimmed, ENT_XML1);
        $sbdhCreationDate = htmlspecialchars($creationDate ?? now()->toIso8601String(), ENT_XML1);
        [$resolvedSender, $resolvedReceiver] = $this->requireRealGlns($senderGln, $receiverGln);

        return <<<XML
    <EPCISHeader>
{$this->sbdhXml($resolvedSender, $resolvedReceiver, $trimmed, $sbdhCreationDate)}        <tracepharma:OutboundCorrelation>{$escaped}</tracepharma:OutboundCorrelation>
    </EPCISHeader>

XML;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function requireRealGlns(?string $senderGln, ?string $receiverGln): array
    {
        $sender = Sgln::normalizeGln($senderGln);
        $receiver = Sgln::normalizeGln($receiverGln);

        if ($sender === null) {
            throw new DomainException(
                'Outbound EPCIS correlation headers require a real sender GLN (13 digits); none was provided.',
            );
        }

        if ($receiver === null) {
            throw new DomainException(
                'Outbound EPCIS correlation headers require a real receiver GLN (13 digits); none was provided.',
            );
        }

        if ($sender === self::PLACEHOLDER_GLN || $receiver === self::PLACEHOLDER_GLN) {
            throw new DomainException(
                'Outbound EPCIS correlation headers reject placeholder GLN 0000000000000; pass real org/site GLNs.',
            );
        }

        return [$sender, $receiver];
    }

    private function sbdhXml(string $senderGln, string $receiverGln, string $instanceId, string $creationDate): string
    {
        return ShippingTiTsFragments::sbdhXml(
            senderGln: $senderGln,
            receiverGln: $receiverGln,
            instanceId: $instanceId,
            creationDate: $creationDate,
        );
    }
}
