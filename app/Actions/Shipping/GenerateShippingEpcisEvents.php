<?php

namespace App\Actions\Shipping;

use App\Actions\Epcis\SyncDocumentEpcsFromEvents;
use App\Enums\EpcisAuthoredKind;
use App\Enums\PartnerType;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\OutboundConnection;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Dscsa\Support\DscsaDirectPurchaseStatements;
use App\Services\Epcis\Outbound\JsonLd20Writer;
use App\Services\Epcis\Outbound\OutboundEpcisDocumentWriter;
use App\Services\Epcis\Outbound\OutboundEpcisWriterResolver;
use App\Support\Epcis\BuildFullHistoryShippingEpcisXml;
use App\Support\Epcis\EpcisSchemaVersion;
use App\Support\Epcis\OutboundEpcisFilename;
use App\Support\Epcis\PersistAuthoredEventLocations;
use App\Support\Epcis\PersistEpcisXmlPayload;
use App\Support\Epcis\SbdhInstanceIdentifier;
use App\Support\Epcis\ScheduleOutboundEpcisTransmission;
use App\Support\Epcis\ShippingTiTsFragments;
use App\Support\Gs1\Sgln;
use App\Support\Gs1\SglnResolution;
use App\Support\Shipping\AtpGateBypass;
use App\Support\Shipping\CorrectiveShipmentDocument;
use App\Support\Shipping\ResolveOutboundShipToSgln;
use App\Support\Shipping\ResolveShipFromSite;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\TenantSettings;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Emit an authored outbound shipping EPCIS document with a shipping ObjectEvent
 * (in_transit, read at the ship-from dock and bound for the customer), carrying
 * DSCSA TI/TS: SBDH, PO/ASN business transactions, the four source/destination
 * parties, and the transaction statement.
 *
 * The live event projection stays a shipping ObjectEvent on the scanned outermost
 * units (custody). When those units still have an open aggregation tree — or prior
 * commissioning/packing documents exist for the confirmed EPCs — the on-disk partner
 * payload is the self-contained commission → pack → ship XML from
 * {@see BuildFullHistoryShippingEpcisXml}. Otherwise the lean shipping-only payload
 * is used (JSON-LD 2.0 always stays lean-writer path).
 *
 * Download / transmit always uses the on-disk partner TI payload. Live `epcis_events`
 * for the session document may list only the authored shipping ObjectEvent.
 *
 * Idempotent per session via shipping_events_generated_at.
 *
 * @phpstan-type TiTsParty array{
 *     party_role: string,
 *     source_dest_type: string,
 *     source_dest_type_uri: string,
 *     sgln: string,
 *     gln: ?string,
 *     site_id: ?int,
 *     trading_partner_id: ?int
 * }
 */
final class GenerateShippingEpcisEvents
{
    private const DISPOSITION_IN_TRANSIT = 'urn:epcglobal:cbv:disp:in_transit';

    private const BIZ_STEP_SHIPPING = 'urn:epcglobal:cbv:bizstep:shipping';

    public function __construct(
        private readonly ResolveShipFromSite $resolveShipFromSite,
        private readonly SyncDocumentEpcsFromEvents $syncDocumentEpcsFromEvents,
        private readonly ScheduleOutboundEpcisTransmission $scheduleOutboundTransmission,
        private readonly PersistEpcisXmlPayload $persistEpcisXmlPayload,
        private readonly PersistAuthoredEventLocations $persistAuthoredEventLocations,
        private readonly EpcCustodyGate $custodyGate,
        private readonly BuildFullHistoryShippingEpcisXml $buildFullHistoryShippingEpcisXml,
        private readonly ShippableEpcsAtSite $shippableEpcsAtSite,
        private readonly ResolveOutboundShipToSgln $resolveOutboundShipToSgln,
        private readonly OutboundEpcisWriterResolver $writerResolver,
        private readonly JsonLd20Writer $jsonLd20Writer,
        private readonly DscsaDirectPurchaseStatements $directPurchaseStatements,
    ) {}

