<?php

declare(strict_types=1);

namespace App\Services\Epcis;

use App\Services\Epcis\Contracts\EpcisDocumentParser;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\Epcis\Validation\EpcisCbv20Mapper;
use InvalidArgumentException;
use JsonException;

/**
 * Parse EPCIS 2.0 JSON-LD into the same intermediate event arrays as Xml12.
 */
final class EpcisJsonLd20Parser implements EpcisDocumentParser
{
    /**
     * @return array{
     *     schema_version: string,
     *     creation_date: string|null,
     *     document_uuid: string|null,
     *     sender_gln: string|null,
     *     receiver_gln: string|null,
     *     dscsa_affirm: bool,
     *     legal_notice: string|null,
     *     product_classes: list<array<string, mixed>>,
     *     locations: list<array<string, mixed>>,
     *     other_vocabulary: list<array<string, mixed>>,
     *     header_json: array<string, mixed>|null,
     *     events: list<array<string, mixed>>
     * }
     */
    public function parse(string $absolutePath): array
    {
        $events = [];
        $header = $this->parseHeaderAndStream($absolutePath, function (array $event) use (&$events): void {
            $events[] = $event;
        });
        $header['events'] = $events;

        return $header;
    }

    /**
     * @param  callable(array<string, mixed>): void  $onEvent
     * @return array<string, mixed>
     */
    public function parseHeaderAndStream(string $absolutePath, callable $onEvent): array
    {
        if (! EpcisSchemaVersion::accepts20()) {
            throw new InvalidArgumentException(
                'EPCIS 2.0 JSON-LD ingest is disabled (set TRACEPHARMA_EPCIS_ACCEPT_20=true).',
            );
        }

        if (! is_readable($absolutePath)) {
            throw new InvalidArgumentException("EPCIS JSON is not readable: {$absolutePath}");
        }

        $raw = file_get_contents($absolutePath);
        if ($raw === false) {
            throw new InvalidArgumentException("Unable to read EPCIS JSON: {$absolutePath}");
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid EPCIS 2.0 JSON: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('EPCIS 2.0 root must be a JSON object.');
        }

        $type = (string) ($decoded['type'] ?? $decoded['@type'] ?? '');
        if ($type !== '' && $type !== 'EPCISDocument') {
            throw new InvalidArgumentException("Expected EPCISDocument, got [{$type}].");
        }

        $schemaVersion = (string) ($decoded['schemaVersion'] ?? EpcisSchemaVersion::V20);
        $creationDate = isset($decoded['creationDate']) ? (string) $decoded['creationDate'] : null;

        $senderGln = null;
        $receiverGln = null;
        $documentUuid = null;
        $dscsaAffirm = false;
        $legalNotice = null;
        $headerJson = null;

        $sbdh = $decoded['epcisHeader']['standardBusinessDocumentHeader']
            ?? $decoded['EPCISHeader']['StandardBusinessDocumentHeader']
            ?? null;

        if (is_array($sbdh)) {
            $senderGln = $this->extractGln($sbdh['sender'] ?? $sbdh['Sender'] ?? null);
            $receiverGln = $this->extractGln($sbdh['receiver'] ?? $sbdh['Receiver'] ?? null);
            $docId = $sbdh['documentIdentification'] ?? $sbdh['DocumentIdentification'] ?? null;
            if (is_array($docId)) {
                $documentUuid = isset($docId['instanceIdentifier'])
                    ? (string) $docId['instanceIdentifier']
                    : (isset($docId['InstanceIdentifier']) ? (string) $docId['InstanceIdentifier'] : null);
                $creationDate = isset($docId['creationDateAndTime'])
                    ? (string) $docId['creationDateAndTime']
                    : (isset($docId['CreationDateAndTime']) ? (string) $docId['CreationDateAndTime'] : $creationDate);
            }
            $headerJson = $sbdh;
        }

        $eventList = $decoded['epcisBody']['eventList']
            ?? $decoded['EPCISBody']['EventList']
            ?? [];

        if (! is_array($eventList)) {
            throw new InvalidArgumentException('EPCIS 2.0 epcisBody.eventList must be an array.');
        }

        foreach ($eventList as $event) {
            if (! is_array($event)) {
                continue;
            }
            $onEvent($this->normalizeEvent($event));
        }

        return [
            'schema_version' => $schemaVersion !== '' ? $schemaVersion : EpcisSchemaVersion::V20,
            'creation_date' => $creationDate,
            'document_uuid' => $documentUuid,
            'sender_gln' => $senderGln,
            'receiver_gln' => $receiverGln,
            'dscsa_affirm' => $dscsaAffirm,
            'legal_notice' => $legalNotice,
            'product_classes' => [],
            'locations' => [],
            'other_vocabulary' => [],
            'header_json' => $headerJson,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function normalizeEvent(array $event): array
    {
        $eventType = (string) ($event['type'] ?? $event['@type'] ?? 'ObjectEvent');
        $epcs = [];

        foreach ($this->stringList($event['epcList'] ?? null) as $uri) {
            $epcs[] = ['uri' => $uri, 'role' => 'epcList'];
        }

        $parentId = $event['parentID'] ?? $event['parentId'] ?? null;
        if (is_string($parentId) && trim($parentId) !== '') {
            $epcs[] = ['uri' => trim($parentId), 'role' => 'parentID'];
        }

        foreach ($this->stringList($event['childEPCs'] ?? $event['childEpcs'] ?? null) as $uri) {
            $epcs[] = ['uri' => $uri, 'role' => 'childEPC'];
        }

        foreach ($this->stringList($event['inputEPCList'] ?? null) as $uri) {
            $epcs[] = ['uri' => $uri, 'role' => 'inputEPC'];
        }

        foreach ($this->stringList($event['outputEPCList'] ?? null) as $uri) {
            $epcs[] = ['uri' => $uri, 'role' => 'outputEPC'];
        }

        $bizStep = EpcisCbv20Mapper::toCanonicalBizStep(
            isset($event['bizStep']) ? (string) $event['bizStep'] : null,
        );
        $disposition = EpcisCbv20Mapper::toCanonicalDisposition(
            isset($event['disposition']) ? (string) $event['disposition'] : null,
        );

        $readPointUri = $this->locationId($event['readPoint'] ?? null);
        $bizLocationUri = $this->locationId($event['bizLocation'] ?? null);

        $bizTransactions = [];
        $btList = $event['bizTransactionList'] ?? [];
        if (is_array($btList)) {
            foreach ($btList as $bt) {
                if (! is_array($bt)) {
                    continue;
                }
                $bizTransactions[] = [
                    'type' => isset($bt['type']) ? (string) $bt['type'] : null,
                    'biz_transaction' => isset($bt['bizTransaction'])
                        ? (string) $bt['bizTransaction']
                        : (isset($bt['value']) ? (string) $bt['value'] : null),
                ];
            }
        }

        $ilmd = null;
        $ilmdSource = $event['ilmd'] ?? null;
        if (is_array($ilmdSource)) {
            $ilmd = [
                'lot_number' => isset($ilmdSource['lotNumber']) ? (string) $ilmdSource['lotNumber'] : null,
                'expiry_date' => isset($ilmdSource['itemExpirationDate']) ? (string) $ilmdSource['itemExpirationDate'] : null,
                'manufacturing_date' => isset($ilmdSource['manufacturingDate']) ? (string) $ilmdSource['manufacturingDate'] : null,
                'best_before_date' => isset($ilmdSource['bestBeforeDate']) ? (string) $ilmdSource['bestBeforeDate'] : null,
                'additional_id' => isset($ilmdSource['additionalId']) ? (string) $ilmdSource['additionalId'] : null,
                'extra_json' => null,
            ];
        }

        return [
            'event_type' => $eventType,
            'event_id' => isset($event['eventID']) ? trim((string) $event['eventID']) : (isset($event['eventId']) ? trim((string) $event['eventId']) : null),
            'event_time' => isset($event['eventTime']) ? trim((string) $event['eventTime']) : null,
            'record_time' => isset($event['recordTime']) ? trim((string) $event['recordTime']) : null,
            'event_timezone_offset' => isset($event['eventTimeZoneOffset']) ? trim((string) $event['eventTimeZoneOffset']) : null,
            'action' => isset($event['action']) ? strtoupper(trim((string) $event['action'])) : 'ADD',
            'biz_step' => $bizStep,
            'disposition' => $disposition,
            'persistent_disposition' => null,
            'transformation_id' => isset($event['transformationID']) ? trim((string) $event['transformationID']) : null,
            'read_point_uri' => $readPointUri,
            'biz_location_uri' => $bizLocationUri,
            'epcs' => $epcs,
            'quantities' => [],
            'class_quantities' => [],
            'biz_transactions' => $bizTransactions,
            'parties' => [],
            'ilmd' => $ilmd,
            'extension_json' => null,
            'error_declaration' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            } elseif (is_array($item) && isset($item['epc']) && is_string($item['epc']) && trim($item['epc']) !== '') {
                $out[] = trim($item['epc']);
            }
        }

        return $out;
    }

    private function locationId(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_array($value) && isset($value['id']) && is_string($value['id']) && trim($value['id']) !== '') {
            return trim($value['id']);
        }

        return null;
    }

    private function extractGln(mixed $party): ?string
    {
        if (! is_array($party)) {
            return null;
        }

        $identifier = $party['identifier'] ?? $party['Identifier'] ?? null;
        if (is_string($identifier) && trim($identifier) !== '') {
            return trim($identifier);
        }

        if (is_array($identifier)) {
            if (isset($identifier['value']) && is_string($identifier['value'])) {
                return trim($identifier['value']);
            }
            if (isset($identifier[0]) && is_string($identifier[0])) {
                return trim($identifier[0]);
            }
        }

        return null;
    }
}
