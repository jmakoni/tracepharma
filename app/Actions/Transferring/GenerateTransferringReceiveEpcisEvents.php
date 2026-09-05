<?php

namespace App\Actions\Transferring;

use App\Actions\Epcis\SyncDocumentEpcsFromEvents;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Receiving\ReceivingGate;
use App\Support\Epcis\PersistAuthoredEventLocations;
use App\Support\Epcis\PersistEpcisXmlPayload;
use App\Support\Epcis\ResolveSiteLocationGlns;
use App\Support\Epcis\SbdhInstanceIdentifier;
use App\Support\Epcis\ScheduleOutboundEpcisTransmission;
use App\Support\Gs1\Sgln;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Append a receiving ObjectEvent (in_progress at the to site) to the existing
 * transfer EPCIS document after destination scan verification.
 *
 * The receive artifact is a fresh two-event file written to a new payload path:
 * the ship-only bytes stay on disk at the path the ship leg transmitted, so a
 * partner that already pulled the in_transit file can still be handed the exact
 * document we sent them. The document row then points at the receive artifact,
 * which is what gets transmitted.
 *
 * Runs as the destination operator, who holds no site access to the origin, so
 * locations come from the record rather than from operator scope.
 *
 * Idempotent per session via receive_events_generated_at.
 */
final class GenerateTransferringReceiveEpcisEvents
{
    private const DISPOSITION_IN_TRANSIT = 'urn:epcglobal:cbv:disp:in_transit';

    private const DISPOSITION_IN_PROGRESS = 'urn:epcglobal:cbv:disp:in_progress';

    private const BIZ_STEP_SHIPPING = 'urn:epcglobal:cbv:bizstep:shipping';

    private const BIZ_STEP_RECEIVING = 'urn:epcglobal:cbv:bizstep:receiving';

    public function __construct(
        private readonly ResolveSiteLocationGlns $resolveSiteLocationGlns,
        private readonly SyncDocumentEpcsFromEvents $syncDocumentEpcsFromEvents,
        private readonly ScheduleOutboundEpcisTransmission $scheduleOutboundTransmission,
        private readonly PersistEpcisXmlPayload $persistEpcisXmlPayload,
        private readonly PersistAuthoredEventLocations $persistAuthoredEventLocations,
        private readonly ReceivingGate $receivingGate,
        private readonly EpcCustodyGate $custodyGate,
    ) {}

