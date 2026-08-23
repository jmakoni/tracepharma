<?php

namespace App\Services\Custody;

use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\SsccLabel;
use App\Services\Receiving\ReceivingGate;
use App\Support\Custody\InTransitInsideOpenParent;
use App\Support\Custody\OutboundShipmentInTransit;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Custody\TenantGlnSet;
use App\Support\Custody\TerminalEpcDisposition;
use App\Support\Custody\UnreceivedPartnerShipment;
use App\Support\Epcis\LastGoodIngestProjection;
use App\Support\Shipping\CorrectiveShipmentDocument;
use App\Support\Tracing\Gs1DualDisplay;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Whether the tenant may operate on an EPC at all.
 *
 * An EPC is in our hands while its latest trackable event leaves it at one of our
 * GLNs ({@see TenantGlnSet}). Commissioning an identity ourselves (SSCC labels we
 * printed have no inbound shipment to be received against) covers the gap before
 * any location event exists, and stops counting once one does — printing a label
 * is not a claim on the pallet forever.
 *
 * No shipping event confers custody, whoever authored it — a handoff says the unit
 * changed hands, never that it is resting in ours. Shipping the unit out ends
 * custody even when our own GLN is on the event: shipments authored before ship-to
 * became the bizLocation recorded ship-from ({@see OutboundShipmentInTransit}). An
 * intracompany transfer counts the same while it is on the road: the organization
 * still owns the goods, but they sit at neither site, so nothing may be shipped,
 * packed or unpacked against them until the destination authors its receiving
 * event. Handing over a container hands over its contents, whose own events never
 * mention the shipment ({@see InTransitInsideOpenParent}). A partner's shipment
 * naming one of our docks is the mirror image: an announcement that stock is
 * coming, not that it came, so custody waits for the receiving event the floor
 * authors ({@see UnreceivedPartnerShipment}).
 *
 * A disposition that retires the identity ends custody wherever the unit lies: a
 * destroyed, recalled or decommissioned unit is standing at our dock and is still
 * nothing we may ship, pack or transfer ({@see TerminalEpcDisposition}).
 *
 * Anything else is somebody else's inventory that merely appears in our event
 * store because a partner told us about it — operating on it would author events
 * we cannot substantiate.
 *
 * Quarantine is a separate, orthogonal gate: a held EPC is in our custody but
 * must not move. Callers get distinct messages for the two failures so floor
 * staff know whether to receive the unit or clear an exception.
 */
final class EpcCustodyGate
{
    public function __construct(
        private readonly ResolveEpcLastKnownGln $lastKnownGln,
        private readonly TenantGlnSet $tenantGlns,
        private readonly ReceivingGate $receivingGate,
        private readonly InTransitInsideOpenParent $inTransitInsideOpenParent,
    ) {}

    public function isInCustody(Epc $epc): bool
    {
        $meta = $this->lastKnownGln->latestEventMeta($epc);

        if (TerminalEpcDisposition::matches($meta)) {
            return false;
        }

        if (OutboundShipmentInTransit::matches($meta)) {
            return false;
        }

        if (UnreceivedPartnerShipment::matches($meta)) {
            return false;
        }

        if ($this->inTransitInsideOpenParent->matches($epc)) {
            return false;
        }

        $gln = $meta['gln'] ?? null;

        if ($this->tenantGlns->contains($gln)) {
            return true;
        }

        // Commissioning vouches for an identity only until the unit has a location.
        return $gln === null && $this->isTenantCommissioned($epc);
    }

    /**
     * Whether we created this EPC's identity: an SSCC label we commissioned, or
     * an EPC carried by a document we authored as SSCC commissioning.
     */
    public function isTenantCommissioned(Epc $epc): bool
    {
        return $this->tenantCommissionedEpcIds([(int) $epc->getKey() => $epc]) !== [];
    }

