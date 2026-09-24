<?php

namespace App\Support\Epcis;

use DomainException;

/**
 * Convert lossless prior-commission/pack XML fragments into EPCIS 2.0 JSON-LD
 * event arrays. Replays manufacturer XML — does not invent history from columns.
 *
 * @phpstan-type ParsedEvent array<string, mixed>
 */
final class PedigreeXmlFragmentsToJsonLdEvents
{
    public function __construct(
        private readonly EpcisXmlReader $reader,
    ) {}

    /**
     * @param  list<string>  $eventXmlFragments
     * @return list<array<string, mixed>>
     */
    public function handle(array $eventXmlFragments): array
    {
        $fragments = [];
        foreach ($eventXmlFragments as $fragment) {
            $xml = $this->normalizeFragment((string) $fragment);
            if ($xml !== '') {
                $fragments[] = $xml;
            }
        }

        if ($fragments === []) {
            return [];
        }

        $path = tempnam(sys_get_temp_dir(), 'tp_pedigree_jsonld_');
        if ($path === false) {
            throw new DomainException('Unable to stage pedigree XML for JSON-LD conversion.');
        }

        try {
            file_put_contents($path, $this->wrapDocument($fragments));
            $parsed = $this->reader->parse($path);
        } finally {
            @unlink($path);
        }

        $events = [];
        foreach ($parsed['events'] ?? [] as $event) {
            if (! is_array($event)) {
                continue;
            }
            $mapped = $this->mapEvent($event);
            if ($mapped !== null) {
                $events[] = $mapped;
            }
        }

        return $events;
    }