    /**
     * @return array{
     *     document: ?EpcisDocument,
     *     shippingEvent: ?EpcisEvent,
     *     generated: bool
     * }
     */
    public function handle(OutboundShippingSession $session, ?int $actorId = null): array
    {
        $session = $session->fresh() ?? $session;

        if ($session->status !== 'completed' && $session->shipping_events_generated_at === null) {
            return [
                'document' => null,
                'shippingEvent' => null,
                'generated' => false,
            ];
        }

        $built = DB::transaction(function () use ($session): array {
            // Lock the session row before reading/writing shipping_events_generated_at:
            // CompleteOutboundShippingSession releases its own lock before calling here,
            // so a concurrent caller must be stopped from double-authoring here instead.
            $session = OutboundShippingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->shipping_events_generated_at !== null) {
                return $this->existingResult($session);
            }

            if ($session->status !== 'completed') {
                return [
                    'document' => null,
                    'shippingEvent' => null,
                    'generated' => false,
                ];
            }

            $session->loadMissing(['site.tradingPartner', 'tradingPartner', 'shipToSite']);

            $tenant = tenant();
            if (! $tenant instanceof Tenant) {
                throw new DomainException('Cannot author shipping EPCIS outside tenant context.');
            }

            $epcIds = $this->outermostConfirmedEpcIds($session);

            $connection = $session->outbound_connection_id !== null
                ? OutboundConnection::query()->find($session->outbound_connection_id)
                : null;
            $writer = $this->writerResolver->forConnection($connection);
            $isJson20 = $writer->schemaVersion() === EpcisSchemaVersion::V20;
            $includeFullHistory = ! $isJson20 && (
                $this->hasOpenAggregationDescendants($epcIds)
                || $this->hasPriorCommissionOrPackDocuments($epcIds)
            );

            /** @var Collection<int, Epc> $epcsById */
            $epcsById = Epc::query()->whereIn('id', $epcIds)->lockForUpdate()->get()->keyBy('id');

            $this->assertConfirmedLinesStillOperable($session, $epcIds);

            $fromLocation = $this->resolveSiteLocation((int) $session->site_id);
            $shipTo = $this->resolveShipTo($session);
            $party = $this->resolveAuthoredPartyFields($session, $tenant, $shipTo);
            $tiTs = $this->resolveTransactionIdentity($session, $party, $fromLocation, $shipTo);

            $recordTime = now();
            $eventTime = $session->completed_at !== null
                ? Carbon::parse($session->completed_at)
                : $recordTime;
            $timezoneOffset = $this->timezoneOffset($session->site, $eventTime);

            $shippingUuid = (string) Str::uuid();

            $document = $this->createAuthoredDocument(
                session: $session,
                epcsById: $epcsById,
                now: $recordTime,
                eventTime: $eventTime,
                shipTo: $shipTo,
                tenant: $tenant,
                party: $party,
                writer: $writer,
            );

            // readPoint is the dock the unit was scanned at; bizLocation is where it comes
            // to rest — the customer. Custody hangs off bizLocation, so naming the
            // ship-from site there would keep shipped stock reading as ours. The XML omits
            // bizLocation per the GS1 US IG and carries the customer on destinationList.
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
                'biz_location_gln' => $shipTo['gln'],
                'trading_partner_id' => $session->trading_partner_id,
            ]));
            $this->persistAuthoredEventLocations->handle($shippingEvent, [
                [
                    'location_type' => 'readPoint',
                    'gln' => $fromLocation['gln'],
                    'gln_uri' => $fromLocation['sgln_urn'],
                    'site_id' => (int) $session->site_id,
                ],
            ]);
            $this->attachEpcs($shippingEvent, $epcIds);
            $this->attachTransactionIdentity($shippingEvent, $tiTs);
            $epcCount = $this->syncDocumentEpcsFromEvents->handle($document);

            $directPurchaseStatement = $this->resolveOutboundDirectPurchaseStatement((bool) $session->dscsa_affirm);

            $payloadPath = (string) $document->payload_path;
            $eventCount = 1;

            if ($isJson20) {
                $payload = $this->buildJsonLd20(
                    epcsById: $epcsById,
                    eventTime: $eventTime,
                    recordTime: $recordTime,
                    timezoneOffset: $timezoneOffset,
                    shippingUuid: $shippingUuid,
                    instanceId: (string) $document->document_uuid,
                    tiTs: $tiTs,
                    affirmTransactionStatement: (bool) $session->dscsa_affirm,
                    isDropShipment: (bool) $session->is_drop_shipment,
                    directPurchaseStatement: $directPurchaseStatement,
                );
            } elseif ($includeFullHistory) {
                // Full-history builder resolves the shipping event id via the session document.
                $session->forceFill(['epcis_document_id' => $document->getKey()])->save();
                $session->setRelation('epcisDocument', $document);

                $built = $this->buildFullHistoryShippingEpcisXml->handle($session);
                $payload = $built['xml'];
                $payloadPath = $built['path'];
                $eventCount = substr_count($payload, '<ObjectEvent>') + substr_count($payload, '<AggregationEvent>');

                $document->forceFill([
                    'document_uuid' => $built['instance_id'],
                    'original_filename' => $built['filename'],
                    'payload_path' => $built['path'],
                    'creation_date' => $built['ship_event_time']->copy()->addSeconds(4),
                ])->save();
            } else {
                $payload = $this->buildXml(
                    epcsById: $epcsById,
                    eventTime: $eventTime,
                    recordTime: $recordTime,
                    timezoneOffset: $timezoneOffset,
                    shippingUuid: $shippingUuid,
                    instanceId: (string) $document->document_uuid,
                    tiTs: $tiTs,
                    affirmTransactionStatement: (bool) $session->dscsa_affirm,
                    isDropShipment: (bool) $session->is_drop_shipment,
                    directPurchaseStatement: $directPurchaseStatement,
                );
            }

            ShippingTiTsFragments::assertDropShipmentEmitted(
                isDropShipment: (bool) $session->is_drop_shipment,
                payload: $payload,
            );

            $document->forceFill([
                'event_count' => $eventCount,
                'epc_count' => $epcCount,
                'status' => 'parsed',
                'processed_at' => $recordTime,
                'last_processed_at' => $recordTime,
            ])->save();

            $this->persistEpcisXmlPayload->handle(
                $document,
                $payload,
                $payloadPath,
                (string) $document->payload_disk,
                'Shipping EPCIS',
            );

            $session->forceFill([
                'epcis_document_id' => $document->getKey(),
                'shipping_events_generated_at' => $recordTime,
            ])->save();

            return [
                'document' => $document->refresh(),
                'shippingEvent' => $shippingEvent,
                'generated' => true,
            ];
        });

        if ($built['generated']) {
            $this->scheduleOutboundTransmission->afterPersist($built['document'], true);
            $this->assertOutboundHookDidNotFail($built['document']);
        }

        return $built;
    }

    /**
     * afterPersist swallows hook exceptions so authoring is not rolled back.
     * Surface failed/skipped transmission to the caller so the UI cannot report
     * a successful send. Benign skips (empty path, no active connection) stay silent.
     */
    private function assertOutboundHookDidNotFail(EpcisDocument $document): void
    {
        $document = $document->fresh() ?? $document;
        $status = $document->transmission_status;

        if (! in_array($status, ['failed', 'skipped'], true)) {
            return;
        }

        if ($status === 'skipped' && in_array((string) $document->error_message, [
            'EPCIS payload path is empty.',
            'No active outbound connection.',
        ], true)) {
            return;
        }

        $detail = filled($document->error_message)
            ? (string) $document->error_message
            : $status;

        throw new DomainException(
            'Shipping EPCIS was authored but outbound transmission did not succeed: '.$detail,
        );
    }

    /**
     * Complete already re-checks under its own lock, then releases it before calling
     * here. Recheck again under this lock so a hold, a lost-custody event, or a
     * shipping ObjectEvent authored on another session cannot slip through.
     *
     * @param  list<int>  $epcIds
     */
    private function assertConfirmedLinesStillOperable(OutboundShippingSession $session, array $epcIds): void
    {
        if ($epcIds === []) {
            return;
        }

        // Corrective orders re-author units that already left on the original
        // shipment; the custody gate below is the one that checks prior ship evidence.
        if (! $session->is_corrective) {
            $alreadyShippedElsewhere = OutboundShippingScanLine::query()
                ->whereIn('epc_id', $epcIds)
                ->where('status', 'confirmed')
                ->whereHas('session', function ($query) use ($session): void {
                    $query
                        ->whereKeyNot($session->getKey())
                        ->whereNotNull('shipping_events_generated_at');
                })
                ->exists();

            if ($alreadyShippedElsewhere) {
                throw new DomainException(
                    'Cannot author shipping EPCIS: one or more confirmed units already have shipping events on another ship order.',
                );
            }
        }

        try {
            if ($session->is_corrective) {
                $this->custodyGate->assertCorrectiveShipAllowed(
                    $epcIds,
                    $session->corrects_epcis_document_id !== null
                        ? (int) $session->corrects_epcis_document_id
                        : null,
                    $session->site_id !== null ? (int) $session->site_id : null,
                );

                return;
            }

            $siteId = $session->site_id !== null ? (int) $session->site_id : null;
            if ($siteId !== null) {
                $shippable = $this->shippableEpcsAtSite->filter($siteId, $epcIds);
                if (count($shippable) !== count($epcIds)) {
                    throw new DomainException(
                        'Cannot author shipping EPCIS: one or more confirmed units are no longer shippable inventory at the ship-from site.',
                    );
                }
            }

            $this->custodyGate->assertOperableFor($epcIds, 'authoring this shipment');
        } catch (InvalidArgumentException $e) {
            throw new DomainException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Every confirmed line is a directly scanned unit and belongs on the epcList — no
     * ship path nests lines under a parent, and rows authored before bare SGTINs were
     * recorded as 'parent' would otherwise drop out of the shipment.
     *
     * @return list<int>
     */
    private function outermostConfirmedEpcIds(OutboundShippingSession $session): array
    {
        return OutboundShippingScanLine::query()
            ->where('outbound_shipping_session_id', $session->getKey())
            ->where('status', 'confirmed')
            ->orderBy('id')
            ->pluck('epc_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function hasOpenAggregationDescendants(array $epcIds): bool
    {
        if ($epcIds === []) {
            return false;
        }

        return AggregationLink::query()
            ->open()
            ->whereIn('parent_epc_id', $epcIds)
            ->exists();
    }

    /**
     * GS1 US TI includes commissioning "if applicable" even for bare unit shipments
     * with no open pack tree. When prior commission/pack documents exist for these
     * EPCs, use full-history payload assembly (replay + ship) instead of lean ship-only.
     *
     * @param  list<int>  $epcIds
     */
    private function hasPriorCommissionOrPackDocuments(array $epcIds): bool
    {
        if ($epcIds === []) {
            return false;
        }

        $query = DB::table('epcis_events as e')
            ->join('event_epcs as ee', 'ee.event_id', '=', 'e.id')
            ->whereIn('ee.epc_id', $epcIds)
            ->where(function ($q): void {
                $q->where('e.biz_step', 'like', '%:commissioning')
                    ->orWhere('e.biz_step', 'like', '%:packing');
            });

        if (Schema::hasColumn('epcis_events', 'superseded_at')) {
            $query->whereNull('e.superseded_at');
        }

        return $query->exists();
    }

    /**
     * Ship-to identity for the authored event and document: the GLN, the site it
     * came from when we know it, and the SGLN to write as the destination location.
     *
     * Both are required. Custody hangs off bizLocation and the customer is named on
     * destinationList, so authoring the ship-from location in either place would read
     * as goods that never left the building. ValidateOutboundShippingSend stops the
     * send first; this is the backstop for any other path into authoring.
     *
     * @return array{gln: string, site_id: ?int, sgln_urn: string}
     */
    private function resolveShipTo(OutboundShippingSession $session): array
    {
        $party = $this->resolveOutboundShipToSgln->destParty($session);

        if ($party['gln'] === null) {
            throw new DomainException(
                'Cannot author shipping EPCIS: ship-to GLN is required. Set a ship-to site or GLN on the ship order.',
            );
        }

        $sglnUrn = $this->resolveOutboundShipToSgln->resolve($session);

        if ($sglnUrn === null) {
            throw new DomainException(
                'Cannot author shipping EPCIS: no SGLN on record for ship-to GLN '.$party['gln'].'. '
                .'Record the customer\'s SGLN (urn:epc:id:sgln:companyPrefix.locationReference.extension) on the '
                .'trading partner or ship-to site — a partner\'s GS1 company prefix is theirs to state, not ours to guess.',
            );
        }

        return [
            'gln' => $party['gln'],
            'site_id' => $party['site_id'],
            'sgln_urn' => $sglnUrn,
        ];
    }

    /**
     * @return array{document: ?EpcisDocument, shippingEvent: ?EpcisEvent, generated: bool}
     */
    private function existingResult(OutboundShippingSession $session): array
    {
        $documentId = $session->epcis_document_id;
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
     * @return array{gln: string, sgln_urn: string, site: Site}
     */
    private function resolveSiteLocation(int $siteId): array
    {
        $resolved = $this->resolveShipFromSite->locationGlnsForAuthoring($siteId);
        $site = $resolved['site'];
        $gln = $resolved['gln'];

        $sglnUrn = $this->resolveSiteSglnUrn($site, $gln);
        if ($sglnUrn === null) {
            throw new DomainException(
                'Cannot author shipping EPCIS: no SGLN on record for ship-from GLN '.$gln.'. '
                .'Set the organization GS1 Company Prefix in Organization settings, or record the site SGLN.',
            );
        }

        return [
            'gln' => $gln,
            'sgln_urn' => $sglnUrn,
            'site' => $site,
        ];
    }

    private function resolveSiteSglnUrn(Site $site, string $gln): ?string
    {
        return $this->resolveSglnUrnForGln($gln, $this->sglnCandidates($site->getAttribute('sgln')));
    }

    /**
     * The SGLN on record, or the one our own company prefix encodes — never a guess.
     * A customer's company-prefix split is theirs to publish, so when they have not,
     * the shipment stops here rather than naming them by an SGLN they never used.
     *
     * @param  list<string>  $candidates  SGLN URNs on record for this location
     */
    private function resolveSglnUrnForGln(string $gln, array $candidates, bool $partnerLocation = false): ?string
    {
        $settings = TenantSettings::forTenant(tenant());
        $prefix = $partnerLocation
            ? $settings->companyPrefixForPartnerEncoding()
            : $settings->companyPrefix();

        return SglnResolution::resolve(
            $gln,
            $candidates,
            $prefix,
        );
    }

    /**
     * @return list<string>
     */
    private function sglnCandidates(mixed ...$values): array
    {
        $candidates = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $candidates[] = $value;
            }
        }

        return $candidates;
    }

    /**
     * DSCSA transaction information for the shipment: the PO/ASN references and the
     * four source/destination parties the GS1 US Implementation Guideline expects on
     * a shipping event.
     *
     * @param  array{sender_gln: ?string, receiver_gln: ?string}  $party
     * @param  array{gln: string, sgln_urn: string, site: Site}  $fromLocation
     * @param  array{gln: string, site_id: ?int, sgln_urn: string}  $shipTo
     * @return array{
     *     po: ?string,
     *     asn: ?string,
     *     sender_gln: ?string,
     *     receiver_gln: ?string,
     *     parties: array<string, TiTsParty>
     * }
     */
    private function resolveTransactionIdentity(
        OutboundShippingSession $session,
        array $party,
        array $fromLocation,
        array $shipTo,
    ): array {
        // Emit recorded SGLNs as-is — do not rewrite facility extensions.
        $sourceLocationSgln = $fromLocation['sgln_urn'];
        $destLocationSgln = $shipTo['sgln_urn'];

        $sourceOwningSgln = $this->owningPartySgln(
            $party['sender_gln'],
            $fromLocation['gln'],
            $sourceLocationSgln,
            $this->sglnCandidates($fromLocation['site']->getAttribute('sgln')),
        );

        $destOwningSgln = $this->owningPartySgln(
            $party['receiver_gln'],
            $shipTo['gln'],
            $destLocationSgln,
            $this->resolveOutboundShipToSgln->candidates($session),
        );

        $partnerId = $session->trading_partner_id !== null ? (int) $session->trading_partner_id : null;
        $fromSiteId = (int) $fromLocation['site']->getKey();

        $parties = [
            'source_owning' => $this->transactionParty(
                'source',
                owning: true,
                sgln: $sourceOwningSgln,
                siteId: $party['sender_gln'] === $fromLocation['gln'] ? $fromSiteId : null,
                partnerId: null,
            ),
            'source_location' => $this->transactionParty(
                'source',
                owning: false,
                sgln: $sourceLocationSgln,
                siteId: $fromSiteId,
                partnerId: null,
            ),
            'dest_owning' => $this->transactionParty(
                'destination',
                owning: true,
                sgln: $destOwningSgln,
                siteId: null,
                partnerId: $partnerId,
            ),
            'dest_location' => $this->transactionParty(
                'destination',
                owning: false,
                sgln: $destLocationSgln,
                siteId: $shipTo['site_id'],
                partnerId: $partnerId,
            ),
        ];

        return [
            // The PO is the DSCSA transaction reference; the invoice stands in when the
            // customer ordered without one.
            'po' => $this->reference($session->customer_po) ?? $this->reference($session->invoice_number),
            'asn' => $this->reference($session->asn_number),
            // Taken from the authored SGLNs so the SBDH cannot disagree with the parties
            // on the event.
            'sender_gln' => $parties['source_owning']['gln'] ?? $party['sender_gln'],
            'receiver_gln' => $parties['dest_owning']['gln'] ?? $party['receiver_gln'],
            'parties' => $parties,
        ];
    }

    /**
     * @return TiTsParty
     */
    private function transactionParty(
        string $role,
        bool $owning,
        string $sgln,
        ?int $siteId,
        ?int $partnerId,
    ): array {
        return [
            'party_role' => $role,
            'source_dest_type' => $owning ? 'owning_party' : 'location',
            'source_dest_type_uri' => $owning
                ? ShippingTiTsFragments::SDT_OWNING_PARTY
                : ShippingTiTsFragments::SDT_LOCATION,
            'sgln' => $sgln,
            'gln' => Sgln::fromUrn($sgln)['gln'] ?? null,
            'site_id' => $siteId,
            'trading_partner_id' => $partnerId,
        ];
    }

    /**
     * Owning-party SGLN from master data / candidates for the owning GLN.
     * When unresolved, fall back to the location SGLN without rewriting its extension.
     *
     * @param  list<string>  $candidates
     */
    private function owningPartySgln(
        ?string $owningGln,
        ?string $locationGln,
        string $locationSgln,
        array $candidates,
    ): string {
        $resolved = null;

        if ($owningGln !== null && $owningGln !== $locationGln) {
            $resolved = $this->resolveSglnUrnForGln($owningGln, $candidates, partnerLocation: true);
        }

        if ($resolved === null && $owningGln !== null) {
            $resolved = $this->resolveSglnUrnForGln($owningGln, $candidates, partnerLocation: true);
        }

        return $resolved ?? $locationSgln;
    }

    private function reference(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  Collection<int, Epc>  $epcsById
     * @param  array{gln: string, site_id: ?int, sgln_urn: string}  $shipTo
     * @param  array{
     *     ship_from_name: ?string,
     *     ship_from_site_name: ?string,
     *     ship_to_name: ?string,
     *     ship_to_site_name: ?string,
     *     sender_gln: ?string,
     *     receiver_gln: ?string
     * }  $party
     */
    private function createAuthoredDocument(
        OutboundShippingSession $session,
        Collection $epcsById,
        Carbon $now,
        Carbon $eventTime,
        array $shipTo,
        Tenant $tenant,
        array $party,
        OutboundEpcisDocumentWriter $writer,
    ): EpcisDocument {
        // Authored outbound payloads stay on local tenant storage; inbound S3 disk is for uploads.
        $disk = (string) config('tracepharma.epcis.authored_payload_disk', 'local');

        $format = $writer->format();
        $extension = $format === EpcisSchemaVersion::FORMAT_JSON ? 'json' : 'xml';
        $filename = OutboundEpcisFilename::forShippingEvent($tenant, $eventTime, $extension);
        $payloadPath = OutboundEpcisFilename::storagePath($tenant, $eventTime, $extension);
        // Two sessions completing in the same millisecond still need unique document_uuid
        // values — RFC 4122 urn:uuid: is collision-safe without a timestamp stamp.
        $documentUuid = SbdhInstanceIdentifier::uuid();

        $attributes = [
            'document_uuid' => $documentUuid,
            'schema_version' => $writer->schemaVersion(),
            'creation_date' => $now,
            'direction' => 'outbound',
            'authored_kind' => EpcisAuthoredKind::Shipping,
            'trading_partner_id' => $session->trading_partner_id,
            'ship_to_partner_id' => $session->trading_partner_id,
            'sender_gln' => $party['sender_gln'],
            'receiver_gln' => $party['receiver_gln'],
            'format' => $format,
            'original_filename' => $filename,
            'payload_disk' => $disk,
            'payload_path' => $payloadPath,
            'dscsa_affirm' => (bool) $session->dscsa_affirm,
            'status' => 'generated',
            'notes' => $this->documentNotes($session),
            'reprocess_count' => 0,
            'event_count' => 0,
            'epc_count' => $epcsById->count(),
            'received_at' => $now,
            'ship_from_site_id' => $session->site_id,
            'ship_from_gln' => Sgln::normalizeGln($session->site?->gln),
            'ship_to_site_id' => $shipTo['site_id'],
            'ship_to_gln' => $shipTo['gln'],
            'ship_from_name' => $party['ship_from_name'],
            'ship_from_site_name' => $party['ship_from_site_name'],
            'ship_to_name' => $party['ship_to_name'],
            'ship_to_site_name' => $party['ship_to_site_name'],
            'asn_number' => $session->asn_number,
            'customer_po' => $session->customer_po,
            'invoice_number' => $session->invoice_number,
        ];

        if ($session->outbound_connection_id !== null) {
            $attributes['outbound_connection_id'] = $session->outbound_connection_id;
        }

        if (
            $session->is_corrective
            && $session->corrects_epcis_document_id !== null
            && CorrectiveShipmentDocument::columnExists()
        ) {
            $attributes[CorrectiveShipmentDocument::COLUMN] = (int) $session->corrects_epcis_document_id;
        }

        return EpcisDocument::query()->create($this->authoredDocumentAttributes($attributes));
    }

    /**
     * A corrective shipment is a normal Shipping authored document; the reason and the
     * document it amends live in the notes so auditors can follow the correction trail,
     * alongside the structured link on {@see CorrectiveShipmentDocument::COLUMN}.
     *
     * A shipment sent while the ATP outbound gate was lifted says so here too: the
     * config value that let it through is not part of the record otherwise.
     */
    private function documentNotes(OutboundShippingSession $session): string
    {
        $notes = "Generated outbound shipping EPCIS for ship order session #{$session->getKey()}.";

        if (AtpGateBypass::isBypassed()) {
            $notes .= ' '.AtpGateBypass::NOTE_MARKER
                .' The destination\'s ATP licence was not verified for this shipment.';
        }

        if (! $session->is_corrective) {
            return $notes;
        }

        $notes .= ' '.CorrectiveShipmentDocument::NOTE_MARKER;

        if ($session->corrects_epcis_document_id !== null) {
            $notes .= ' Corrects EPCIS document #'.(int) $session->corrects_epcis_document_id.'.';
        }

        if (filled($session->corrective_reason)) {
            $notes .= ' Reason: '.trim((string) $session->corrective_reason);
        }

        return $notes;
    }

    /**
     * Denormalized seller/sold-to display + SBDH sender/receiver GLNs for authored ship docs.
     *
     * @param  array{gln: string, site_id: ?int, sgln_urn: string}  $shipTo
     * @return array{
     *     ship_from_name: ?string,
     *     ship_from_site_name: ?string,
     *     ship_to_name: ?string,
     *     ship_to_site_name: ?string,
     *     sender_gln: ?string,
     *     receiver_gln: ?string
     * }
     */
    private function resolveAuthoredPartyFields(
        OutboundShippingSession $session,
        Tenant $tenant,
        array $shipTo,
    ): array {
        $shipFromSite = $session->site;
        $partner = $session->tradingPartner;
        $shipToSite = $session->shipToSite;

        // Seller / owning party: site's organization partner when present, else tenant org name.
        $shipFromName = filled($shipFromSite?->tradingPartner?->name)
            ? (string) $shipFromSite->tradingPartner->name
            : (filled($tenant->name) ? (string) $tenant->name : null);

        $orgGln = TenantSettings::forTenant($tenant)->gln();
        $senderGln = $orgGln ?? Sgln::normalizeGln($shipFromSite?->gln);

        $receiverGln = filled($partner?->gln)
            ? Sgln::normalizeGln((string) $partner->gln)
            : $shipTo['gln'];

        return [
            'ship_from_name' => $shipFromName,
            'ship_from_site_name' => filled($shipFromSite?->name) ? (string) $shipFromSite->name : null,
            'ship_to_name' => filled($partner?->name) ? (string) $partner->name : null,
            'ship_to_site_name' => filled($shipToSite?->name) ? (string) $shipToSite->name : null,
            'sender_gln' => $senderGln,
            'receiver_gln' => $receiverGln,
        ];
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
     * Mirror the authored TI/TS onto the event catalog, so validation, tracing and
     * DSCSA reports read the same references and parties the XML carries.
     *
     * @param  array{po: ?string, asn: ?string, parties: array<string, TiTsParty>}  $tiTs
     */
    private function attachTransactionIdentity(EpcisEvent $event, array $tiTs): void
    {
        $transactions = ShippingTiTsFragments::bizTransactions(
            po: $tiTs['po'],
            asn: $tiTs['asn'],
            destOwningGln: $tiTs['parties']['dest_owning']['gln'],
            sourceOwningGln: $tiTs['parties']['source_owning']['gln'],
        );

        if ($transactions !== []) {
            DB::table('event_biz_transactions')->insert(array_map(fn (array $transaction): array => [
                'event_id' => $event->getKey(),
                'type_uri' => $transaction['type_uri'],
                'value' => $transaction['value'],
            ], $transactions));
        }

        DB::table('event_parties')->insert(array_map(fn (array $party): array => [
            'event_id' => $event->getKey(),
            'party_role' => $party['party_role'],
            'gln' => $party['gln'],
            'gln_uri' => $party['sgln'],
            'trading_partner_id' => $party['trading_partner_id'],
            'site_id' => $party['site_id'],
            'extra_json' => json_encode([
                'source_dest_type' => $party['source_dest_type'],
                'source_dest_type_uri' => $party['source_dest_type_uri'],
            ], JSON_THROW_ON_ERROR),
        ], array_values($tiTs['parties'])));
    }

    /**
     * @param  Collection<int, Epc>  $epcsById
     * @param  array{
     *     po: ?string,
     *     asn: ?string,
     *     sender_gln: ?string,
     *     receiver_gln: ?string,
     *     parties: array<string, TiTsParty>
     * }  $tiTs
     */
    private function buildJsonLd20(
        Collection $epcsById,
        Carbon $eventTime,
        Carbon $recordTime,
        string $timezoneOffset,
        string $shippingUuid,
        string $instanceId,
        array $tiTs,
        bool $affirmTransactionStatement = false,
        bool $isDropShipment = false,
        ?string $directPurchaseStatement = null,
    ): string {
        $parties = $tiTs['parties'];

        $epcList = $epcsById
            ->map(fn (Epc $epc): string => (string) $epc->epc_uri)
            ->values()
            ->all();

        $sourceDestination = ShippingTiTsFragments::sourceDestinationListsJson(
            sourceOwningSgln: $parties['source_owning']['sgln'],
            sourceLocationSgln: $parties['source_location']['sgln'],
            destOwningSgln: $parties['dest_owning']['sgln'],
            destLocationSgln: $parties['dest_location']['sgln'],
        );

        $event = [
            'type' => 'ObjectEvent',
            'eventTime' => $eventTime->clone()->utc()->format(DateTimeInterface::ATOM),
            'eventTimeZoneOffset' => $timezoneOffset,
            'eventID' => 'urn:uuid:'.$shippingUuid,
            'epcList' => $epcList,
            'action' => 'OBSERVE',
            'bizStep' => self::BIZ_STEP_SHIPPING,
            'disposition' => self::DISPOSITION_IN_TRANSIT,
            'readPoint' => ['id' => $parties['source_location']['sgln']],
            'sourceList' => $sourceDestination['sourceList'],
            'destinationList' => $sourceDestination['destinationList'],
        ];

        $bizTransactionList = ShippingTiTsFragments::bizTransactionListJson(
            po: $tiTs['po'],
            asn: $tiTs['asn'],
            destOwningGln: $parties['dest_owning']['gln'],
            sourceOwningGln: $parties['source_owning']['gln'],
        );

        if ($bizTransactionList !== []) {
            $event['bizTransactionList'] = $bizTransactionList;
        }

        if ($directPurchaseStatement !== null && $directPurchaseStatement !== '') {
            $event = array_merge($event, ShippingTiTsFragments::directPurchaseExtensionJson($directPurchaseStatement));
        }

        $json = $this->jsonLd20Writer->buildFromDomainEvents(
            [$event],
            $recordTime->clone()->utc()->format(DateTimeInterface::ATOM),
            $instanceId,
        );

        if ($affirmTransactionStatement) {
            $json = ShippingTiTsFragments::withDscsaTransactionStatementDocumentField($json);
        }

        $json = ShippingTiTsFragments::withDropShipmentDocumentField($json, $isDropShipment);

        return $json;
    }

    /**
     * @param  Collection<int, Epc>  $epcsById
     * @param  array{
     *     po: ?string,
     *     asn: ?string,
     *     sender_gln: ?string,
     *     receiver_gln: ?string,
     *     parties: array<string, TiTsParty>
     * }  $tiTs
     */
    private function buildXml(
        Collection $epcsById,
        Carbon $eventTime,
        Carbon $recordTime,
        string $timezoneOffset,
        string $shippingUuid,
        string $instanceId,
        array $tiTs,
        bool $affirmTransactionStatement,
        bool $isDropShipment = false,
        ?string $directPurchaseStatement = null,
    ): string {
        $creationDate = $recordTime->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $eventTimeXml = $eventTime->clone()->utc()->format('Y-m-d\TH:i:s.v\Z');
        $recordTimeXml = $creationDate;
        $offsetXml = htmlspecialchars($timezoneOffset, ENT_XML1);
        $parties = $tiTs['parties'];

        $epcList = $epcsById
            ->map(fn (Epc $epc): string => '          <epc>'.htmlspecialchars((string) $epc->epc_uri, ENT_XML1).'</epc>')
            ->implode("\n");

        $readPoint = htmlspecialchars($parties['source_location']['sgln'], ENT_XML1);

        // GS1 US R1.3 / TraceLink: omit bizLocation on shipping ObjectEvents.
        $shippingEvent =
            "      <ObjectEvent>\n".
            "        <eventTime>{$eventTimeXml}</eventTime>\n".
            "        <recordTime>{$recordTimeXml}</recordTime>\n".
            "        <eventTimeZoneOffset>{$offsetXml}</eventTimeZoneOffset>\n".
            "        <baseExtension>\n".
            "          <eventID>urn:uuid:{$shippingUuid}</eventID>\n".
            "        </baseExtension>\n".
            "        <epcList>\n".
            "{$epcList}\n".
            "        </epcList>\n".
            "        <action>OBSERVE</action>\n".
            '        <bizStep>'.self::BIZ_STEP_SHIPPING."</bizStep>\n".
            '        <disposition>'.self::DISPOSITION_IN_TRANSIT."</disposition>\n".
            "        <readPoint>\n".
            "          <id>{$readPoint}</id>\n".
            "        </readPoint>\n".
            ShippingTiTsFragments::bizTransactionListXml(
                po: $tiTs['po'],
                asn: $tiTs['asn'],
                destOwningGln: $parties['dest_owning']['gln'],
                sourceOwningGln: $parties['source_owning']['gln'],
            ).
            ShippingTiTsFragments::sourceDestinationExtensionXml(
                sourceOwningSgln: $parties['source_owning']['sgln'],
                sourceLocationSgln: $parties['source_location']['sgln'],
                destOwningSgln: $parties['dest_owning']['sgln'],
                destLocationSgln: $parties['dest_location']['sgln'],
                directPurchaseStatement: $directPurchaseStatement,
            ).
            '      </ObjectEvent>';

        return
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n".
            "<epcis:EPCISDocument\n".
            "    xmlns:epcis=\"urn:epcglobal:epcis:xsd:1\"\n".
            "    xmlns:sbdh=\"http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader\"\n".
            "    xmlns:cbvmda=\"urn:epcglobal:cbv:mda\"\n".
            "    xmlns:gs1ushc=\"http://epcis.gs1us.org/hc/ns\"\n".
            "    schemaVersion=\"1.2\"\n".
            "    creationDate=\"{$creationDate}\">\n".
            $this->headerXml($tiTs, $instanceId, $creationDate, $affirmTransactionStatement, $isDropShipment).
            "  <EPCISBody>\n".
            "    <EventList>\n".
            "{$shippingEvent}\n".
            "    </EventList>\n".
            "  </EPCISBody>\n".
            "</epcis:EPCISDocument>\n";
    }

    /**
     * @param  array{sender_gln: ?string, receiver_gln: ?string}  $tiTs
     */
    private function headerXml(
        array $tiTs,
        string $instanceId,
        string $creationDate,
        bool $affirmTransactionStatement,
        bool $isDropShipment = false,
    ): string {
        $header = '';

        if (filled($tiTs['sender_gln']) && filled($tiTs['receiver_gln'])) {
            $header .= ShippingTiTsFragments::sbdhXml(
                senderGln: (string) $tiTs['sender_gln'],
                receiverGln: (string) $tiTs['receiver_gln'],
                instanceId: $instanceId,
                creationDate: $creationDate,
            );
        }

        $extras = '';
        $extras .= "    <gs1ushc:guidelineVersion>R1.3</gs1ushc:guidelineVersion>\n";
        if ($affirmTransactionStatement) {
            $extras .= ShippingTiTsFragments::dscsaTransactionStatementXml('    ');
        }
        $extras .= ShippingTiTsFragments::dropShipmentIndicatorXml($isDropShipment, '    ');

        // Lean header has no MasterData: emit HC as EPCISHeader ##other (not inside <extension>).
        if ($extras !== '') {
            $header .= $extras;
        }

        return $header !== ''
            ? "  <EPCISHeader>\n".$header."  </EPCISHeader>\n"
            : '';
    }

    private function resolveOutboundDirectPurchaseStatement(bool $affirmTransactionStatement): ?string
    {
        if (! $affirmTransactionStatement) {
            return null;
        }

        $partnerType = $this->directPurchaseStatements->tenantProfileToPartnerType(tenant());
        if ($partnerType !== PartnerType::Wholesaler) {
            return null;
        }

        $sellerName = filled(tenant()?->name) ? (string) tenant()->name : 'Seller';

        return $this->directPurchaseStatements->statementForSeller($partnerType, $sellerName);
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