    /**
     * The subset of the given EPC ids that are in tenant custody, in the order asked.
     *
     * For listing candidates in a picker: every lookup is batched — one query for the
     * latest event of all ids, one for the containers they are packed into, and the
     * commissioning fallback only for ids with no location at all.
     *
     * @param  iterable<Epc|int>  $epcs
     * @return list<int>
     */
    public function epcIdsInCustody(iterable $epcs): array
    {
        $epcIds = [];

        foreach ($epcs as $epc) {
            $epcId = $epc instanceof Epc ? (int) $epc->getKey() : (int) $epc;

            if ($epcId > 0) {
                $epcIds[$epcId] = true;
            }
        }

        $epcIds = array_keys($epcIds);

        if ($epcIds === []) {
            return [];
        }

        $metas = $this->lastKnownGln->latestEventMetaForEpcIds($epcIds);

        $inCustody = [];
        $unlocatedIds = [];

        foreach ($epcIds as $epcId) {
            $meta = $metas[$epcId] ?? null;

            if (TerminalEpcDisposition::matches($meta)
                || OutboundShipmentInTransit::matches($meta)
                || UnreceivedPartnerShipment::matches($meta)) {
                continue;
            }

            $gln = $meta['gln'] ?? null;

            if ($this->tenantGlns->contains($gln)) {
                $inCustody[$epcId] = true;

                continue;
            }

            if ($gln === null) {
                $unlocatedIds[] = $epcId;
            }
        }

        if ($unlocatedIds !== []) {
            $unlocated = Epc::query()->whereIn('id', $unlocatedIds)->get()
                ->keyBy(fn (Epc $epc): int => (int) $epc->getKey())
                ->all();

            foreach ($this->tenantCommissionedEpcIds($unlocated) as $epcId) {
                $inCustody[$epcId] = true;
            }
        }

        // Only ids that would otherwise pass are worth climbing: everything else is
        // already out of custody on its own events.
        $ancestors = $this->inTransitInsideOpenParent->inTransitAncestorByEpcId(array_keys($inCustody));

        foreach (array_keys($ancestors) as $epcId) {
            unset($inCustody[$epcId]);
        }

        return array_values(array_filter($epcIds, fn (int $epcId): bool => isset($inCustody[$epcId])));
    }

    /**
     * @param  Epc|iterable<Epc|int>  $epcs
     * @param  string  $operation  gerund used in the operator message, e.g. "shipping"
     *
     * @throws InvalidArgumentException on the first EPC not in tenant custody
     */
    public function assertInCustody(Epc|iterable $epcs, string $operation): void
    {
        $epcs = $this->normalizeEpcs($epcs);

        if ($epcs === []) {
            return;
        }

        $metas = $this->lastKnownGln->latestEventMetaForEpcIds($epcs);
        $inTransitAncestors = $this->inTransitInsideOpenParent->inTransitAncestorByEpcId($epcs);

        // Only EPCs with no location at all can be vouched for by commissioning, so
        // only those pay for the lookup — batched, whatever the scan count.
        $unlocated = [];
        foreach ($epcs as $epc) {
            $meta = $metas[(int) $epc->getKey()] ?? null;

            if (($meta['gln'] ?? null) === null
                && ! TerminalEpcDisposition::matches($meta)
                && ! OutboundShipmentInTransit::matches($meta)
                && ! UnreceivedPartnerShipment::matches($meta)) {
                $unlocated[(int) $epc->getKey()] = $epc;
            }
        }

        $commissionedEpcIds = $this->tenantCommissionedEpcIds($unlocated);

        foreach ($epcs as $epc) {
            $epcId = (int) $epc->getKey();
            $meta = $metas[$epcId] ?? null;
            $gln = $meta['gln'] ?? null;

            if (TerminalEpcDisposition::matches($meta)) {
                throw new InvalidArgumentException(
                    'Not in tenant custody — the latest event records this unit as '.
                    TerminalEpcDisposition::label($meta['disposition'] ?? null).
                    '. A retired unit cannot be brought back by '.$operation.
                    ' — correct the event on record instead.',
                );
            }

            if (OutboundShipmentInTransit::matches($meta)) {
                throw new InvalidArgumentException(
                    'Not in tenant custody — already shipped and in transit'.
                    ($gln !== null ? ' to '.$gln : '').
                    '. Receive it back at a tenant site, or open a corrective shipment, before '.$operation.'.',
                );
            }

            if (isset($inTransitAncestors[$epcId])) {
                throw new InvalidArgumentException(
                    'Not in tenant custody — packed inside '.
                    $this->epcLabel($inTransitAncestors[$epcId]).
                    ', which has already shipped and is in transit. Receive it back at a tenant site, '.
                    'or open a corrective shipment, before '.$operation.'.',
                );
            }

            if (UnreceivedPartnerShipment::matches($meta)) {
                throw new InvalidArgumentException(
                    'Not in tenant custody — the latest event is a shipment'.
                    ($gln !== null ? ' naming '.$gln : '').
                    ' that has not been received. Receive it at a tenant site before '.$operation.'.',
                );
            }

            if ($this->tenantGlns->contains($gln)) {
                continue;
            }

            if ($gln === null && in_array($epcId, $commissionedEpcIds, true)) {
                continue;
            }

            throw new InvalidArgumentException(
                'Not in tenant custody — last seen at '.($gln ?? 'unknown location').
                '. Receive at a tenant site before '.$operation.'.',
            );
        }
    }

