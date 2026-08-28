<?php

declare(strict_types=1);

namespace App\Services\Epcis\Outbound;

use App\Domain\Epcis\Data\AggregationEventData;
use App\Domain\Epcis\Data\ObjectEventData;
use App\Support\Epcis\EpcisSchemaVersion;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * EPCIS 2.0 JSON-LD writer from Domain event DTOs (opt-in outbound).
 */
final class JsonLd20Writer implements OutboundEpcisDocumentWriter
{
    public function schemaVersion(): string
    {
        return EpcisSchemaVersion::V20;
    }

    public function format(): string
    {
        return EpcisSchemaVersion::FORMAT_JSON;
    }

    public function buildDocument(string $eventTime, string $eventsPayload, ?string $correlationId = null): string
    {
        $decoded = json_decode($eventsPayload, true);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('JsonLd20Writer expects a JSON array of events as eventsPayload.');
        }

        return $this->encodeDocument($eventTime, $decoded, $correlationId);
    }

    /**
     * @param  list<ObjectEventData|AggregationEventData|array<string, mixed>>  $events
     */
    public function buildFromDomainEvents(
        array $events,
        DateTimeInterface|string|null $creationDate = null,
        ?string $correlationId = null,
    ): string {
        $mapped = [];
        foreach ($events as $event) {
            $mapped[] = match (true) {
                $event instanceof ObjectEventData => $this->mapObjectEvent($event),
                $event instanceof AggregationEventData => $this->mapAggregationEvent($event),
                is_array($event) => $event,
                default => throw new InvalidArgumentException('Unsupported domain event type for JSON-LD 2.0.'),
            };
        }

        $created = $creationDate instanceof DateTimeInterface
            ? $creationDate->format(DateTimeInterface::ATOM)
            : (is_string($creationDate) && $creationDate !== '' ? $creationDate : now()->toIso8601String());

        return $this->encodeDocument($created, $mapped, $correlationId);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function encodeDocument(string $creationDate, array $events, ?string $correlationId): string
    {
        $document = [
            '@context' => [
                'https://ref.gs1.org/standards/epcis/2.0.0/epcis-context.jsonld',
            ],
            'type' => 'EPCISDocument',
            'schemaVersion' => EpcisSchemaVersion::V20,
            'creationDate' => $creationDate,
            'epcisBody' => [
                'eventList' => $events,
            ],
        ];

        if ($correlationId !== null && trim($correlationId) !== '') {
            $document['tracepharma:outboundCorrelation'] = trim($correlationId);
        }

        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new InvalidArgumentException('Unable to encode EPCIS 2.0 JSON-LD document.');
        }

        return $json."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function mapObjectEvent(ObjectEventData $event): array
    {
        $row = [
            'type' => 'ObjectEvent',
            'eventTime' => $event->eventTime->format(DateTimeInterface::ATOM),
            'eventTimeZoneOffset' => $event->eventTimeZoneOffset,
            'epcList' => array_values($event->epcList),
            'action' => $event->action->value,
            'bizStep' => $event->bizStep,
            'disposition' => $event->disposition,
        ];

        if ($event->readPoint !== null && $event->readPoint !== '') {
            $row['readPoint'] = ['id' => $event->readPoint];
        }

        if ($event->bizLocation !== null && $event->bizLocation !== '') {
            $row['bizLocation'] = ['id' => $event->bizLocation];
        }

        if ($event->quantityList !== []) {
            $row['quantityList'] = $event->quantityList;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAggregationEvent(AggregationEventData $event): array
    {
        $row = [
            'type' => 'AggregationEvent',
            'eventTime' => $event->eventTime->format(DateTimeInterface::ATOM),
            'eventTimeZoneOffset' => $event->eventTimeZoneOffset,
            'parentID' => $event->parentId,
            'childEPCs' => array_values($event->childEpcs),
            'action' => $event->action->value,
            'bizStep' => $event->bizStep,
            'disposition' => $event->disposition,
            'readPoint' => $event->readPoint !== null && $event->readPoint !== ''
                ? ['id' => $event->readPoint]
                : null,
            'bizLocation' => $event->bizLocation !== null && $event->bizLocation !== ''
                ? ['id' => $event->bizLocation]
                : null,
        ];

        return array_filter($row, static fn (mixed $value): bool => $value !== null);
    }
}