    /**
     * @param  list<string>  $fragments
     */
    private function wrapDocument(array $fragments): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<epcis:EPCISDocument xmlns:epcis="urn:epcglobal:epcis:xsd:1"'
            .' xmlns:cbvmda="urn:epcglobal:cbv:mda"'
            .' xmlns:gs1ushc="http://epcis.gs1us.org/hc/ns"'
            .' schemaVersion="1.2" creationDate="2000-01-01T00:00:00.000Z">'
            ."\n  <EPCISBody>\n    <EventList>\n"
            .implode("\n", $fragments)
            ."\n    </EventList>\n  </EPCISBody>\n</epcis:EPCISDocument>\n";
    }

    private function normalizeFragment(string $fragment): string
    {
        $xml = trim($fragment);
        if ($xml === '') {
            return '';
        }

        $stripped = preg_replace('/^\s*<\?xml[^?]*\?>\s*/u', '', $xml);

        return is_string($stripped) ? trim($stripped) : $xml;
    }

    /**
     * @param  ParsedEvent  $event
     * @return array<string, mixed>|null
     */
    private function mapEvent(array $event): ?array
    {
        $type = (string) ($event['event_type'] ?? '');
        if (! in_array($type, ['ObjectEvent', 'AggregationEvent'], true)) {
            return null;
        }

        $row = [
            'type' => $type,
            'eventTime' => (string) ($event['event_time'] ?? ''),
            'eventTimeZoneOffset' => (string) ($event['event_timezone_offset'] ?? '+00:00'),
            'action' => (string) ($event['action'] ?? 'ADD'),
        ];

        $eventId = trim((string) ($event['event_id'] ?? ''));
        if ($eventId !== '') {
            $row['eventID'] = $eventId;
        }

        $recordTime = trim((string) ($event['record_time'] ?? ''));
        if ($recordTime !== '') {
            $row['recordTime'] = $recordTime;
        }

        $bizStep = trim((string) ($event['biz_step'] ?? ''));
        if ($bizStep !== '') {
            $row['bizStep'] = $bizStep;
        }

        $disposition = trim((string) ($event['disposition'] ?? ''));
        if ($disposition !== '') {
            $row['disposition'] = $disposition;
        }

        $epcs = is_array($event['epcs'] ?? null) ? $event['epcs'] : [];
        if ($type === 'ObjectEvent') {
            $row['epcList'] = $this->urisForRole($epcs, 'epcList');
        } else {
            $parents = $this->urisForRole($epcs, 'parentID');
            if ($parents !== []) {
                $row['parentID'] = $parents[0];
            }
            $row['childEPCs'] = $this->urisForRole($epcs, 'childEPC');
        }

        $readPoint = trim((string) ($event['read_point_uri'] ?? ''));
        if ($readPoint !== '') {
            $row['readPoint'] = ['id' => $readPoint];
        }

        $bizLocation = trim((string) ($event['biz_location_uri'] ?? ''));
        if ($bizLocation !== '') {
            $row['bizLocation'] = ['id' => $bizLocation];
        }

        $quantities = is_array($event['quantities'] ?? null) ? $event['quantities'] : [];
        $quantityList = $this->quantityListForRole($quantities, 'quantityList');
        if ($quantityList !== []) {
            $row['quantityList'] = $quantityList;
        }
        $childQuantityList = $this->quantityListForRole($quantities, 'childQuantityList');
        if ($childQuantityList !== []) {
            $row['childQuantityList'] = $childQuantityList;
        }

        $ilmd = $this->mapIlmd(is_array($event['ilmd'] ?? null) ? $event['ilmd'] : null);
        if ($ilmd !== []) {
            $row['ilmd'] = $ilmd;
        }

        $this->appendSourceDestination($row, is_array($event['parties'] ?? null) ? $event['parties'] : []);

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $epcs
     * @return list<string>
     */
    private function urisForRole(array $epcs, string $role): array
    {
        $uris = [];
        foreach ($epcs as $epc) {
            if (! is_array($epc) || ($epc['role'] ?? null) !== $role) {
                continue;
            }
            $uri = trim((string) ($epc['uri'] ?? ''));
            if ($uri !== '') {
                $uris[] = $uri;
            }
        }

        return $uris;
    }

    /**
     * @param  list<array<string, mixed>>  $quantities
     * @return list<array{epcClass: string, quantity: float, uom?: string}>
     */
    private function quantityListForRole(array $quantities, string $role): array
    {
        $rows = [];
        foreach ($quantities as $qty) {
            if (! is_array($qty) || ($qty['role'] ?? null) !== $role) {
                continue;
            }
            $epcClass = trim((string) ($qty['epc_class'] ?? ''));
            if ($epcClass === '' || ! is_numeric($qty['quantity'] ?? null)) {
                continue;
            }
            $row = [
                'epcClass' => $epcClass,
                'quantity' => (float) $qty['quantity'],
            ];
            $uom = trim((string) ($qty['uom'] ?? ''));
            if ($uom !== '') {
                $row['uom'] = $uom;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $ilmd
     * @return array<string, string>
     */
    private function mapIlmd(?array $ilmd): array
    {
        if ($ilmd === null) {
            return [];
        }

        $mapped = [];
        $pairs = [
            'lot_number' => 'lotNumber',
            'expiry_date' => 'itemExpirationDate',
            'manufacturing_date' => 'manufacturingDate',
            'best_before_date' => 'bestBeforeDate',
            'additional_id' => 'additionalId',
        ];
        foreach ($pairs as $from => $to) {
            $value = trim((string) ($ilmd[$from] ?? ''));
            if ($value !== '') {
                $mapped[$to] = $value;
            }
        }

        $extra = $ilmd['extra_json'] ?? null;
        if (is_array($extra)) {
            foreach ($extra as $key => $value) {
                $name = trim((string) $key);
                $text = trim((string) $value);
                if ($name !== '' && $text !== '') {
                    $mapped[$name] = $text;
                }
            }
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $parties
     */
    private function appendSourceDestination(array &$row, array $parties): void
    {
        $sources = [];
        $destinations = [];
        foreach ($parties as $party) {
            if (! is_array($party)) {
                continue;
            }
            $uri = trim((string) ($party['gln_uri'] ?? ''));
            if ($uri === '') {
                continue;
            }
            $type = trim((string) ($party['type_uri'] ?? ''));
            $entry = ['type' => $type !== '' ? $type : 'owning_party', 'source' => $uri];
            if (($party['party_role'] ?? null) === 'destination') {
                $destinations[] = ['type' => $entry['type'], 'destination' => $uri];
            } elseif (($party['party_role'] ?? null) === 'source') {
                $sources[] = $entry;
            }
        }

        if ($sources !== []) {
            $row['sourceList'] = $sources;
        }
        if ($destinations !== []) {
            $row['destinationList'] = $destinations;
        }
    }
}