    /**
     * Custody plus quarantine: the full precondition for moving an EPC.
     *
     * @param  Epc|iterable<Epc|int>  $epcs
     *
     * @throws InvalidArgumentException when out of custody or under an open hold
     */
    public function assertOperableFor(Epc|iterable $epcs, string $operation): void
    {
        $epcs = $this->normalizeEpcs($epcs);

        if ($epcs === []) {
            return;
        }

        $this->assertInCustody($epcs, $operation);
        $this->assertNotQuarantined($epcs, $operation);
    }

    /**
     * Quarantine plus terminal disposition only — for authoring that legitimately
     * follows an in-transit handoff (transfer receive EPCIS), where
     * {@see assertOperableFor} would reject the expected in_transit state.
     *
     * @param  Epc|iterable<Epc|int>  $epcs
     *
     * @throws InvalidArgumentException when retired or under an open hold
     */
    public function assertNotRetiredFor(Epc|iterable $epcs, string $operation): void
    {
        $epcs = $this->normalizeEpcs($epcs);

        if ($epcs === []) {
            return;
        }

        $metas = $this->lastKnownGln->latestEventMetaForEpcIds($epcs);

        foreach ($epcs as $epc) {
            $meta = $metas[(int) $epc->getKey()] ?? null;

            if (TerminalEpcDisposition::matches($meta)) {
                throw new InvalidArgumentException(
                    'Not in tenant custody — the latest event records this unit as '.
                    TerminalEpcDisposition::label($meta['disposition'] ?? null).
                    '. A retired unit cannot be brought back by '.$operation.
                    ' — correct the event on record instead.',
                );
            }
        }

        $this->assertNotQuarantined($epcs, $operation);
    }

