<?php

declare(strict_types=1);

namespace App\Services\Epcis\Outbound;

/**
 * Minimal EPCIS 1.2 document wrapper for authored SSCC / outbound event XML.
 */
final class OutboundEpcisXmlBuilder
{
    public function buildDocument(string $eventTime, string $eventsXml, ?string $correlationId = null): string
    {
        $escapedTime = htmlspecialchars($eventTime, ENT_XML1);
        $header = $this->buildEpcisHeaderXml($correlationId);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"
    xmlns:sbdh="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader"
    creationDate="{$escapedTime}" schemaVersion="1.2">
{$header}    <EPCISBody>
        <EventList>
{$eventsXml}
        </EventList>
    </EPCISBody>
</epcis:EPCISDocument>
XML;
    }

    public function buildEpcisHeaderXml(?string $correlationId): string
    {
        if ($correlationId === null || trim($correlationId) === '') {
            return '';
        }

        $escaped = htmlspecialchars($correlationId, ENT_XML1);

        return <<<XML
    <EPCISHeader>
        <extension>
            <tracepharma:OutboundCorrelation xmlns:tracepharma="https://tracepharma.com/epcis">{$escaped}</tracepharma:OutboundCorrelation>
        </extension>
    </EPCISHeader>

XML;
    }
}
