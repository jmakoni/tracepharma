<?php

namespace App\Actions\Transferring;

use App\Actions\Epcis\SyncDocumentEpcsFromEvents;
use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Site;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Receiving\ReceivingGate;
use App\Support\Epcis\PersistEpcisXmlPayload;
use App\Support\Epcis\ResolveSiteLocationGlns;
use App\Support\Epcis\ScheduleOutboundEpcisTransmission;
use DomainException;
use InvalidArgumentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Emit an authored intracompany transfer EPCIS document with a shipping ObjectEvent
 * (in_transit at the from site). Receiving is authored later at destination.
 *
 * Idempotent per session via transfer_events_generated_at.
 *
 * Both sites are resolved through ResolveSiteLocationGlns, the same way the receive
 * leg resolves them: the session has already fixed which warehouses this transfer is
 * between, and CompleteTransferringSession has already asserted the operator may ship
 * from the origin, so what is left here is naming those two locations.
 */
final class GenerateTransferringEpcisEvents
{
    private const DISPOSITION_IN_TRANSIT = 'urn:epcglobal:cbv:disp:in_transit';

    private const BIZ_STEP_SHIPPING = 'urn:epcglobal:cbv:bizstep:shipping';

    public function __construct(
        private readonly ResolveSiteLocationGlns $resolveSiteLocationGlns,
        private readonly SyncDocumentEpcsFromEvents $syncDocumentEpcsFromEvents,
        private readonly ScheduleOutboundEpcisTransmission $scheduleOutboundTransmission,
        private readonly PersistEpcisXmlPayload $persistEpcisXmlPayload,
        private readonly ReceivingGate $receivingGate,
        private readonly EpcCustodyGate $custodyGate,
    ) {}

