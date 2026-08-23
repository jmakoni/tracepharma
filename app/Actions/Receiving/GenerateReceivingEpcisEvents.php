<?php

namespace App\Actions\Receiving;

use App\Actions\Epcis\SyncDocumentEpcsFromEvents;
use App\Actions\Outbound\AssertAuthoredAggregationCandidate;
use App\Actions\Outbound\AssertAuthoredObjectEventCandidate;
use App\Domain\Epcis\Enums\EpcisAction;
use App\Enums\EpcisAuthoredKind;
use App\Enums\ReceivingSessionKind;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Services\Receiving\ReceivingGate;
use App\Support\Epcis\PersistAuthoredEventLocations;
use App\Support\Epcis\PersistEpcisXmlPayload;
use App\Support\Epcis\ScheduleOutboundEpcisTransmission;
use App\Support\Gs1\Sgln;
use App\Support\Gs1\SglnResolution;
use App\Support\Receiving\ReceivingPolicy;
use App\Support\TenantSettings;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Emit an authored EPCIS receiving ObjectEvent (OBSERVE, bizstep receiving,
 * disposition in_progress) for a completed receiving session, and optionally an
 * unpacking AggregationEvent (DELETE) when hierarchy is physically broken.
 *
 * This is operational custody attestation for the receiver's repository — not
 * DSCSA TI/TS (seller affirmation stays on the inbound shipping document).
 *
 * Idempotent per session: once receiving_events_generated_at is set, subsequent
 * calls return the already-generated document/events without re-emitting.
 */
final class GenerateReceivingEpcisEvents
{
    private const DISPOSITION_IN_PROGRESS = 'urn:epcglobal:cbv:disp:in_progress';

    private const BIZ_STEP_RECEIVING = 'urn:epcglobal:cbv:bizstep:receiving';

    /** @var list<string> */
    private const COPY_BIZ_TRANSACTION_TYPES = [
        'urn:epcglobal:cbv:btt:po',
        'urn:epcglobal:cbv:btt:desadv',
    ];

    public function __construct(
        private readonly SyncDocumentEpcsFromEvents $syncDocumentEpcsFromEvents,
        private readonly ScheduleOutboundEpcisTransmission $scheduleOutboundTransmission,
        private readonly PersistEpcisXmlPayload $persistEpcisXmlPayload,
        private readonly PersistAuthoredEventLocations $persistAuthoredEventLocations,
        private readonly UnpackReceivingHierarchy $unpackReceivingHierarchy,
        private readonly AssertAuthoredObjectEventCandidate $assertObjectEventCandidate,
        private readonly AssertAuthoredAggregationCandidate $assertAggregationCandidate,
        private readonly ReceivingGate $receivingGate,
    ) {}

