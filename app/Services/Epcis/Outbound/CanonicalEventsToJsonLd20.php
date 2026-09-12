<?php

declare(strict_types=1);

namespace App\Services\Epcis\Outbound;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Project canonical epcis_events rows into an EPCIS 2.0 JSON-LD document envelope.
 */
final class CanonicalEventsToJsonLd20
{
    public function __construct(
        private readonly JsonLd20Writer $writer,
    ) {}

    public function projectDocument(EpcisDocument $document): string
    {
        if (! in_array($document->status, ['parsed', 'validated', 'generated'], true)) {
            throw new InvalidArgumentException(
                "Document #{$document->getKey()} must be parsed or validated before query-as-2.0 projection.",
            );
        }

        $events = $this->loadActiveEvents($document);
        if ($events->isEmpty()) {
            throw new InvalidArgumentException(
                "Document #{$document->getKey()} has no active events to project.",
            );
        }

        $jsonEvents = [];
        foreach ($events as $event) {
            $jsonEvents[] = $this->mapEvent($event);
        }

        $creationDate = $document->creation_date?->toIso8601String() ?? now()->toIso8601String();

        return $this->writer->buildFromDomainEvents($jsonEvents, $creationDate);
    }

    /**
     * @return Collection<int, EpcisEvent>
     */
    protected function loadActiveEvents(EpcisDocument $document): Collection
    {
        return $document->activeEvents()
            ->with([
                'eventEpcs.epc',
                'locations',
                'bizTransactions',
                'epcIlmd',
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, EpcisEvent>|list<EpcisEvent>  $events
     * @return array<string, mixed>
     */
    public function projectQueryDocument(iterable $events, ?string $creationDate = null): array
    {
        $jsonEvents = [];
        foreach ($events as $event) {
            $jsonEvents[] = $this->mapEvent($event);
        }

        return [
            '@context' => ['https://ref.gs1.org/standards/epcis/epcis-context.jsonld'],
            'type' => 'EPCISQueryDocument',
            'schemaVersion' => '2.0',
            'creationDate' => $creationDate ?? now()->toIso8601String(),
            'epcisBody' => [
                'queryResults' => [
                    'resultBody' => [
                        'eventList' => $jsonEvents,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function projectEvent(EpcisEvent $event): array
    {
        return $this->mapEvent($event);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEvent(EpcisEvent $event): array
    {
        $locations = $event->locations->keyBy('location_type');
        $readPointUri = $locations->get('readPoint')?->gln_uri;
        $bizLocationUri = $locations->get('bizLocation')?->gln_uri;

        $row = [
            'type' => $this->normalizeEventType((string) $event->event_type),
            'eventTime' => $event->event_time?->toIso8601String(),
            'eventTimeZoneOffset' => filled($event->event_timezone_offset)
                ? (string) $event->event_timezone_offset
                : '+00:00',
            'action' => strtoupper(trim((string) ($event->action ?? 'ADD'))),
            'bizStep' => (string) ($event->biz_step ?? ''),
            'disposition' => (string) ($event->disposition ?? ''),
        ];

        if (filled($event->event_id)) {
            $row['eventID'] = (string) $event->event_id;
        }

        if (filled($readPointUri)) {
            $row['readPoint'] = ['id' => (string) $readPointUri];
        }

        if (filled($bizLocationUri)) {
            $row['bizLocation'] = ['id' => (string) $bizLocationUri];
        }

        $bizTransactions = [];
        foreach ($event->bizTransactions as $transaction) {
            $bizTransactions[] = [
                'type' => (string) $transaction->type_uri,
                'bizTransaction' => (string) $transaction->value,
            ];
        }
        if ($bizTransactions !== []) {
            $row['bizTransactionList'] = $bizTransactions;
        }

        if ($row['type'] === 'AggregationEvent') {
            return array_merge($row, $this->mapAggregationEpcs($event));
        }

        return array_merge($row, $this->mapObjectEpcs($event));
    }

    /**
     * @return array<string, mixed>
     */
    private function mapObjectEpcs(EpcisEvent $event): array
    {
        $epcList = [];
        foreach ($event->eventEpcs as $eventEpc) {
            if ($eventEpc->role !== 'epcList' || $eventEpc->epc === null) {
                continue;
            }
            $uri = trim((string) $eventEpc->epc->epc_uri);
            if ($uri !== '') {
                $epcList[] = $uri;
            }
        }

        $mapped = ['epcList' => $epcList];
        $ilmd = $this->mapIlmd($event);
        if ($ilmd !== null) {
            $mapped['ilmd'] = $ilmd;
        }

        return $mapped;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAggregationEpcs(EpcisEvent $event): array
    {
        $parentId = null;
        $childEpcs = [];

        foreach ($event->eventEpcs as $eventEpc) {
            if ($eventEpc->epc === null) {
                continue;
            }
            $uri = trim((string) $eventEpc->epc->epc_uri);
            if ($uri === '') {
                continue;
            }

            if ($eventEpc->role === 'parentID') {
                $parentId = $uri;
            } elseif ($eventEpc->role === 'childEPC') {
                $childEpcs[] = $uri;
            }
        }

        return [
            'parentID' => $parentId,
            'childEPCs' => $childEpcs,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapIlmd(EpcisEvent $event): ?array
    {
        $ilmdRow = $event->epcIlmd->first();
        if ($ilmdRow === null) {
            return null;
        }

        $ilmd = [];
        if (filled($ilmdRow->lot_number)) {
            $ilmd['lotNumber'] = (string) $ilmdRow->lot_number;
        }
        if ($ilmdRow->expiry_date !== null) {
            $ilmd['itemExpirationDate'] = $ilmdRow->expiry_date->format('Y-m-d');
        }
        if ($ilmdRow->manufacturing_date !== null) {
            $ilmd['manufacturingDate'] = $ilmdRow->manufacturing_date->format('Y-m-d');
        }
        if ($ilmdRow->best_before_date !== null) {
            $ilmd['bestBeforeDate'] = $ilmdRow->best_before_date->format('Y-m-d');
        }
        if (filled($ilmdRow->additional_id)) {
            $ilmd['additionalId'] = (string) $ilmdRow->additional_id;
        }

        return $ilmd === [] ? null : $ilmd;
    }

    private function normalizeEventType(string $eventType): string
    {
        $normalized = trim($eventType);

        return str_contains(strtolower($normalized), 'aggregation')
            ? 'AggregationEvent'
            : 'ObjectEvent';
    }
}