    /**
     * Whether we have shipped this EPC before — the precondition for a corrective
     * ship (re-sending or amending a shipment for stock that has already left).
     *
     * Evidence is either a confirmed scan line on a completed ship order, or a
     * shipping ObjectEvent on a document we authored as a shipment. With a
     * ship-from site given, only shipments out of that site count — a correction
     * amends what left one dock, not everything the organization ever shipped.
     *
     * A correction is not evidence for the next correction: its own ship order and
     * its own authored document are excluded, so an operator amending a shipment
     * cold (without naming the document) still has to point at something the
     * organization really shipped ({@see CorrectiveShipmentDocument}).
     */
    public function hasPriorTenantShipEvidence(Epc $epc, ?int $shipFromSiteId = null): bool
    {
        $shipped = OutboundShippingScanLine::query()
            ->where('epc_id', $epc->getKey())
            ->where('status', 'confirmed')
            ->whereHas('session', function (Builder $session) use ($shipFromSiteId): void {
                $session->where('status', 'completed')
                    ->where('is_corrective', false);

                if ($shipFromSiteId !== null) {
                    $session->where('site_id', $shipFromSiteId);
                }
            })
            ->exists();

        if ($shipped) {
            return true;
        }

        $evidence = DB::table('event_epcs as ee')
            ->join('epcis_events as ev', 'ev.id', '=', 'ee.event_id')
            ->join('epcis_documents as doc', 'doc.id', '=', 'ev.document_id')
            ->where('ee.epc_id', $epc->getKey())
            ->where('ev.event_type', 'ObjectEvent')
            ->where('ev.biz_step', 'like', '%shipping%')
            ->where('doc.direction', 'outbound');
        LastGoodIngestProjection::constrainDocuments(
            $evidence,
            'doc',
            ['generated', 'parsed', 'validated'],
        );

        return $evidence
            ->where(function ($generation) {
                $generation->whereColumn('ev.ingest_generation', 'doc.ingest_generation')
                    ->orWhereNull('doc.ingest_generation');
            })
            ->when(
                $shipFromSiteId !== null,
                fn ($query) => $query->where('doc.ship_from_site_id', $shipFromSiteId),
            )
            ->where(function ($authored): void {
                $authored->where('doc.authored_kind', EpcisAuthoredKind::Shipping->value)
                    // Authored before authored_kind existed; same markers the backfill used.
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('doc.authored_kind')
                            ->where(function ($notes): void {
                                $notes->where('doc.notes', 'like', '%Generated outbound shipping%')
                                    ->orWhere('doc.notes', 'like', '%ship order session%');
                            });
                    });
            })
            ->where(function ($fresh): void {
                CorrectiveShipmentDocument::applyIsNotCorrection($fresh);
            })
            ->exists();
    }

    /**
     * Corrective shipping deliberately inverts the custody check — the stock is
     * already gone, which is exactly why a correction is being authored, and stock
     * still on hand has no business on one. Prior ship evidence replaces custody as
     * the authorization, and quarantine still applies.
     *
     * When the order names the document it corrects, that document bounds the
     * correction: only units that left on it are in scope, whether they were
     * listed directly or travelled inside an SSCC that was. Without a named
     * document (a correction opened cold from the list page) the check widens to
     * any prior shipment, still restricted to the ship-from site when given.
     *
     * @param  Epc|iterable<Epc|int>  $epcs
     *
     * @throws InvalidArgumentException when an EPC is outside the correction's scope,
     *                                  back in our hands, or under an open hold
     */
    public function assertCorrectiveShipAllowed(
        Epc|iterable $epcs,
        ?int $correctsDocumentId = null,
        ?int $shipFromSiteId = null,
    ): void {
        $epcs = $this->normalizeEpcs($epcs);

        if ($epcs === []) {
            return;
        }

        foreach ($epcs as $epc) {
            $this->assertWithinCorrectionScope($epc, $correctsDocumentId, $shipFromSiteId);
        }

        $this->assertNotBackInCustody($epcs);
        $this->assertNotQuarantined($epcs, 'corrective shipping');
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertWithinCorrectionScope(
        Epc $epc,
        ?int $correctsDocumentId,
        ?int $shipFromSiteId,
    ): void {
        if ($correctsDocumentId !== null) {
            if (! $this->isOnCorrectedShipment($epc, $correctsDocumentId)) {
                throw new InvalidArgumentException(
                    'Not part of the shipment being corrected (EPCIS document #'.$correctsDocumentId.'). '.
                    'A corrective order may only amend units that left on that document.',
                );
            }

            return;
        }

        if (! $this->hasPriorTenantShipEvidence($epc, $shipFromSiteId)) {
            throw new InvalidArgumentException(
                'No prior outbound shipment on record for this EPC'.
                ($shipFromSiteId !== null ? ' from the ship-from site' : '').
                ' — corrective shipping only applies to stock this organization has already shipped.',
            );
        }
    }

    /**
     * Returned stock is not corrected, it is re-shipped: once a unit has been
     * received back it answers for itself again, and amending the shipment it left
     * on would author a handoff for goods sitting on our own dock.
     *
     * Batched over the whole set — one custody pass regardless of the scan count —
     * and reported in the caller's order so the operator sees the first offender.
     *
     * @param  list<Epc>  $epcs
     *
     * @throws InvalidArgumentException
     */
    private function assertNotBackInCustody(array $epcs): void
    {
        $onHandEpcIds = $this->epcIdsInCustody($epcs);

        if ($onHandEpcIds === []) {
            return;
        }

        foreach ($epcs as $epc) {
            $epcId = (int) $epc->getKey();

            if (! in_array($epcId, $onHandEpcIds, true)) {
                continue;
            }

            throw new InvalidArgumentException(
                Gs1DualDisplay::forEpc($epc)['primary'].
                ' is in tenant custody — corrective shipping only applies to stock that has already left. '.
                'Ship it on a normal ship order; stock that came back is received first, then shipped again.',
            );
        }
    }

    /**
     * Whether the EPC left on the document being corrected: named in one of its
     * events, confirmed on the ship order that authored it, or packed under a
     * parent that the document did carry.
     */
    private function isOnCorrectedShipment(Epc $epc, int $documentId): bool
    {
        if ($this->documentCarriesAnyOf([(int) $epc->getKey()], $documentId)) {
            return true;
        }

        $confirmedOnSession = OutboundShippingScanLine::query()
            ->where('epc_id', $epc->getKey())
            ->where('status', 'confirmed')
            ->whereHas('session', fn (Builder $session): Builder => $session
                ->where('epcis_document_id', $documentId))
            ->exists();

        if ($confirmedOnSession) {
            return true;
        }

        return $this->wasPackedUnderShippedParent($epc, $documentId);
    }

    /**
     * Whether the document named any of these EPCs, asked as a membership test over
     * the ids in hand rather than by listing every EPC on the shipment: a pallet
     * shipment carries thousands, and the question is only ever about a handful.
     *
     * @param  list<int>  $epcIds
     */
    private function documentCarriesAnyOf(array $epcIds, int $documentId): bool
    {
        if ($epcIds === []) {
            return false;
        }

        $carries = DB::table('event_epcs as ee')
            ->join('epcis_events as ev', 'ev.id', '=', 'ee.event_id')
            ->join('epcis_documents as doc', 'doc.id', '=', 'ev.document_id')
            ->where('ev.document_id', $documentId)
            ->whereIn('ee.epc_id', $epcIds)
            ->where(function ($generation) {
                $generation->whereColumn('ev.ingest_generation', 'doc.ingest_generation')
                    ->orWhereNull('doc.ingest_generation');
            });
        LastGoodIngestProjection::constrainDocuments(
            $carries,
            'doc',
            ['generated', 'parsed', 'validated'],
        );

        return $carries->exists();
    }

    /**
     * A shipped SSCC carries its contents even though only the SSCC is on the
     * epcList, at every level of the hierarchy: an item inside a case inside the
     * shipped pallet left on that shipment too.
     *
     * What counts is the hierarchy as it stood when the shipment went out. A link
     * closed since then still counts — unpacking at the customer does not undo what
     * we shipped — but a link closed *before* the shipment does not: that item was
     * on our dock while the pallet drove away, and correcting the shipment it never
     * left on would author a handoff we cannot substantiate.
     *
     * Costs one query per level of hierarchy, bounded by the same depth limit the
     * EPCIS validators use, so a malformed cycle cannot spin.
     */
    private function wasPackedUnderShippedParent(Epc $epc, int $documentId): bool
    {
        // No events, nothing shipped: a document that carries no EPC carries no
        // container either.
        $shippedAt = DB::table('epcis_events')
            ->where('document_id', $documentId)
            ->max('event_time');

        if ($shippedAt === null) {
            return false;
        }

        $shippedAt = (string) $shippedAt;
        $frontier = [(int) $epc->getKey()];
        $seen = array_fill_keys($frontier, true);
        $depthLimit = self::hierarchyDepthLimit();

        for ($depth = 0; $depth < $depthLimit && $frontier !== []; $depth++) {
            $parentIds = array_values(array_filter(
                $this->parentsPackedAt($frontier, $shippedAt),
                fn (int $parentId): bool => ! isset($seen[$parentId]),
            ));

            if ($parentIds === []) {
                return false;
            }

            if ($this->documentCarriesAnyOf($parentIds, $documentId)) {
                return true;
            }

            foreach ($parentIds as $parentId) {
                $seen[$parentId] = true;
            }

            $frontier = $parentIds;
        }

        return false;
    }

    /**
     * One level up: the containers these EPCs sat inside at the given instant.
     *
     * @param  list<int>  $childEpcIds
     * @return list<int>
     */
    private function parentsPackedAt(array $childEpcIds, string $packedAt): array
    {
        return AggregationLink::query()
            ->whereIn('child_epc_id', $childEpcIds)
            ->where('valid_from', '<=', $packedAt)
            ->where(function (Builder $stillOpen) use ($packedAt): void {
                $stillOpen->whereNull('valid_to')
                    ->orWhere('valid_to', '>', $packedAt);
            })
            ->distinct()
            ->pluck('parent_epc_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private static function hierarchyDepthLimit(): int
    {
        return max(1, (int) config('tracepharma.epcis.validation.hierarchy_depth_limit', 6));
    }

    /**
     * Which of these EPCs we created the identity for — two queries regardless of
     * how many EPCs are asked about: one over the SSCC labels we commissioned, one
     * over the commissioning documents we authored.
     *
     * @param  array<int, Epc>  $epcsById
     * @return list<int>
     */
    private function tenantCommissionedEpcIds(array $epcsById): array
    {
        if ($epcsById === []) {
            return [];
        }

        /** @var array<string, list<int>> $epcIdsByUri */
        $epcIdsByUri = [];
        /** @var array<string, list<int>> $epcIdsBySscc18 */
        $epcIdsBySscc18 = [];

        foreach ($epcsById as $epcId => $epc) {
            if (filled($epc->epc_uri)) {
                $epcIdsByUri[(string) $epc->epc_uri][] = (int) $epcId;
            }

            if (filled($epc->sscc18)) {
                $epcIdsBySscc18[(string) $epc->sscc18][] = (int) $epcId;
            }
        }

        /** @var array<int, true> $commissioned */
        $commissioned = [];

        if ($epcIdsByUri !== [] || $epcIdsBySscc18 !== []) {
            $labels = SsccLabel::query()
                ->whereNotNull('commissioned_at')
                ->where(function (Builder $match) use ($epcIdsByUri, $epcIdsBySscc18): void {
                    if ($epcIdsByUri !== []) {
                        $match->orWhereIn('sscc_urn', array_keys($epcIdsByUri));
                    }

                    if ($epcIdsBySscc18 !== []) {
                        $match->orWhereIn('sscc_18', array_keys($epcIdsBySscc18));
                    }
                })
                ->get(['sscc_urn', 'sscc_18']);

            foreach ($labels as $label) {
                foreach ($epcIdsByUri[(string) $label->sscc_urn] ?? [] as $epcId) {
                    $commissioned[$epcId] = true;
                }

                foreach ($epcIdsBySscc18[(string) $label->sscc_18] ?? [] as $epcId) {
                    $commissioned[$epcId] = true;
                }
            }
        }

        $remainingIds = array_values(array_diff(array_keys($epcsById), array_keys($commissioned)));

        if ($remainingIds !== []) {
            $authored = DB::table('event_epcs as ee')
                ->join('epcis_events as ev', 'ev.id', '=', 'ee.event_id')
                ->join('epcis_documents as doc', 'doc.id', '=', 'ev.document_id')
                ->whereIn('ee.epc_id', $remainingIds)
                ->whereIn('doc.authored_kind', [
                    EpcisAuthoredKind::SsccCommissioning->value,
                    EpcisAuthoredKind::Commissioning->value,
                ])
                ->distinct()
                ->pluck('ee.epc_id');

            foreach ($authored as $epcId) {
                $commissioned[(int) $epcId] = true;
            }
        }

        return array_map(intval(...), array_keys($commissioned));
    }

    /**
     * How the floor refers to a container we have to name in a refusal: the SSCC
     * digits off the label, not the row id.
     */
    private function epcLabel(int $epcId): string
    {
        $epc = Epc::query()->find($epcId);

        return $epc instanceof Epc
            ? Gs1DualDisplay::forEpc($epc)['primary']
            : 'EPC #'.$epcId;
    }

    /**
     * @param  list<Epc>  $epcs
     *
     * @throws InvalidArgumentException
     */
    private function assertNotQuarantined(array $epcs, string $operation): void
    {
        $epcsById = [];
        foreach ($epcs as $epc) {
            $epcsById[(int) $epc->getKey()] = $epc;
        }

        $heldEpcIds = $this->receivingGate->epcIdsBlockedByOpenHold(array_keys($epcsById));

        if ($heldEpcIds === []) {
            return;
        }

        // Report in the caller's order so the operator sees the first offending scan.
        foreach ($epcsById as $epcId => $epc) {
            if (! in_array($epcId, $heldEpcIds, true)) {
                continue;
            }

            $hold = $this->receivingGate->epcBlockedByOpenHold($epc);
            $caseId = $hold?->exception_id;
            $suffix = $caseId !== null ? " (exception #{$caseId})" : '';

            throw new InvalidArgumentException(
                'Under quarantine'.$suffix.'. Clear or release quarantine before '.$operation.'.',
            );
        }
    }

    /**
     * Resolve the caller's EPCs, failing closed: an id we cannot load is a caller
     * bug, and silently dropping it would turn the assertion into a no-op that
     * waves the scan through.
     *
     * @param  Epc|iterable<Epc|int>  $epcs
     * @return list<Epc>
     *
     * @throws InvalidArgumentException when an input does not resolve to a stored EPC
     */
    private function normalizeEpcs(Epc|iterable $epcs): array
    {
        if ($epcs instanceof Epc) {
            return [$epcs];
        }

        /** @var array<int, Epc|null> $ordered */
        $ordered = [];
        $idsToLoad = [];
        $unresolvable = [];

        foreach ($epcs as $epc) {
            if ($epc instanceof Epc) {
                $epcId = (int) $epc->getKey();

                if ($epcId > 0) {
                    $ordered[$epcId] = $epc;
                } else {
                    $unresolvable[] = 'unsaved EPC';
                }

                continue;
            }

            $epcId = (int) $epc;

            if ($epcId <= 0) {
                $unresolvable[] = var_export($epc, true);

                continue;
            }

            if (! isset($ordered[$epcId])) {
                $ordered[$epcId] = null;
                $idsToLoad[] = $epcId;
            }
        }

        if ($idsToLoad !== []) {
            $loaded = Epc::query()->whereIn('id', $idsToLoad)->get()->keyBy('id');

            foreach ($idsToLoad as $epcId) {
                $ordered[$epcId] = $loaded->get($epcId);

                if ($ordered[$epcId] === null) {
                    $unresolvable[] = (string) $epcId;
                }
            }
        }

        if ($unresolvable !== []) {
            throw new InvalidArgumentException(
                'Unknown EPC '.(count($unresolvable) === 1 ? 'id' : 'ids').': '.
                implode(', ', array_unique($unresolvable)).
                '. Custody cannot be established for EPCs that are not on record.',
            );
        }

        return array_values(array_filter($ordered, fn (?Epc $epc): bool => $epc instanceof Epc));
    }
}