    /**
     * @return array{
     *     document: ?EpcisDocument,
     *     shippingEvent: ?EpcisEvent,
     *     generated: bool
     * }
     *
     * @throws DomainException when either site has no GLN, or no SGLN we can stand behind
     */
    public function handle(TransferringSession $session, ?int $actorId = null): array
    {
        $session = $session->fresh() ?? $session;

        if ($session->transfer_events_generated_at !== null) {
            return $this->existingResult($session);
        }

        if ($session->status !== 'in_transit') {
            return [
                'document' => null,
                'shippingEvent' => null,
                'generated' => false,
            ];
        }

        $built = DB::transaction(function () use ($session): array {
            // Lock before reading/writing transfer_events_generated_at:
            // CompleteTransferringSession releases its own lock before calling here,
            // so a concurrent caller must be stopped from double-authoring here instead.
            $session = TransferringSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->transfer_events_generated_at !== null) {
                return $this->existingResult($session);
            }

            if ($session->status !== 'in_transit') {
                return [
                    'document' => null,
                    'shippingEvent' => null,
                    'generated' => false,
                ];
            }

            $session->loadMissing('fromSite', 'toSite');

            $epcIds = TransferringScanLine::query()
                ->where('transferring_session_id', $session->getKey())
                ->whereIn('status', ['confirmed', 'received'])
                ->orderBy('id')
                ->pluck('epc_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            /** @var Collection<int, Epc> $epcsById */
            $epcsById = Epc::query()->whereIn('id', $epcIds)->lockForUpdate()->get()->keyBy('id');

            $this->assertConfirmedEpcsStillEligible($epcIds);

            $fromLocation = $this->resolveSiteLocationGlns->handle(
                (int) $session->from_site_id,
                'Transfer origin site',
            );
            // The destination is named on the document the moment the goods leave, and the
            // receive leg will refuse to close without its SGLN. Resolving it here means an
            // unidentifiable destination is caught while the pallet is still on our dock.
            $toLocation = $this->resolveSiteLocationGlns->handle(
                (int) $session->to_site_id,
                'Transfer destination site',
            );

            $recordTime = now();
            $eventTime = $session->shipped_at !== null
                ? Carbon::parse($session->shipped_at)
                : $recordTime;
            $timezoneOffset = $this->timezoneOffset($session->fromSite, $eventTime);

            $shippingUuid = (string) Str::uuid();

            $document = $this->createAuthoredDocument($session, $epcsById, $recordTime, $fromLocation, $toLocation);

            $shippingEvent = EpcisEvent::query()->create($this->authoredEventAttributes([
                'document_id' => $document->getKey(),
                'event_id' => 'urn:uuid:'.$shippingUuid,
                'event_type' => 'ObjectEvent',
                'event_time' => $eventTime,
                'record_time' => $recordTime,
                'event_timezone_offset' => $timezoneOffset,
                'action' => 'OBSERVE',
                'biz_step' => self::BIZ_STEP_SHIPPING,
                'disposition' => self::DISPOSITION_IN_TRANSIT,
                'read_point_gln' => $fromLocation['gln'],
                'biz_location_gln' => $fromLocation['gln'],
            ]));
            $this->attachEpcs($shippingEvent, $epcIds);
            $epcCount = $this->syncDocumentEpcsFromEvents->handle($document);

            $xml = $this->buildXml(
                epcsById: $epcsById,
                eventTime: $eventTime,
                recordTime: $recordTime,
                timezoneOffset: $timezoneOffset,
                shippingUuid: $shippingUuid,
                fromSglnUrn: $fromLocation['sgln_urn'],
            );

            $document->forceFill([
                'event_count' => 1,
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
                'Transferring EPCIS',
            );

            $session->forceFill([
                'transfer_epcis_document_id' => $document->getKey(),
                'transfer_events_generated_at' => $recordTime,
            ])->save();

            return [
                'document' => $document->refresh(),
                'shippingEvent' => $shippingEvent,
                'generated' => true,
            ];
        });

        if (($built['generated'] ?? false) && $built['document'] !== null) {
            $this->scheduleOutboundTransmission->afterPersist($built['document'], true);
        }

        return $built;
    }

    /**
     * Complete releases its lock before calling here. Recheck holds and custody so
     * a quarantine hold, destroy, or decommission placed after the ship transaction
     * cannot reach the authored transfer EPCIS.
     *
     * @param  list<int>  $epcIds
     */
    private function assertConfirmedEpcsStillEligible(array $epcIds): void
    {
        if ($epcIds === []) {
            throw new DomainException('Cannot author transferring EPCIS: no confirmed scan lines on this session.');
        }

        $heldIds = $this->receivingGate->epcIdsBlockedByOpenHold($epcIds);
        if ($heldIds !== []) {
            throw new DomainException(
                'Cannot author transferring EPCIS: one or more confirmed units are under quarantine.',
            );
        }

        try {
            $this->custodyGate->assertOperableFor($epcIds, 'authoring this transfer');
        } catch (InvalidArgumentException $e) {
            throw new DomainException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{document: ?EpcisDocument, shippingEvent: ?EpcisEvent, generated: bool}
     */
    private function existingResult(TransferringSession $session): array
    {
        $documentId = $session->transfer_epcis_document_id;
        $document = $documentId !== null ? EpcisDocument::query()->find($documentId) : null;

        $shippingEvent = null;

        if ($documentId !== null) {
            $shippingEvent = EpcisEvent::query()
                ->where('document_id', $documentId)
                ->where('biz_step', self::BIZ_STEP_SHIPPING)
                ->first();
        }

        return [
            'document' => $document,
            'shippingEvent' => $shippingEvent,
            'generated' => false,
        ];
    }

    /**
     * @param  Collection<int, Epc>  $epcsById
     * @param  array{site_id: int, gln: string, sgln_urn: string, site: Site}  $fromLocation
     * @param  array{site_id: int, gln: string, sgln_urn: string, site: Site}  $toLocation
     */
    private function createAuthoredDocument(
        TransferringSession $session,
        Collection $epcsById,
        Carbon $now,
        array $fromLocation,
        array $toLocation,
    ): EpcisDocument {
        $disk = (string) config('tracepharma.epcis.authored_payload_disk', 'local');
        $documentUuid = (string) Str::uuid();
        $payloadPath = "epcis/outbound/transfer-{$session->getKey()}-{$now->format('Ymd_His')}.xml";

        return EpcisDocument::query()->create($this->authoredDocumentAttributes([
            'document_uuid' => $documentUuid,
            'schema_version' => '1.2',
            'creation_date' => $now,
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Transferring,
            'format' => 'xml',
            'original_filename' => "transfer-{$session->getKey()}.xml",
            'payload_disk' => $disk,
            'payload_path' => $payloadPath,
            'dscsa_affirm' => false,
            'status' => 'generated',
            'notes' => "Generated transferring EPCIS (intracompany custody) for transferring session #{$session->getKey()}.",
            'reprocess_count' => 0,
            'event_count' => 0,
            'epc_count' => $epcsById->count(),
            'received_at' => $now,
            'ship_from_site_id' => $fromLocation['site_id'],
            'ship_from_gln' => $fromLocation['gln'],
            'ship_to_site_id' => $toLocation['site_id'],
            'ship_to_gln' => $toLocation['gln'],
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
     * @param  Collection<int, Epc>  $epcsById
     */
    private function buildXml(
        Collection $epcsById,
        Carbon $eventTime,
        Carbon $recordTime,
        string $timezoneOffset,
        string $shippingUuid,
        string $fromSglnUrn,
    ): string {
        $creationDate = $recordTime->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $eventTimeXml = $eventTime->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $recordTimeXml = $creationDate;
        $offsetXml = htmlspecialchars($timezoneOffset, ENT_XML1);

        $epcList = $epcsById
            ->map(fn (Epc $epc): string => '          <epc>'.htmlspecialchars((string) $epc->epc_uri, ENT_XML1).'</epc>')
            ->implode("\n");

        $shippingLocationXml = $this->locationXml($fromSglnUrn);

        $shippingEvent =
            "              <ObjectEvent>\n".
            "                <eventTime>{$eventTimeXml}</eventTime>\n".
            "                <recordTime>{$recordTimeXml}</recordTime>\n".
            "                <eventTimeZoneOffset>{$offsetXml}</eventTimeZoneOffset>\n".
            "                <eventID>urn:uuid:{$shippingUuid}</eventID>\n".
            "                <epcList>\n".
            "{$epcList}\n".
            "                </epcList>\n".
            "                <action>OBSERVE</action>\n".
            '                <bizStep>'.self::BIZ_STEP_SHIPPING."</bizStep>\n".
            '                <disposition>'.self::DISPOSITION_IN_TRANSIT."</disposition>\n".
            $shippingLocationXml.
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
            "    </EventList>\n".
            "  </EPCISBody>\n".
            "</epcis:EPCISDocument>\n";
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