    /**
     * @return array{
     *     document: ?EpcisDocument,
     *     event: ?EpcisEvent,
     *     unpackEvent: ?EpcisEvent,
     *     generated: bool
     * }
     */
    public function handle(ReceivingSession $session, ?int $actorId = null, bool $unpack = false): array
    {
        $session = $session->fresh() ?? $session;

        if ($session->status !== 'completed' && $session->receiving_events_generated_at === null) {
            return [
                'document' => null,
                'event' => null,
                'unpackEvent' => null,
                'generated' => false,
            ];
        }

        $built = DB::transaction(function () use ($session, $unpack): array {
            // Lock the session row before reading/writing receiving_events_generated_at:
            // two concurrent callers (e.g. scan-first "Complete" racing an ASN
            // auto-complete on the same session) must not both pass the null check and
            // author duplicate receiving EPCIS documents.
            $session = ReceivingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->receiving_events_generated_at !== null) {
                $existing = $this->existingResult($session);
                // Stale marker (timestamp set, document/event missing) — allow repair.
                if ($existing['document'] !== null && $existing['event'] !== null) {
                    return $existing;
                }

                $session->forceFill([
                    'receiving_epcis_document_id' => null,
                    'receiving_events_generated_at' => null,
                ])->save();
            }

            if ($session->status !== 'completed') {
                return [
                    'document' => null,
                    'event' => null,
                    'unpackEvent' => null,
                    'generated' => false,
                ];
            }

            $session->loadMissing('site', 'tradingPartner', 'document');

            $confirmedLines = ReceivingScanLine::query()
                ->where('receiving_session_id', $session->getKey())
                ->where('status', 'confirmed')
                ->get(['epc_id', 'line_role', 'parent_epc_id']);

            $epcIds = $this->receiptEpcIds($confirmedLines);
            /** @var Collection<int, Epc> $epcsById */
            $epcsById = Epc::query()->whereIn('id', $epcIds)->lockForUpdate()->get()->keyBy('id');

            $this->assertConfirmedLinesStillEligible($session, $epcIds);

            $recordTime = now();
            $eventTime = $this->resolveEventTime($session, $recordTime);
            $timezoneOffset = $this->timezoneOffset($session, $eventTime);
            $location = $this->resolveReceivingLocationGlns($session);
            $gln = $location['gln'];
            $sglnUrn = $location['sgln_urn'];
            $eventUuid = (string) Str::uuid();

            $this->preflightReceivingObjectEvent($epcsById, $epcIds, $eventTime);

            $document = $this->createAuthoredDocument($session, $epcsById, $recordTime);

            $event = EpcisEvent::query()->create($this->authoredEventAttributes([
                'document_id' => $document->getKey(),
                'event_id' => 'urn:uuid:'.$eventUuid,
                'event_type' => 'ObjectEvent',
                'event_time' => $eventTime,
                'record_time' => $recordTime,
                'event_timezone_offset' => $timezoneOffset,
                'action' => 'OBSERVE',
                'biz_step' => self::BIZ_STEP_RECEIVING,
                'disposition' => self::DISPOSITION_IN_PROGRESS,
                'read_point_gln' => $gln,
                'biz_location_gln' => $gln,
                'trading_partner_id' => $session->trading_partner_id,
            ]));

            $this->persistAuthoredEventLocations->handle($event, [
                [
                    'location_type' => 'readPoint',
                    'gln' => $gln,
                    'gln_uri' => $sglnUrn,
                    'site_id' => $session->site_id,
                ],
                [
                    'location_type' => 'bizLocation',
                    'gln' => $gln,
                    'gln_uri' => $sglnUrn,
                    'site_id' => $session->site_id,
                ],
            ]);

            $this->attachEpcs($event, $epcIds, 'epcList');
            $bizTransactions = $this->copyInboundBizTransactions($session, $event);

            $eventCount = 1;
            $unpackEvent = null;
            /** @var list<array{event_id: string, parent_epc_id: int, child_epc_ids: list<int>}> $unpackBlocks */
            $unpackBlocks = [];

            $policy = ReceivingPolicy::forTenant(tenant());
            $mayUnpack = $unpack && ($policy->canUnpackAtReceive() || $policy->canUnpackAfterReceive());
            if ($mayUnpack) {
                $unpacked = $this->unpackReceivingHierarchy->authorEventsOnDocument(
                    $session,
                    $document,
                    $eventTime,
                    $recordTime,
                    $timezoneOffset,
                    $gln,
                    // Quarantined hierarchy stays sealed rather than blocking receipt custody.
                    failOnQuarantine: false,
                );
                $unpackBlocks = $unpacked['blocks'];
                $unpackEvent = $unpacked['first_event'];
                $eventCount += count($unpackBlocks);
            }

            $this->preflightUnpackAggregationBlocks($unpackBlocks, $eventTime, $epcsById);

            $xml = $this->buildXml(
                epcsById: $epcsById,
                eventTime: $eventTime,
                recordTime: $recordTime,
                timezoneOffset: $timezoneOffset,
                eventUuid: $eventUuid,
                sglnUrn: $sglnUrn,
                bizTransactions: $bizTransactions,
                unpackBlocks: $unpackBlocks,
            );

            $epcCount = $this->syncDocumentEpcsFromEvents->handle($document);

            $document->forceFill([
                'event_count' => $eventCount,
                'epc_count' => $epcCount,
                'status' => 'parsed',
                'processed_at' => $recordTime,
                'last_processed_at' => $recordTime,
            ])->save();

            // Persist XML before marking generated so dual-disk failure rolls back the txn.
            $this->persistEpcisXmlPayload->handle(
                $document,
                $xml,
                (string) $document->payload_path,
                (string) $document->payload_disk,
                'Receiving EPCIS',
            );

            $session->forceFill([
                'receiving_epcis_document_id' => $document->getKey(),
                'receiving_events_generated_at' => $recordTime,
            ])->save();

            return [
                'document' => $document->refresh(),
                'event' => $event,
                'unpackEvent' => $unpackEvent,
                'generated' => true,
            ];
        });