    /**
     * @return array{
     *     document: ?EpcisDocument,
     *     shippingEvent: ?EpcisEvent,
     *     receivingEvent: ?EpcisEvent,
     *     generated: bool
     * }
     */
    public function handle(TransferringSession $session, ?int $actorId = null): array
    {
        $session = $session->fresh() ?? $session;

        if ($session->receive_events_generated_at !== null) {
            return $this->existingResult($session);
        }

        if ($session->status !== 'completed') {
            return [
                'document' => null,
                'shippingEvent' => null,
                'receivingEvent' => null,
                'generated' => false,
            ];
        }

        if ($session->transfer_epcis_document_id === null) {
            return [
                'document' => null,
                'shippingEvent' => null,
                'receivingEvent' => null,
                'generated' => false,
            ];
        }

        $built = DB::transaction(function () use ($session): array {
            $session->loadMissing('fromSite', 'toSite');

            $document = EpcisDocument::query()
                ->whereKey($session->transfer_epcis_document_id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingReceiving = EpcisEvent::query()
                ->where('document_id', $document->getKey())
                ->where('biz_step', self::BIZ_STEP_RECEIVING)
                ->first();

            if ($existingReceiving !== null) {
                $session->forceFill([
                    'receive_events_generated_at' => $existingReceiving->record_time ?? now(),
                ])->save();

                $shippingEvent = EpcisEvent::query()
                    ->where('document_id', $document->getKey())
                    ->where('biz_step', self::BIZ_STEP_SHIPPING)
                    ->first();

                return [
                    'document' => $document->refresh(),
                    'shippingEvent' => $shippingEvent,
                    'receivingEvent' => $existingReceiving,
                    'generated' => false,
                ];
            }

            $epcIds = TransferringScanLine::query()
                ->where('transferring_session_id', $session->getKey())
                ->where('status', 'received')
                ->orderBy('id')
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $this->assertReceivedEpcsStillEligible($epcIds);

            /** @var Collection<int, Epc> $receivedEpcsById */
            $receivedEpcsById = Epc::query()->whereIn('id', $epcIds)->get()->keyBy('id');

            $shippingEvent = EpcisEvent::query()
                ->where('document_id', $document->getKey())
                ->where('biz_step', self::BIZ_STEP_SHIPPING)
                ->firstOrFail();

            $shippingEpcIds = DB::table('event_epcs')
                ->where('event_id', $shippingEvent->getKey())
                ->where('role', 'epcList')
                ->orderBy('epc_id')
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            /** @var Collection<int, Epc> $shippingEpcsById */
            $shippingEpcsById = Epc::query()->whereIn('id', $shippingEpcIds)->get()->keyBy('id');
            $shippingEpcsById = collect($shippingEpcIds)
                ->map(fn (int $id): ?Epc => $shippingEpcsById->get($id))
                ->filter()
                ->keyBy(fn (Epc $epc): int => (int) $epc->getKey());

            $fromLocation = $this->shipLegLocation($shippingEvent, (int) $session->from_site_id);
            $toLocation = $this->resolveSiteLocation((int) $session->to_site_id, 'Transfer destination site');

            $recordTime = now();
            $receiveEventTime = $session->received_at !== null
                ? Carbon::parse($session->received_at)
                : $recordTime;
            $timezoneOffset = $this->timezoneOffset($session->toSite, $receiveEventTime);
            $receivingUuid = (string) Str::uuid();

            $receivingEvent = EpcisEvent::query()->create($this->authoredEventAttributes([
                'document_id' => $document->getKey(),
                'event_id' => 'urn:uuid:'.$receivingUuid,
                'event_type' => 'ObjectEvent',
                'event_time' => $receiveEventTime,
                'record_time' => $recordTime,
                'event_timezone_offset' => $timezoneOffset,
                'action' => 'OBSERVE',
                'biz_step' => self::BIZ_STEP_RECEIVING,
                'disposition' => self::DISPOSITION_IN_PROGRESS,
                'read_point_gln' => $toLocation['gln'],
                'biz_location_gln' => $toLocation['gln'],
            ]));
            $this->persistAuthoredEventLocations->handle($receivingEvent, [
                [
                    'location_type' => 'readPoint',
                    'gln' => $toLocation['gln'],
                    'gln_uri' => $toLocation['sgln_urn'],
                    'site_id' => $toLocation['site_id'],
                ],
                [
                    'location_type' => 'bizLocation',
                    'gln' => $toLocation['gln'],
                    'gln_uri' => $toLocation['sgln_urn'],
                    'site_id' => $toLocation['site_id'],
                ],
            ]);
            $this->attachEpcs($receivingEvent, $epcIds);
            $epcCount = $this->syncDocumentEpcsFromEvents->handle($document);

            $shippingUuid = str_replace('urn:uuid:', '', (string) $shippingEvent->event_id);
            $shipEventTime = Carbon::parse($shippingEvent->event_time);
            $shipTimezoneOffset = (string) ($shippingEvent->event_timezone_offset ?: $timezoneOffset);

            $xml = $this->buildXml(
                shippingEpcsById: $shippingEpcsById,
                receivedEpcsById: $receivedEpcsById,
                shippingEventTime: $shipEventTime,
                receivingEventTime: $receiveEventTime,
                recordTime: $recordTime,
                shippingTimezoneOffset: $shipTimezoneOffset,
                receivingTimezoneOffset: $timezoneOffset,
                shippingUuid: $shippingUuid,
                receivingUuid: $receivingUuid,
                fromSglnUrn: $fromLocation['sgln_urn'],
                toSglnUrn: $toLocation['sgln_urn'],
            );

            // A new path, not the ship leg's: writing over it would destroy the
            // ship-only bytes we already transmitted as in_transit.
            $receivePayloadPath = "epcis/outbound/transfer-{$session->getKey()}-receive-{$recordTime->format('Ymd_His')}.xml";

            $document->forceFill([
                // Discriminate by session so two receives in the same millisecond cannot collide.
                'document_uuid' => SbdhInstanceIdentifier::fromEventTime($receiveEventTime, $session->getKey()),
                'original_filename' => "transfer-{$session->getKey()}-receive.xml",
                'payload_path' => $receivePayloadPath,
                'creation_date' => $recordTime,
                'event_count' => 2,
                'epc_count' => $epcCount,
                'status' => 'parsed',
                'processed_at' => $recordTime,
                'last_processed_at' => $recordTime,
            ])->save();

            // Persist XML before marking generated so dual-disk failure rolls back the txn.
            $this->persistEpcisXmlPayload->handle(
                $document,
                $xml,
                $receivePayloadPath,
                (string) $document->payload_disk,
                'Transferring receive EPCIS',
            );

            $session->forceFill([
                'receive_events_generated_at' => $recordTime,
            ])->save();

            return [
                'document' => $document->refresh(),
                'shippingEvent' => $shippingEvent,
                'receivingEvent' => $receivingEvent,
                'generated' => true,
            ];
        });

        $generated = (bool) $built['generated'];

        // Same gate as the ship leg: an authored transfer document is transmitted
        // whenever a connection resolves, with no trading_partner_id requirement.
        // Intracompany transfers usually carry no partner, and the transmitter
        // already marks the document 'skipped' when no connection is configured.
        $this->scheduleOutboundTransmission->afterPersist($built['document'], $generated);

        return [
            'document' => $built['document'],
            'shippingEvent' => $built['shippingEvent'],
            'receivingEvent' => $built['receivingEvent'],
            'generated' => $generated,
        ];
    }

    /**
     * Receive completion releases its lock before calling here. Recheck holds and
     * terminal disposition so a quarantine hold, destroy, or decommission placed
     * after the receive transaction cannot reach the authored transfer-receive EPCIS.
     * Units are still in_transit until this event exists, so operability is
     * {@see EpcCustodyGate::assertNotRetiredFor()} rather than assertOperableFor().
     *
     * @param  list<int>  $epcIds
     */
    private function assertReceivedEpcsStillEligible(array $epcIds): void
    {
        if ($epcIds === []) {
            return;
        }

        $heldIds = $this->receivingGate->epcIdsBlockedByOpenHold($epcIds);
        if ($heldIds !== []) {
            throw new DomainException(
                'Cannot author transferring receive EPCIS: one or more received units are under quarantine.',
            );
        }

        try {
            $this->custodyGate->assertNotRetiredFor($epcIds, 'authoring this transfer receipt');
        } catch (InvalidArgumentException $e) {
            throw new DomainException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{document: ?EpcisDocument, shippingEvent: ?EpcisEvent, receivingEvent: ?EpcisEvent, generated: bool}
     */
    private function existingResult(TransferringSession $session): array
    {
        $documentId = $session->transfer_epcis_document_id;
        $document = $documentId !== null ? EpcisDocument::query()->find($documentId) : null;

        $shippingEvent = null;
        $receivingEvent = null;

        if ($documentId !== null) {
            $shippingEvent = EpcisEvent::query()
                ->where('document_id', $documentId)
                ->where('biz_step', self::BIZ_STEP_SHIPPING)
                ->first();

            $receivingEvent = EpcisEvent::query()
                ->where('document_id', $documentId)
                ->where('biz_step', self::BIZ_STEP_RECEIVING)
                ->first();
        }

        return [
            'document' => $document,
            'shippingEvent' => $shippingEvent,
            'receivingEvent' => $receivingEvent,
            'generated' => false,
        ];
    }

    /**
     * readPoint/bizLocation for the reissued shipping ObjectEvent.
     *
     * The ship leg's own GLNs win over the origin site as it stands today. This event
     * already left as in_transit under a given identity, and a site GLN edited (or a
     * company prefix corrected) between ship and receive would otherwise reissue it
     * pointing at a different location — the same eventID naming somewhere else. The
     * site is consulted only for an SGLN it has on file, or when the ship leg recorded
     * no GLN at all.
     *
     * @return array{gln: string, sgln_urn: string, site_id: int}
     *
     * @throws DomainException when no SGLN can be resolved for the ship-leg GLN
     */
    private function shipLegLocation(EpcisEvent $shippingEvent, int $fromSiteId): array
    {
        $gln = Sgln::normalizeGln($shippingEvent->read_point_gln)
            ?? Sgln::normalizeGln($shippingEvent->biz_location_gln);

        if ($gln === null) {
            return $this->resolveSiteLocation($fromSiteId, 'Transfer origin site');
        }

        $sglnUrn = $this->resolveSiteLocationGlns->sglnUrnForRecordedGln(
            $gln,
            $fromSiteId,
            $this->authoredLocationUrns($shippingEvent),
        );

        if ($sglnUrn === null) {
            throw new DomainException(
                'Cannot author transferring receive EPCIS: no SGLN on record for the shipped-from GLN '.$gln.'. '
                .'Record the origin site SGLN, or set the organization GS1 Company Prefix in Organization Settings.',
            );
        }

        return [
            'gln' => $gln,
            'sgln_urn' => $sglnUrn,
            'site_id' => $fromSiteId,
        ];
    }

    /**
     * SGLN URNs the original event stored for its own locations.
     *
     * @return list<mixed>
     */
    private function authoredLocationUrns(EpcisEvent $event): array
    {
        return DB::table('event_locations')
            ->where('event_id', $event->getKey())
            ->whereNotNull('gln_uri')
            ->pluck('gln_uri')
            ->all();
    }

    /**
     * @return array{gln: string, sgln_urn: string, site_id: int}
     *
     * @throws DomainException when the site has no GLN, or no SGLN can be resolved for it
     */
    private function resolveSiteLocation(int $siteId, string $label): array
    {
        $resolved = $this->resolveSiteLocationGlns->handle($siteId, $label);

        return [
            'gln' => $resolved['gln'],
            'sgln_urn' => $resolved['sgln_urn'],
            'site_id' => $resolved['site_id'],
        ];
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
    private function attachEpcs(EpcisEvent $event, array $epcIds): void
    {
        if ($epcIds === []) {
            return;
        }

        $rows = array_map(fn (int $epcId): array => [
            'event_id' => $event->getKey(),
            'epc_id' => $epcId,
            'role' => 'epcList',
        ], $epcIds);

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('event_epcs')->insertOrIgnore($chunk);
        }
    }

    /**
     * @param  Collection<int, Epc>  $shippingEpcsById  Original ship-leg epcList (full shipment)
     * @param  Collection<int, Epc>  $receivedEpcsById  Received subset for the receiving leg only
     */
    private function buildXml(
        Collection $shippingEpcsById,
        Collection $receivedEpcsById,
        Carbon $shippingEventTime,
        Carbon $receivingEventTime,
        Carbon $recordTime,
        string $shippingTimezoneOffset,
        string $receivingTimezoneOffset,
        string $shippingUuid,
        string $receivingUuid,
        string $fromSglnUrn,
        string $toSglnUrn,
    ): string {
        $creationDate = $recordTime->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $shippingEventTimeXml = $shippingEventTime->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $receivingEventTimeXml = $receivingEventTime->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $recordTimeXml = $creationDate;
        $shipOffsetXml = htmlspecialchars($shippingTimezoneOffset, ENT_XML1);
        $receiveOffsetXml = htmlspecialchars($receivingTimezoneOffset, ENT_XML1);

        $shippingEpcList = $this->epcListXml($shippingEpcsById);
        $receivingEpcList = $this->epcListXml($receivedEpcsById);

        $shippingLocationXml = $this->locationXml($fromSglnUrn);
        $receivingLocationXml = $this->locationXml($toSglnUrn);

        $shippingEvent =
            "              <ObjectEvent>\n".
            "                <eventTime>{$shippingEventTimeXml}</eventTime>\n".
            "                <recordTime>{$recordTimeXml}</recordTime>\n".
            "                <eventTimeZoneOffset>{$shipOffsetXml}</eventTimeZoneOffset>\n".
            "                <baseExtension>\n".
            "                  <eventID>urn:uuid:{$shippingUuid}</eventID>\n".
            "                </baseExtension>\n".
            "                <epcList>\n".
            "{$shippingEpcList}\n".
            "                </epcList>\n".
            "                <action>OBSERVE</action>\n".
            '                <bizStep>'.self::BIZ_STEP_SHIPPING."</bizStep>\n".
            '                <disposition>'.self::DISPOSITION_IN_TRANSIT."</disposition>\n".
            $shippingLocationXml.
            '              </ObjectEvent>';

        $receivingEvent =
            "              <ObjectEvent>\n".
            "                <eventTime>{$receivingEventTimeXml}</eventTime>\n".
            "                <recordTime>{$recordTimeXml}</recordTime>\n".
            "                <eventTimeZoneOffset>{$receiveOffsetXml}</eventTimeZoneOffset>\n".
            "                <baseExtension>\n".
            "                  <eventID>urn:uuid:{$receivingUuid}</eventID>\n".
            "                </baseExtension>\n".
            "                <epcList>\n".
            "{$receivingEpcList}\n".
            "                </epcList>\n".
            "                <action>OBSERVE</action>\n".
            '                <bizStep>'.self::BIZ_STEP_RECEIVING."</bizStep>\n".
            '                <disposition>'.self::DISPOSITION_IN_PROGRESS."</disposition>\n".
            $receivingLocationXml.
            '              </ObjectEvent>';

        return
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".
            "<epcis:EPCISDocument\n".
            "    xmlns:epcis=\"urn:epcglobal:epcis:xsd:1\"\n".
            "    schemaVersion=\"1.2\"\n".
            "    creationDate=\"{$creationDate}\">\n".
            "  <EPCISBody>\n".
            "    <EventList>\n".
            "{$shippingEvent}\n".
            "{$receivingEvent}\n".
            "    </EventList>\n".
            "  </EPCISBody>\n".
            "</epcis:EPCISDocument>\n";
    }

    /**
     * @param  Collection<int, Epc>  $epcsById
     */
    private function epcListXml(Collection $epcsById): string
    {
        return $epcsById
            ->map(fn (Epc $epc): string => '          <epc>'.htmlspecialchars((string) $epc->epc_uri, ENT_XML1).'</epc>')
            ->implode("\n");
    }

    private function locationXml(string $sglnUrn): string
    {
        $safe = htmlspecialchars($sglnUrn, ENT_XML1);

        return
            "        <readPoint>\n".
            "          <id>{$safe}</id>\n".
            "        </readPoint>\n".
            "        <bizLocation>\n".
            "          <id>{$safe}</id>\n".
            "        </bizLocation>\n";
    }

    private function timezoneOffset(?Site $site, Carbon $at): string
    {
        $tzName = $site?->timezone ?: (string) config('app.timezone', 'UTC');

        try {
            return $at->clone()->timezone($tzName)->format('P');
        } catch (Throwable) {
            return $at->clone()->utc()->format('P');
        }
    }
}