        if ($built['generated']) {
            $this->scheduleOutboundTransmission->afterPersist($built['document'], true);
        }

        return $built;
    }

    /**
     * @return array{document: ?EpcisDocument, event: ?EpcisEvent, unpackEvent: ?EpcisEvent, generated: bool}
     */
    private function existingResult(ReceivingSession $session): array
    {
        $documentId = $session->receiving_epcis_document_id;
        $document = $documentId !== null ? EpcisDocument::query()->find($documentId) : null;

        $event = null;
        $unpackEvent = null;

        if ($documentId !== null) {
            $event = EpcisEvent::query()
                ->where('document_id', $documentId)
                ->where('event_type', 'ObjectEvent')
                ->first();

            $unpackEvent = EpcisEvent::query()
                ->where('document_id', $documentId)
                ->where('event_type', 'AggregationEvent')
                ->first();
        }

        return [
            'document' => $document,
            'event' => $event,
            'unpackEvent' => $unpackEvent,
            'generated' => false,
        ];
    }

    /**
     * @param  Collection<int, Epc>  $epcsById
     * @param  list<int>  $epcIds
     */
    private function preflightReceivingObjectEvent(Collection $epcsById, array $epcIds, Carbon $eventTime): void
    {
        $epcUris = [];
        foreach ($epcIds as $epcId) {
            $epc = $epcsById->get($epcId);
            if ($epc !== null) {
                $epcUris[] = (string) $epc->epc_uri;
            }
        }

        if ($epcUris === []) {
            throw new InvalidArgumentException('Receiving preflight: no EPC URIs for ObjectEvent.');
        }

        $this->assertObjectEventCandidate->handle(
            epcList: $epcUris,
            action: EpcisAction::Observe,
            bizStep: 'receiving',
            disposition: 'in_progress',
            eventTimeUtc: $eventTime->clone()->utc()->toDateTimeImmutable(),
        );
    }

    /**
     * @param  list<array{event_id: string, parent_epc_id: int, child_epc_ids: list<int>}>  $unpackBlocks
     * @param  Collection<int, Epc>  $epcsById
     */
    private function preflightUnpackAggregationBlocks(array $unpackBlocks, Carbon $eventTime, Collection $epcsById): void
    {
        if ($unpackBlocks === []) {
            return;
        }

        $neededIds = [];
        foreach ($unpackBlocks as $block) {
            $neededIds[] = $block['parent_epc_id'];
            foreach ($block['child_epc_ids'] as $childId) {
                $neededIds[] = $childId;
            }
        }

        $extraEpcs = Epc::query()
            ->whereIn('id', array_values(array_unique($neededIds)))
            ->get(['id', 'epc_uri'])
            ->keyBy('id');

        $eventTimeUtc = $eventTime->clone()->utc()->toDateTimeImmutable();

        foreach ($unpackBlocks as $block) {
            $parent = $epcsById->get($block['parent_epc_id']) ?? $extraEpcs->get($block['parent_epc_id']);
            if ($parent === null) {
                throw new InvalidArgumentException(
                    "Receiving unpack preflight: parent EPC #{$block['parent_epc_id']} not found.",
                );
            }

            $childUris = [];
            $missingChildIds = [];
            foreach ($block['child_epc_ids'] as $childId) {
                $epc = $epcsById->get($childId) ?? $extraEpcs->get($childId);
                if ($epc === null) {
                    $missingChildIds[] = $childId;
                } else {
                    $childUris[] = (string) $epc->epc_uri;
                }
            }

            if ($missingChildIds !== []) {
                throw new InvalidArgumentException(
                    'Receiving unpack preflight: child EPC(s) not found: '
                    .implode(', ', array_map(fn (int $id): string => "#{$id}", $missingChildIds))
                    ." (parent EPC #{$block['parent_epc_id']}).",
                );
            }

            if ($childUris === []) {
                throw new InvalidArgumentException(
                    "Receiving unpack preflight: no child EPCs for parent EPC #{$block['parent_epc_id']}.",
                );
            }

            $this->assertAggregationCandidate->handle(
                parentUri: (string) $parent->epc_uri,
                childEpcs: $childUris,
                action: EpcisAction::Delete,
                bizStep: 'unpacking',
                disposition: 'in_progress',
                eventTimeUtc: $eventTimeUtc,
            );
        }
    }

    /**
     * Complete releases its lock before calling here. Recheck holds and that the
     * same kind of session has not already authored receiving events for these
     * EPCs (scan-first vs ASN reconcile may both carry the line — those differ
     * in session_kind and are allowed).
     *
     * @param  list<int>  $epcIds
     */
    private function assertConfirmedLinesStillEligible(ReceivingSession $session, array $epcIds): void
    {
        if ($epcIds === []) {
            return;
        }

        $heldIds = $this->receivingGate->epcIdsBlockedByOpenHold($epcIds);
        if ($heldIds !== []) {
            throw new DomainException(
                'Cannot author receiving EPCIS: one or more confirmed units are under quarantine.',
            );
        }

        $alreadyReceivedElsewhere = ReceivingScanLine::query()
            ->whereIn('epc_id', $epcIds)
            ->whereIn('status', ['confirmed', 'unexpected'])
            ->whereHas('session', function ($query) use ($session): void {
                $query
                    ->whereKeyNot($session->getKey())
                    ->whereNotNull('receiving_events_generated_at')
                    ->where(function ($kind) use ($session): void {
                        if ($session->isScanFirst()) {
                            $kind->where('session_kind', ReceivingSessionKind::ScanFirst);

                            return;
                        }

                        $kind->where(function ($asn): void {
                            $asn->where('session_kind', ReceivingSessionKind::InboundAsn)
                                ->orWhereNull('session_kind');
                        });
                    });
            })
            ->exists();

        if ($alreadyReceivedElsewhere) {
            throw new DomainException(
                'Cannot author receiving EPCIS: one or more confirmed units already have receiving events on another receive session.',
            );
        }
    }

    /**
     * Confirmed scan lines only. Includes auto-confirmed children for local custody
     * completeness; partner transmit may later filter to observed parents.
     *
     * @param  Collection<int, ReceivingScanLine>  $confirmedLines
     * @return list<int>
     */
    private function receiptEpcIds(Collection $confirmedLines): array
    {
        $policy = ReceivingPolicy::forTenant(tenant());
        $lines = $confirmedLines;

        if (! $policy->receiptIncludesConfirmedChildren()) {
            $lines = $lines->where('line_role', 'parent');
        }

        return $lines
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Epc>  $epcsById
     */
    private function createAuthoredDocument(
        ReceivingSession $session,
        Collection $epcsById,
        Carbon $now,
    ): EpcisDocument {
        $disk = (string) config('tracepharma.epcis.authored_payload_disk', 'local');
        $documentUuid = (string) Str::uuid();
        $payloadPath = "epcis/outbound/receiving-{$session->getKey()}-{$now->format('Ymd_His')}.xml";

        return EpcisDocument::query()->create($this->authoredDocumentAttributes([
            'document_uuid' => $documentUuid,
            'schema_version' => '1.2',
            'creation_date' => $now,
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Receiving,
            'trading_partner_id' => $session->trading_partner_id,
            'format' => 'xml',
            'original_filename' => "receiving-{$session->getKey()}.xml",
            'payload_disk' => $disk,
            'payload_path' => $payloadPath,
            'dscsa_affirm' => false,
            'status' => 'generated',
            'notes' => "Generated receiving EPCIS (custody attestation, not TI/TS) for receiving session #{$session->getKey()}.",
            'reprocess_count' => 0,
            'event_count' => 0,
            'epc_count' => $epcsById->count(),
            'received_at' => $now,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function authoredDocumentAttributes(array $attributes): array
    {
        if (Schema::hasColumn('epcis_documents', 'ingest_generation')) {
            $attributes['ingest_generation'] = 1;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function authoredEventAttributes(array $attributes): array
    {
        if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
            $attributes['ingest_generation'] = 1;
        }

        return $attributes;
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function attachEpcs(EpcisEvent $event, array $epcIds, string $role): void
    {
        if ($epcIds === []) {
            return;
        }

        $rows = array_map(fn (int $epcId): array => [
            'event_id' => $event->getKey(),
            'epc_id' => $epcId,
            'role' => $role,
        ], $epcIds);

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('event_epcs')->insertOrIgnore($chunk);
        }
    }

    /**
     * @return list<array{type_uri: string, value: string}>
     */
    private function copyInboundBizTransactions(ReceivingSession $session, EpcisEvent $receivingEvent): array
    {
        $inboundDocumentId = $session->epcis_document_id;
        if ($inboundDocumentId === null) {
            return [];
        }

        $rows = DB::table('event_biz_transactions')
            ->join('epcis_events', 'epcis_events.id', '=', 'event_biz_transactions.event_id')
            ->where('epcis_events.document_id', $inboundDocumentId)
            ->whereIn('event_biz_transactions.type_uri', self::COPY_BIZ_TRANSACTION_TYPES)
            ->select([
                'event_biz_transactions.type_uri',
                'event_biz_transactions.value',
            ])
            ->distinct()
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $payload = [];
        $insert = [];
        foreach ($rows as $row) {
            $typeUri = (string) $row->type_uri;
            $value = (string) $row->value;
            if ($typeUri === '' || $value === '') {
                continue;
            }
            $payload[] = ['type_uri' => $typeUri, 'value' => $value];
            $insert[] = [
                'event_id' => $receivingEvent->getKey(),
                'type_uri' => $typeUri,
                'value' => $value,
            ];
        }

        if ($insert !== []) {
            DB::table('event_biz_transactions')->insert($insert);
        }

        return $payload;
    }

    /**
     * @param  Collection<int, Epc>  $epcsById
     * @param  list<array{type_uri: string, value: string}>  $bizTransactions
     * @param  list<array{event_id: string, parent_epc_id: int, child_epc_ids: list<int>}>  $unpackBlocks
     */
    private function buildXml(
        Collection $epcsById,
        Carbon $eventTime,
        Carbon $recordTime,
        string $timezoneOffset,
        string $eventUuid,
        string $sglnUrn,
        array $bizTransactions,
        array $unpackBlocks,
    ): string {
        $creationDate = $recordTime->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $eventTimeXml = $eventTime->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $recordTimeXml = $creationDate;
        $offsetXml = htmlspecialchars($timezoneOffset, ENT_XML1);

        $epcList = $epcsById
            ->map(fn (Epc $epc): string => '          <epc>'.htmlspecialchars((string) $epc->epc_uri, ENT_XML1).'</epc>')
            ->implode("\n");

        $safe = htmlspecialchars($sglnUrn, ENT_XML1);
        $locationXml =
            "        <readPoint>\n".
            "          <id>{$safe}</id>\n".
            "        </readPoint>\n".
            "        <bizLocation>\n".
            "          <id>{$safe}</id>\n".
            "        </bizLocation>\n";

        $bizTxXml = '';
        if ($bizTransactions !== []) {
            $items = collect($bizTransactions)
                ->map(function (array $bt): string {
                    $type = htmlspecialchars($bt['type_uri'], ENT_XML1);
                    $value = htmlspecialchars($bt['value'], ENT_XML1);

                    return "          <bizTransaction type=\"{$type}\">{$value}</bizTransaction>";
                })
                ->implode("\n");
            $bizTxXml = "        <bizTransactionList>\n{$items}\n        </bizTransactionList>\n";
        }

        $objectEvent =
            "              <ObjectEvent>\n".
            "                <eventTime>{$eventTimeXml}</eventTime>\n".
            "                <recordTime>{$recordTimeXml}</recordTime>\n".
            "                <eventTimeZoneOffset>{$offsetXml}</eventTimeZoneOffset>\n".
            "                <baseExtension>\n".
            "                  <eventID>urn:uuid:{$eventUuid}</eventID>\n".
            "                </baseExtension>\n".
            "                <epcList>\n".
            "{$epcList}\n".
            "                </epcList>\n".
            "                <action>OBSERVE</action>\n".
            "                <bizStep>urn:epcglobal:cbv:bizstep:receiving</bizStep>\n".
            "                <disposition>urn:epcglobal:cbv:disp:in_progress</disposition>\n".
            $locationXml.
            $bizTxXml.
            '              </ObjectEvent>';

        $aggregationXml = '';
        if ($unpackBlocks !== []) {
            $neededIds = [];
            foreach ($unpackBlocks as $block) {
                $neededIds[] = $block['parent_epc_id'];
                foreach ($block['child_epc_ids'] as $childId) {
                    $neededIds[] = $childId;
                }
            }
            $extraEpcs = Epc::query()
                ->whereIn('id', array_values(array_unique($neededIds)))
                ->get()
                ->keyBy('id');

            $aggChunks = [];
            foreach ($unpackBlocks as $block) {
                $parent = $epcsById->get($block['parent_epc_id']) ?? $extraEpcs->get($block['parent_epc_id']);
                if ($parent === null) {
                    continue;
                }
                $parentUri = htmlspecialchars((string) $parent->epc_uri, ENT_XML1);
                $childXml = collect($block['child_epc_ids'])
                    ->map(function (int $childId) use ($epcsById, $extraEpcs): ?string {
                        $epc = $epcsById->get($childId) ?? $extraEpcs->get($childId);

                        return $epc !== null
                            ? '          <epc>'.htmlspecialchars((string) $epc->epc_uri, ENT_XML1).'</epc>'
                            : null;
                    })
                    ->filter()
                    ->implode("\n");
                $unpackId = htmlspecialchars($block['event_id'], ENT_XML1);
                $aggChunks[] =
                    "              <AggregationEvent>\n".
                    "                <eventTime>{$eventTimeXml}</eventTime>\n".
                    "                <recordTime>{$recordTimeXml}</recordTime>\n".
                    "                <eventTimeZoneOffset>{$offsetXml}</eventTimeZoneOffset>\n".
                    "                <baseExtension>\n".
                    "                  <eventID>{$unpackId}</eventID>\n".
                    "                </baseExtension>\n".
                    "                <parentID>{$parentUri}</parentID>\n".
                    "                <childEPCs>\n".
                    "{$childXml}\n".
                    "                </childEPCs>\n".
                    "                <action>DELETE</action>\n".
                    "                <bizStep>urn:epcglobal:cbv:bizstep:unpacking</bizStep>\n".
                    "                <disposition>urn:epcglobal:cbv:disp:in_progress</disposition>\n".
                    $locationXml.
                    '              </AggregationEvent>';
            }
            $aggregationXml = implode("\n", $aggChunks);
        }

        $events = $objectEvent.($aggregationXml !== '' ? "\n".$aggregationXml : '');

        return
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".
            "<epcis:EPCISDocument\n".
            "    xmlns:epcis=\"urn:epcglobal:epcis:xsd:1\"\n".
            "    schemaVersion=\"1.2\"\n".
            "    creationDate=\"{$creationDate}\">\n".
            "  <EPCISBody>\n".
            "    <EventList>\n".
            "{$events}\n".
            "    </EventList>\n".
            "  </EPCISBody>\n".
            "</epcis:EPCISDocument>\n";
    }

    private function resolveEventTime(ReceivingSession $session, Carbon $fallback): Carbon
    {
        if ($session->completed_at !== null) {
            return Carbon::parse($session->completed_at);
        }

        $lastConfirm = ReceivingScanLine::query()
            ->where('receiving_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->whereNotNull('confirmed_at')
            ->max('confirmed_at');

        return $lastConfirm !== null ? Carbon::parse($lastConfirm) : $fallback;
    }

    private function timezoneOffset(ReceivingSession $session, Carbon $at): string
    {
        $tzName = $session->site?->timezone ?: (string) config('app.timezone', 'UTC');

        try {
            return $at->clone()->timezone($tzName)->format('P');
        } catch (Throwable) {
            return $at->clone()->utc()->format('P');
        }
    }

    /**
     * When session.site_id is set, the receive-site GLN is required — no tenant fallback.
     * Without a site, fall back to the tenant organization GLN for authored locations.
     * Always builds an SGLN URN from GLN + company prefix when GLN is present.
     *
     * @return array{gln: string, sgln_urn: string}
     *
     * @throws DomainException when no GLN is available or SGLN cannot be built
     */
    private function resolveReceivingLocationGlns(ReceivingSession $session): array
    {
        if ($session->site_id !== null) {
            $siteGln = Sgln::normalizeGln($session->site?->gln);

            if ($siteGln === null) {
                throw new DomainException(
                    'Cannot author receiving EPCIS: receive site has no GLN configured for readPoint/bizLocation.',
                );
            }

            $sglnUrn = $this->resolveSiteSglnUrn($session, $siteGln);
            if ($sglnUrn === null) {
                throw new DomainException(
                    'Cannot author receiving EPCIS: site GLN is set but SGLN could not be built (organization company prefix required).',
                );
            }

            return [
                'gln' => $siteGln,
                'sgln_urn' => $sglnUrn,
            ];
        }

        $settings = TenantSettings::forTenant(tenant());
        $tenantGln = Sgln::normalizeGln($settings->gln());

        if ($tenantGln === null) {
            throw new DomainException(
                'Cannot author receiving EPCIS: no receive-site or organization GLN is configured for readPoint/bizLocation.',
            );
        }

        $sglnUrn = SglnResolution::fromCompanyPrefix($tenantGln, $settings->companyPrefix());

        if ($sglnUrn === null) {
            throw new DomainException(
                'Cannot author receiving EPCIS: organization GLN is set but SGLN could not be built (company prefix required).',
            );
        }

        return [
            'gln' => $tenantGln,
            'sgln_urn' => $sglnUrn,
        ];
    }

    private function resolveSiteSglnUrn(ReceivingSession $session, string $gln): ?string
    {
        $candidates = [];

        // Prefer a parseable site SGLN attribute when present (generated columns may be non-GS1).
        $siteSgln = $session->site?->getAttribute('sgln');
        if (is_string($siteSgln) && $siteSgln !== '') {
            $candidates[] = $siteSgln;
        }

        if ($session->epcis_document_id !== null) {
            $fromEvents = DB::table('event_locations')
                ->join('epcis_events', 'epcis_events.id', '=', 'event_locations.event_id')
                ->where('epcis_events.document_id', $session->epcis_document_id)
                ->whereNotNull('event_locations.gln_uri')
                ->pluck('event_locations.gln_uri')
                ->all();
            foreach ($fromEvents as $uri) {
                if (is_string($uri) && $uri !== '') {
                    $candidates[] = $uri;
                }
            }
        }

        $resolved = Sgln::resolveUrn($gln, null, $candidates);
        if ($resolved !== null) {
            return $resolved;
        }

        $prefix = TenantSettings::forTenant(tenant())->companyPrefix();

        return SglnResolution::fromCompanyPrefix($gln, $prefix);
    }
}
