<?php

namespace App\Support\Epcis\Validation;

use App\Actions\Epcis\ValidateEpcis12Document;
use App\Models\Epcis\EpcisDocument;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog EPCIS business rules layered on top of {@see ValidateEpcis12Document}'s
 * structural checks: timing, source/destination, CBV pairing, master-data cross-references,
 * aggregation integrity, and transmission/partner signals from {@see EpcisValidationCatalog}.
 *
 * Every finding type is capped at config('tracepharma.epcis.validation.max_findings_per_type')
 * to keep huge/degenerate documents from producing unbounded exception rows.
 */
final class EpcisCatalogBusinessRules
{
    private int $maxFindingsPerType = 50;

    /**
     * @var array<string, int>
     */
    private array $findingCounts = [];

    /**
     * Dropped findings per type when {@see add()} hits the per-type cap.
     *
     * @var array<string, int>
     */
    private array $truncatedCounts = [];

    /**
     * EPC ids flagged MISSING_COMMISSIONING in this pass — suppresses COMMISSION_AFTER_SHIP dedupe.
     *
     * @var array<int, true>
     */
    private array $missingCommissioningEpcIds = [];

    /**
     * @return list<EpcisValidationFinding>
     */
    public function validate(EpcisValidationContext $ctx, Collection $events): array
    {
        $this->maxFindingsPerType = (int) config('tracepharma.epcis.validation.max_findings_per_type', 50);
        $this->findingCounts = [];
        $this->truncatedCounts = [];
        $this->missingCommissioningEpcIds = [];

        $findings = [];
        $documentEpcIds = $this->documentEpcIds($ctx->document);

        $this->checkEventTiming($ctx, $events, $findings);
        $this->checkSourceDestination($ctx, $events, $findings);
        $this->checkShippingBizLocationPolarity($ctx, $events, $findings);
        $this->checkCbvCouples($ctx, $events, $findings);
        $this->checkUnknownGln($ctx, $findings);
        $this->checkUnknownGtin($ctx, $documentEpcIds, $findings);
        $this->checkDuplicateSerial($ctx, $events, $findings);
        $this->checkSerialAlreadyCommissioned($ctx, $events, $documentEpcIds, $findings);
        $this->checkAggregationDeleteWithoutPrior($ctx, $events, $findings);
        $this->checkAggregationQuantityMismatch($ctx, $events, $findings);
        $this->checkOrphanSscc($ctx, $events, $findings);
        $this->checkHierarchyDepth($ctx, $documentEpcIds, $findings);
        $this->checkShipmentCommissioning($ctx, $events, $findings);
        $this->checkMissingCommissioning($ctx, $events, $findings);
        $this->checkTimingSequenceAndReturns($ctx, $events, $findings);
        $this->checkMixedPackagingLevels($ctx, $events, $findings);
        $this->checkDropShipmentIndicator($ctx, $events, $findings);
        $this->checkMissingBizTransaction($ctx, $findings);
        $this->checkFileSizeExceeded($ctx, $findings);
        $this->checkDuplicateTransmission($ctx, $findings);
        $this->checkEpcTypeAndPrefix($ctx, $documentEpcIds, $findings);
        $this->checkBrokenAggregation($ctx, $documentEpcIds, $findings);

        $this->flushTruncatedFindings($ctx, $findings);

        return $findings;
    }

    /**
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function add(
        array &$findings,
        EpcisValidationContext $ctx,
        string $type,
        string $description,
        ?int $eventId = null,
        ?int $epcId = null,
        ?string $severity = null,
    ): bool {
        $count = $this->findingCounts[$type] ?? 0;
        if ($count >= $this->maxFindingsPerType) {
            $this->truncatedCounts[$type] = ($this->truncatedCounts[$type] ?? 0) + 1;

            return false;
        }

        $this->findingCounts[$type] = $count + 1;
        $findings[] = new EpcisValidationFinding(
            exceptionType: $type,
            severity: $severity ?? EpcisValidationSeverityMap::severityFor($type, $ctx),
            description: $description,
            eventId: $eventId,
            epcId: $epcId,
        );

        return true;
    }

    /**
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function flushTruncatedFindings(EpcisValidationContext $ctx, array &$findings): void
    {
        foreach ($this->truncatedCounts as $type => $dropped) {
            if ($dropped <= 0) {
                continue;
            }

            $findings[] = new EpcisValidationFinding(
                exceptionType: 'FINDINGS_TRUNCATED',
                severity: EpcisValidationSeverityMap::severityFor('FINDINGS_TRUNCATED', $ctx),
                description: $dropped.' additional '.$type.' finding(s) were omitted because the per-type cap ('.$this->maxFindingsPerType.') was reached.',
                eventId: null,
                epcId: null,
            );
        }
    }

    /**
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function recordMissingCommissioning(
        array &$findings,
        EpcisValidationContext $ctx,
        int $eventId,
        int $epcId,
        string $description,
    ): void {
        $this->missingCommissioningEpcIds[$epcId] = true;
        $this->add($findings, $ctx, 'MISSING_COMMISSIONING', $description, $eventId, $epcId);
    }

    /**
     * FUTURE_EVENT_TIME / STALE_EVENT.
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkEventTiming(EpcisValidationContext $ctx, Collection $events, array &$findings): void
    {
        $skewSeconds = (int) config('tracepharma.epcis.validation.future_event_skew_seconds', 300);
        $staleDays = (int) config('tracepharma.epcis.validation.stale_event_days', 365);
        $futureLimit = now()->addSeconds($skewSeconds);
        $now = now();

        foreach ($events as $event) {
            $eventTime = $event->event_time;
            if ($eventTime === null) {
                continue;
            }
            $eventId = (int) $event->getKey();

            if ($eventTime->greaterThan($futureLimit)) {
                $this->add(
                    $findings,
                    $ctx,
                    'FUTURE_EVENT_TIME',
                    'Event time is in the future beyond the allowed skew: '.$eventTime->toDateTimeString(),
                    $eventId,
                );
            }

            $staleBoundary = $event->record_time !== null
                ? $event->record_time->copy()->subDays($staleDays)
                : $now->copy()->subDays($staleDays);

            if ($eventTime->lessThan($staleBoundary)) {
                $reference = $event->record_time !== null ? 'recordTime' : 'now';
                $this->add(
                    $findings,
                    $ctx,
                    'STALE_EVENT',
                    "Event time is older than {$staleDays} days relative to {$reference}: ".$eventTime->toDateTimeString(),
                    $eventId,
                );
            }
        }
    }

    /**
     * MISSING_SOURCE_DESTINATION / OWNERSHIP_TRANSFER_UNCLEAR for shipping ObjectEvents.
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkSourceDestination(EpcisValidationContext $ctx, Collection $events, array &$findings): void
    {
        $shippingEvents = $events->filter(
            fn ($e) => $e->event_type === 'ObjectEvent' && EpcisCbvAllowlist::isShipping($e->biz_step),
        );

        if ($shippingEvents->isEmpty() || ! Schema::hasTable('event_parties')) {
            return;
        }

        $eventIds = $this->eventIds($shippingEvents);
        $partiesByEvent = collect();
        foreach (array_chunk($eventIds, 1000) as $chunk) {
            $partiesByEvent = $partiesByEvent->merge(
                DB::table('event_parties')
                    ->whereIn('event_id', $chunk)
                    ->get(['event_id', 'party_role', 'extra_json']),
            );
        }
        $partiesByEvent = $partiesByEvent->groupBy('event_id');

        foreach ($shippingEvents as $event) {
            $eventId = (int) $event->getKey();
            $rows = $partiesByEvent->get($eventId, collect());
            $sources = $rows->where('party_role', 'source')->values();
            $destinations = $rows->where('party_role', 'destination')->values();

            $sourceTypes = $sources->map(fn ($r) => $this->sourceDestType($r->extra_json));
            $destTypes = $destinations->map(fn ($r) => $this->sourceDestType($r->extra_json));

            $hasOwningSource = $sourceTypes->contains('owning_party')
                || ($sources->count() === 1 && $sourceTypes->first() === null);
            $hasOwningDest = $destTypes->contains('owning_party')
                || ($destinations->count() === 1 && $destTypes->first() === null);
            $hasLocationSource = $sourceTypes->contains('location');
            $hasLocationDest = $destTypes->contains('location');

            if ($sources->isEmpty() || $destinations->isEmpty()) {
                $this->add(
                    $findings,
                    $ctx,
                    'MISSING_SOURCE_DESTINATION',
                    'Shipping event is missing a source and/or destination party.',
                    $eventId,
                );

                continue;
            }

            if (! $hasOwningSource || ! $hasOwningDest) {
                if ($hasLocationSource || $hasLocationDest) {
                    $this->add(
                        $findings,
                        $ctx,
                        'OWNERSHIP_TRANSFER_UNCLEAR',
                        'Shipping event has location-type source/destination parties but no clear owning_party.',
                        $eventId,
                    );
                } else {
                    $this->add(
                        $findings,
                        $ctx,
                        'MISSING_SOURCE_DESTINATION',
                        'Shipping event is missing an owning_party source and/or destination.',
                        $eventId,
                    );
                }
            }

            if ($ctx->r13Hard && (! $hasLocationSource || ! $hasLocationDest)) {
                $this->add(
                    $findings,
                    $ctx,
                    'MISSING_SOURCE_DESTINATION',
                    'GS1 US R1.3 requires location-type source and destination in addition to owning_party.',
                    $eventId,
                );
            }
        }
    }

    private function sourceDestType(mixed $extraJson): ?string
    {
        $decoded = is_string($extraJson) ? json_decode($extraJson, true) : $extraJson;
        $type = is_array($decoded) ? ($decoded['source_dest_type'] ?? null) : null;

        return filled($type) && $type !== 'unknown' ? (string) $type : null;
    }

    /**
     * Shipping ObjectEvents should omit bizLocation per the GS1 US IG; flag when present.
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkShippingBizLocationPolarity(EpcisValidationContext $ctx, Collection $events, array &$findings): void
    {
        foreach ($events as $event) {
            if ($event->event_type !== 'ObjectEvent' || ! EpcisCbvAllowlist::isShipping($event->biz_step)) {
                continue;
            }

            if (filled($event->biz_location_gln)) {
                $this->add(
                    $findings,
                    $ctx,
                    'INTERNAL_VALIDATION_FAILED',
                    'Shipping ObjectEvent includes bizLocation, which the GS1 US Implementation Guideline omits.',
                    (int) $event->getKey(),
                    null,
                    'warning',
                );
            }
        }
    }

    /**
     * bizStep/disposition CBV pairing: shipping->in_transit, commissioning->active,
     * packing/receiving->in_progress. Mismatches are forced to 'warning' severity.
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkCbvCouples(EpcisValidationContext $ctx, Collection $events, array &$findings): void
    {
        foreach ($events as $event) {
            if ($event->event_type !== 'ObjectEvent') {
                continue;
            }

            $bizStep = $event->biz_step;
            $disposition = $event->disposition;
            if (blank($bizStep) || blank($disposition)) {
                continue;
            }

            $expected = match (true) {
                EpcisCbvAllowlist::isShipping($bizStep) => 'in_transit',
                EpcisCbvAllowlist::isCommissioning($bizStep) => 'active',
                EpcisCbvAllowlist::isPacking($bizStep) => 'in_progress',
                EpcisCbvAllowlist::isReceiving($bizStep) => 'in_progress',
                default => null,
            };

            if ($expected === null || str_contains(strtolower((string) $disposition), $expected)) {
                continue;
            }

            $this->add(
                $findings,
                $ctx,
                'INVALID_DISPOSITION',
                "Disposition '{$disposition}' does not match the expected CBV pairing '{$expected}' for bizStep '{$bizStep}'.",
                (int) $event->getKey(),
                null,
                'warning',
            );
        }
    }

    /**
     * UNKNOWN_GLN — one finding per distinct unmatched GLN recorded for this document.
     *
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkUnknownGln(EpcisValidationContext $ctx, array &$findings): void
    {
        if (! Schema::hasTable('epcis_unmatched_glns')) {
            return;
        }

        $glns = DB::table('epcis_unmatched_glns')
            ->where('document_id', $ctx->document->getKey())
            ->distinct()
            ->pluck('gln');

        foreach ($glns as $gln) {
            $this->add($findings, $ctx, 'UNKNOWN_GLN', 'Unmatched GLN referenced in document: '.$gln);
        }
    }

    /**
     * UNKNOWN_GTIN — SGTIN GTIN-14s on this document absent from the product master.
     *
     * @param  list<int>  $documentEpcIds
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkUnknownGtin(EpcisValidationContext $ctx, array $documentEpcIds, array &$findings): void
    {
        if ($documentEpcIds === [] || ! Schema::hasTable('products')) {
            return;
        }

        $productColumn = Schema::hasColumn('products', 'gtin14')
            ? 'gtin14'
            : (Schema::hasColumn('products', 'gtin') ? 'gtin' : null);

        if ($productColumn === null) {
            return;
        }

        $sgtinGtins = collect();
        foreach (array_chunk($documentEpcIds, 1000) as $chunk) {
            $sgtinGtins = $sgtinGtins->merge(
                DB::table('epcs')
                    ->whereIn('id', $chunk)
                    ->where('epc_type', 'sgtin')
                    ->whereNotNull('gtin14')
                    ->pluck('gtin14'),
            );
        }
        $sgtinGtins = $sgtinGtins->map(fn ($v) => (string) $v)->unique()->values();

        if ($sgtinGtins->isEmpty()) {
            return;
        }

        $known = collect();
        foreach ($sgtinGtins->chunk(1000) as $chunk) {
            $known = $known->merge(
                DB::table('products')->whereIn($productColumn, $chunk->all())->pluck($productColumn),
            );
        }
        $known = $known->map(fn ($v) => (string) $v)->flip();

        foreach ($sgtinGtins as $gtin) {
            if (! $known->has($gtin)) {
                $this->add($findings, $ctx, 'UNKNOWN_GTIN', 'GTIN not found in product master: '.$gtin);
            }
        }
    }

    /**
     * DUPLICATE_SERIAL — same EPC listed more than once in a commissioning ADD epcList.
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkDuplicateSerial(EpcisValidationContext $ctx, Collection $events, array &$findings): void
    {
        if (! Schema::hasTable('event_epcs')) {
            return;
        }

        $commissioningEventIds = $this->eventIds($this->commissioningAddEvents($events));
        if ($commissioningEventIds === []) {
            return;
        }

        foreach (array_chunk($commissioningEventIds, 1000) as $chunk) {
            $dupes = DB::table('event_epcs')
                ->select('event_id', 'epc_id')
                ->selectRaw('COUNT(*) as cnt')
                ->whereIn('event_id', $chunk)
                ->where('role', 'epcList')
                ->groupBy('event_id', 'epc_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($dupes as $row) {
                $this->add(
                    $findings,
                    $ctx,
                    'DUPLICATE_SERIAL',
                    'EPC is listed more than once in a commissioning epcList ('.$row->cnt.'x).',
                    (int) $row->event_id,
                    (int) $row->epc_id,
                );
            }
        }
    }

    /**
     * SERIAL_ALREADY_COMMISSIONED — commissioned SGTIN already commissioned in another document.
     * Skipped entirely for very large documents to bound query cost.
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<int>  $documentEpcIds
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkSerialAlreadyCommissioned(
        EpcisValidationContext $ctx,
        Collection $events,
        array $documentEpcIds,
        array &$findings,
    ): void {
        if (count($documentEpcIds) > 2000 || ! Schema::hasTable('event_epcs')) {
            return;
        }

        $commissioningEvents = $this->commissioningAddEvents($events);
        $commissioningEventIds = $this->eventIds($commissioningEvents);
        if ($commissioningEventIds === []) {
            return;
        }

        $sgtinEpcIds = $this->epcListEpcIds($commissioningEventIds, 'sgtin');
        if ($sgtinEpcIds === []) {
            return;
        }

        $documentId = $ctx->document->getKey();

        // Earliest commissioning time on THIS document per SGTIN — a prior commission only
        // counts when it happened strictly earlier (replayed pedigree / later docs must not
        // poison re-validation of the original commissioning file).
        $currentCommissionAt = [];
        foreach (array_chunk($commissioningEventIds, 1000) as $chunk) {
            $rows = DB::table('event_epcs')
                ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
                ->whereIn('event_epcs.event_id', $chunk)
                ->where('event_epcs.role', 'epcList')
                ->whereIn('event_epcs.epc_id', $sgtinEpcIds)
                ->whereNotNull('epcis_events.event_time')
                ->groupBy('event_epcs.epc_id')
                ->selectRaw('event_epcs.epc_id, MIN(epcis_events.event_time) as commissioned_at')
                ->get();

            foreach ($rows as $row) {
                $currentCommissionAt[(int) $row->epc_id] = (string) $row->commissioned_at;
            }
        }

        if ($currentCommissionAt === []) {
            return;
        }

        $hasIngestGeneration = Schema::hasColumn('epcis_events', 'ingest_generation')
            && Schema::hasColumn('epcis_documents', 'ingest_generation');

        foreach (array_chunk(array_keys($currentCommissionAt), 1000) as $chunk) {
            $query = DB::table('event_epcs')
                ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
                ->join('epcis_documents', 'epcis_documents.id', '=', 'epcis_events.document_id')
                ->whereIn('event_epcs.epc_id', $chunk)
                ->where('event_epcs.role', 'epcList')
                ->where('epcis_events.event_type', 'ObjectEvent')
                ->where('epcis_events.action', 'ADD')
                ->where('epcis_events.document_id', '!=', $documentId)
                ->whereRaw("LOWER(epcis_events.biz_step) LIKE '%commissioning%'")
                ->whereNotNull('epcis_events.event_time');

            if ($hasIngestGeneration) {
                $query->whereColumn('epcis_events.ingest_generation', 'epcis_documents.ingest_generation');
            }

            $priorRows = $query
                ->groupBy('event_epcs.epc_id')
                ->selectRaw('event_epcs.epc_id, MIN(epcis_events.event_time) as prior_at')
                ->get();

            foreach ($priorRows as $row) {
                $epcId = (int) $row->epc_id;
                $currentAt = $currentCommissionAt[$epcId] ?? null;
                if ($currentAt === null || (string) $row->prior_at >= $currentAt) {
                    continue;
                }

                $this->add(
                    $findings,
                    $ctx,
                    'SERIAL_ALREADY_COMMISSIONED',
                    'SGTIN was already commissioned in another EPCIS document.',
                    null,
                    $epcId,
                );
            }
        }
    }

    /**
     * DEAGGREGATION_WITHOUT_PRIOR — AggregationEvent DELETE for a parent with no aggregation history at all.
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkAggregationDeleteWithoutPrior(EpcisValidationContext $ctx, Collection $events, array &$findings): void
    {
        if (! Schema::hasTable('aggregation_links') || ! Schema::hasTable('event_epcs')) {
            return;
        }

        $deleteAggEvents = $events->filter(
            fn ($e) => $e->event_type === 'AggregationEvent' && strtoupper((string) $e->action) === 'DELETE',
        );

        if ($deleteAggEvents->isEmpty()) {
            return;
        }

        $eventIds = $this->eventIds($deleteAggEvents);
        $parentByEvent = [];
        foreach (array_chunk($eventIds, 1000) as $chunk) {
            $rows = DB::table('event_epcs')
                ->whereIn('event_id', $chunk)
                ->where('role', 'parentID')
                ->get(['event_id', 'epc_id']);

            foreach ($rows as $row) {
                $parentByEvent[(int) $row->event_id] = (int) $row->epc_id;
            }
        }

        $parentIds = array_values(array_unique($parentByEvent));
        if ($parentIds === []) {
            return;
        }

        $everHadLinks = [];
        foreach (array_chunk($parentIds, 1000) as $chunk) {
            $rows = DB::table('aggregation_links')->whereIn('parent_epc_id', $chunk)->distinct()->pluck('parent_epc_id');
            foreach ($rows as $id) {
                $everHadLinks[(int) $id] = true;
            }
        }

        foreach ($deleteAggEvents as $event) {
            $eventId = (int) $event->getKey();
            $parentId = $parentByEvent[$eventId] ?? null;
            if ($parentId === null || isset($everHadLinks[$parentId])) {
                continue;
            }

            $this->add(
                $findings,
                $ctx,
                'DEAGGREGATION_WITHOUT_PRIOR',
                'AggregationEvent DELETE references a parent EPC with no aggregation history on file.',
                $eventId,
                $parentId,
            );
        }
    }

    /**
     * AGGREGATION_QUANTITY_MISMATCH — declared quantityList quantity vs. counted childEPC rows.
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkAggregationQuantityMismatch(EpcisValidationContext $ctx, Collection $events, array &$findings): void
    {
        if (! Schema::hasTable('event_epcs')) {
            return;
        }

        $aggEventIds = $this->eventIds($events->where('event_type', 'AggregationEvent'));
        if ($aggEventIds === []) {
            return;
        }

        $rowsByEvent = collect();
        foreach (array_chunk($aggEventIds, 1000) as $chunk) {
            $rowsByEvent = $rowsByEvent->merge(
                DB::table('event_epcs')
                    ->whereIn('event_id', $chunk)
                    ->where('role', 'childEPC')
                    ->get(['event_id', 'epc_id', 'quantity']),
            );
        }

        foreach ($rowsByEvent->groupBy('event_id') as $eventId => $rows) {
            $childCount = $rows->count();
            if ($childCount === 0) {
                continue;
            }

            $declaredRow = $rows->first(fn ($row) => $row->quantity !== null);
            if ($declaredRow === null) {
                continue;
            }

            $declaredQuantity = (float) $declaredRow->quantity;
            if ($declaredQuantity !== (float) $childCount) {
                $this->add(
                    $findings,
                    $ctx,
                    'AGGREGATION_QUANTITY_MISMATCH',
                    "Declared child quantity ({$declaredQuantity}) does not match counted children ({$childCount}).",
                    (int) $eventId,
                );
            }
        }
    }

    /**
     * ORPHAN_SSCC — SSCCs commissioned in this document that never act as an aggregation parent.
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkOrphanSscc(EpcisValidationContext $ctx, Collection $events, array &$findings): void
    {
        if (! Schema::hasTable('event_epcs') || ! Schema::hasTable('aggregation_links')) {
            return;
        }

        $commissioningEventIds = $this->eventIds($this->commissioningAddEvents($events));
        if ($commissioningEventIds === []) {
            return;
        }

        $ssccEpcIds = $this->epcListEpcIds($commissioningEventIds, 'sscc');
        if ($ssccEpcIds === []) {
            return;
        }

        $everParent = [];
        foreach (array_chunk($ssccEpcIds, 1000) as $chunk) {
            $rows = DB::table('aggregation_links')->whereIn('parent_epc_id', $chunk)->distinct()->pluck('parent_epc_id');
            foreach ($rows as $id) {
                $everParent[(int) $id] = true;
            }
        }

        foreach ($ssccEpcIds as $epcId) {
            if (! isset($everParent[$epcId])) {
                $this->add(
                    $findings,
                    $ctx,
                    'ORPHAN_SSCC',
                    'Commissioned SSCC is never used as an aggregation parent.',
                    null,
                    $epcId,
                );
            }
        }
    }

    /**
     * HIERARCHY_DEPTH_EXCEEDED — BFS depth across this document's aggregation subtree.
     *
     * @param  list<int>  $documentEpcIds
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkHierarchyDepth(EpcisValidationContext $ctx, array $documentEpcIds, array &$findings): void
    {
        if ($documentEpcIds === [] || ! Schema::hasTable('aggregation_links')) {
            return;
        }

        $limit = (int) config('tracepharma.epcis.validation.hierarchy_depth_limit', 6);

        $childrenByParent = [];
        $isChild = [];
        foreach (array_chunk($documentEpcIds, 1000) as $chunk) {
            $rows = $this->scopeActiveAggregationLinksForDocument(
                DB::table('aggregation_links')
                    ->whereIn('parent_epc_id', $chunk)
                    ->whereNull('valid_to'),
                $ctx->document,
            )->get(['parent_epc_id', 'child_epc_id']);

            foreach ($rows as $row) {
                $childrenByParent[(int) $row->parent_epc_id][] = (int) $row->child_epc_id;
                $isChild[(int) $row->child_epc_id] = true;
            }
        }

        if ($childrenByParent === []) {
            return;
        }

        $roots = array_filter(array_keys($childrenByParent), fn ($id) => ! isset($isChild[$id]));
        $maxDepth = 0;
        foreach ($roots as $root) {
            $maxDepth = max($maxDepth, $this->bfsDepth($root, $childrenByParent));
        }

        if ($maxDepth > $limit) {
            $this->add(
                $findings,
                $ctx,
                'HIERARCHY_DEPTH_EXCEEDED',
                "Aggregation hierarchy depth ({$maxDepth}) exceeds the configured limit ({$limit}).",
            );
        }
    }

    /**
     * @param  array<int, list<int>>  $childrenByParent
     */
    private function bfsDepth(int $root, array $childrenByParent): int
    {
        $visited = [$root => true];
        $queue = [[$root, 1]];
        $maxDepth = 1;

        while ($queue !== []) {
            [$node, $depth] = array_shift($queue);
            $maxDepth = max($maxDepth, $depth);

            foreach ($childrenByParent[$node] ?? [] as $child) {
                if (isset($visited[$child])) {
                    continue;
                }
                $visited[$child] = true;
                $queue[] = [$child, $depth + 1];
            }
        }

        return $maxDepth;
    }

    /**
     * SERIAL_SHIPPED_NOT_COMMISSIONED — shipped SGTINs and SSCCs with no commissioning event on file anywhere.
     * Emits one aggregated finding instead of many when the violation count would exceed the cap,
     * and bails out to a single aggregated finding entirely for very large documents to bound
     * query cost (mirrors the SERIAL_ALREADY_COMMISSIONED 2000-EPC bail below).
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkShipmentCommissioning(EpcisValidationContext $ctx, Collection $events, array &$findings): void
    {
        if (! Schema::hasTable('event_epcs')) {
            return;
        }

        $shippingEventIds = $this->eventIds($events->filter(
            fn ($e) => $e->event_type === 'ObjectEvent' && EpcisCbvAllowlist::isShipping($e->biz_step),
        ));

        if ($shippingEventIds === []) {
            return;
        }

        $shippedEpcIds = $this->epcListEpcIds($shippingEventIds);
        if ($shippedEpcIds === []) {
            return;
        }

        $largeDocLimit = 5000;
        // Bound query cost on huge docs; do not emit SERIAL_SHIPPED_NOT_COMMISSIONED
        // without evidence (that would false-flag every large clean shipment).
        if ((int) $ctx->document->epc_count > $largeDocLimit || count($shippedEpcIds) > $largeDocLimit) {
            return;
        }

        $commissionedAnywhere = [];
        foreach (array_chunk($shippedEpcIds, 1000) as $chunk) {
            $rows = DB::table('event_epcs')
                ->join('epcis_events', 'epcis_events.id', '=', 'event_epcs.event_id')
                ->whereIn('event_epcs.epc_id', $chunk)
                ->where('event_epcs.role', 'epcList')
                ->where('epcis_events.event_type', 'ObjectEvent')
                ->where('epcis_events.action', 'ADD')
                ->whereRaw("LOWER(epcis_events.biz_step) LIKE '%commissioning%'")
                ->distinct()
                ->pluck('event_epcs.epc_id');

            foreach ($rows as $id) {
                $commissionedAnywhere[(int) $id] = true;
            }
        }

        $violating = array_values(array_diff($shippedEpcIds, array_keys($commissionedAnywhere)));
        if ($violating === []) {
            return;
        }

        if (count($violating) > $this->maxFindingsPerType) {
            $this->add(
                $findings,
                $ctx,
                'SERIAL_SHIPPED_NOT_COMMISSIONED',
                count($violating).' shipped serials/EPCs have no commissioning event on file (aggregated due to volume).',
            );

            return;
        }

        foreach ($violating as $epcId) {
            $this->add(
                $findings,
                $ctx,
                'SERIAL_SHIPPED_NOT_COMMISSIONED',
                'Shipped serial/EPC has no commissioning event on file.',
                null,
                $epcId,
            );
        }
    }

    /**
     * MISSING_COMMISSIONING — TraceLink-style "operation on Reserved": packing, shipping,
     * or receiving in this document when the EPC has no usable commissioning ADD in this document
     * (missing entirely, or every in-doc commissioning ADD lacks readPoint).
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkMissingCommissioning(EpcisValidationContext $ctx, Collection $events, array &$findings): void
    {
        if (! Schema::hasTable('event_epcs')) {
            return;
        }

        // Inbound partner files: reserved serials packed/shipped/received in the same
        // document. Tenant-authored outbound (SSCC commission, then a later packing
        // ADD) is a different document and is not this TraceLink Reserved check.
        if ($ctx->direction !== 'inbound') {
            return;
        }

        $eventsById = $events->keyBy(fn ($e) => (int) $e->getKey());

        $shippingEventIds = $this->eventIds($events->filter(
            fn ($e) => $e->event_type === 'ObjectEvent' && EpcisCbvAllowlist::isShipping($e->biz_step),
        ));
        $receivingEventIds = $this->eventIds($events->filter(
            fn ($e) => $e->event_type === 'ObjectEvent' && EpcisCbvAllowlist::isReceiving($e->biz_step),
        ));
        $packingEventIds = $this->eventIds($events->filter(
            fn ($e) => $e->event_type === 'AggregationEvent'
                && strtoupper((string) $e->action) === 'ADD'
                && EpcisCbvAllowlist::isPacking($e->biz_step),
        ));

        $downstreamRows = $this->epcListRows($shippingEventIds)
            ->merge($this->epcListRows($receivingEventIds))
            ->merge($this->packingEpcRows($packingEventIds));
        if ($downstreamRows->isEmpty()) {
            return;
        }

        $commissionRows = $this->epcListRows($this->eventIds($this->commissioningAddEvents($events)));

        /** @var array<int, list<mixed>> $commissionEventsByEpc */
        $commissionEventsByEpc = [];
        foreach ($commissionRows as $row) {
            $event = $eventsById->get((int) $row->event_id);
            if ($event === null) {
                continue;
            }
            $commissionEventsByEpc[(int) $row->epc_id][] = $event;
        }

        $earliestDownstreamByEpc = [];
        foreach ($downstreamRows as $row) {
            $event = $eventsById->get((int) $row->event_id);
            if ($event === null || $event->event_time === null) {
                continue;
            }
            $epcId = (int) $row->epc_id;
            $time = $event->event_time;
            if (! isset($earliestDownstreamByEpc[$epcId]) || $time->lessThan($earliestDownstreamByEpc[$epcId]['time'])) {
                $earliestDownstreamByEpc[$epcId] = [
                    'time' => $time,
                    'event_id' => (int) $event->getKey(),
                ];
            }
        }

        foreach ($earliestDownstreamByEpc as $epcId => $downstream) {
            $commissions = $commissionEventsByEpc[$epcId] ?? [];

            if ($commissions === []) {
                $this->recordMissingCommissioning(
                    $findings,
                    $ctx,
                    $downstream['event_id'],
                    $epcId,
                    'EPC was packed, shipped, or received in this document while still reserved/uncommissioned (no commissioning ADD in this document).',
                );

                continue;
            }

            $allMissingReadPoint = true;
            foreach ($commissions as $event) {
                if (filled($event->read_point_gln)) {
                    $allMissingReadPoint = false;
                    break;
                }
            }

            if ($allMissingReadPoint) {
                $this->recordMissingCommissioning(
                    $findings,
                    $ctx,
                    $downstream['event_id'],
                    $epcId,
                    'EPC was packed, shipped, or received in this document but commissioning in this document lacks readPoint (unusable commissioning).',
                );
            }
        }
    }

    /**
     * COMMISSION_AFTER_SHIP, DECOMMISSION_AFTER_SHIP, EVENTS_OUT_OF_ORDER, RETURNS_NOT_LINKED —
     * all derived from a single pass over per-EPC event times within this document.
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkTimingSequenceAndReturns(EpcisValidationContext $ctx, Collection $events, array &$findings): void
    {
        if (! Schema::hasTable('event_epcs')) {
            return;
        }

        $eventsById = $events->keyBy(fn ($e) => (int) $e->getKey());

        $commissioningEventIds = $this->eventIds($this->commissioningAddEvents($events));
        $shippingEvents = $events->filter(
            fn ($e) => $e->event_type === 'ObjectEvent' && EpcisCbvAllowlist::isShipping($e->biz_step),
        );
        $shippingEventIds = $this->eventIds($shippingEvents);
        $packingEvents = $events->filter(
            fn ($e) => $e->event_type === 'AggregationEvent'
                && strtoupper((string) $e->action) === 'ADD'
                && EpcisCbvAllowlist::isPacking($e->biz_step),
        );
        $packingEventIds = $this->eventIds($packingEvents);
        $decommissionEventIds = $this->eventIds($events->filter(
            fn ($e) => $e->event_type === 'ObjectEvent' && $this->isDecommissionDisposition($e->disposition),
        ));
        $returnEvents = $events->filter(
            fn ($e) => $e->event_type === 'ObjectEvent'
                && EpcisCbvAllowlist::isReceiving($e->biz_step)
                && str_contains(strtolower((string) $e->disposition), 'returned'),
        );

        $commissionRows = $this->epcListRows($commissioningEventIds);
        $shipRows = $this->epcListRows($shippingEventIds);
        $packRows = $this->packingEpcRows($packingEventIds);
        $decommissionRows = $this->epcListRows($decommissionEventIds);

        $earliestByEpc = function (Collection $rows) use ($eventsById): array {
            $result = [];
            foreach ($rows as $row) {
                $event = $eventsById->get((int) $row->event_id);
                if ($event === null || $event->event_time === null) {
                    continue;
                }
                $epcId = (int) $row->epc_id;
                $time = $event->event_time;
                if (! isset($result[$epcId]) || $time->lessThan($result[$epcId]['time'])) {
                    $result[$epcId] = ['time' => $time, 'event_id' => (int) $event->getKey()];
                }
            }

            return $result;
        };

        $commissionTimes = $earliestByEpc($commissionRows);
        $shipTimes = $earliestByEpc($shipRows);
        $decommissionTimes = $earliestByEpc($decommissionRows);

        $downstreamBeforeCommissionRows = $shipRows->merge($packRows);
        $downstreamBeforeCommissionTimes = $earliestByEpc($downstreamBeforeCommissionRows);

        foreach ($commissionTimes as $epcId => $commission) {
            if (isset($this->missingCommissioningEpcIds[$epcId])) {
                continue;
            }

            $downstream = $downstreamBeforeCommissionTimes[$epcId] ?? null;
            if ($downstream !== null && $commission['time']->greaterThan($downstream['time'])) {
                $this->add(
                    $findings,
                    $ctx,
                    'COMMISSION_AFTER_SHIP',
                    'Commissioning event time is after this EPC was shipped or packed in this document.',
                    $downstream['event_id'],
                    $epcId,
                );
            }
        }

        foreach ($decommissionTimes as $epcId => $decommission) {
            $ship = $shipTimes[$epcId] ?? null;
            if ($ship !== null && $decommission['time']->greaterThan($ship['time'])) {
                $this->add(
                    $findings,
                    $ctx,
                    'DECOMMISSION_AFTER_SHIP',
                    'EPC was decommissioned/destroyed after being shipped in this document.',
                    $decommission['event_id'],
                    $epcId,
                );
            }
            if ($ship !== null && $ship['time']->greaterThanOrEqualTo($decommission['time'])) {
                $this->add(
                    $findings,
                    $ctx,
                    'DECOMMISSIONED_SERIAL_SHIPPED',
                    'EPC was shipped at or after a decommission/destroy disposition in this document.',
                    $ship['event_id'],
                    $epcId,
                );
            }
        }

        $timesByEpc = [];
        foreach ($commissionRows->merge($shipRows)->merge($packRows)->merge($decommissionRows) as $row) {
            $event = $eventsById->get((int) $row->event_id);
            if ($event === null || $event->event_time === null) {
                continue;
            }
            $epcId = (int) $row->epc_id;
            $key = $event->event_time->toDateTimeString();
            $timesByEpc[$epcId][$key][] = (int) $event->getKey();
        }

        foreach ($timesByEpc as $epcId => $times) {
            foreach ($times as $eventIdsAtTime) {
                $uniqueEventIds = array_unique($eventIdsAtTime);
                if (count($uniqueEventIds) > 1) {
                    $this->add(
                        $findings,
                        $ctx,
                        'EVENTS_OUT_OF_ORDER',
                        'Multiple distinct events for the same EPC report identical event times, so their relative order cannot be determined.',
                        (int) reset($uniqueEventIds),
                        (int) $epcId,
                        'warning',
                    );
                }
            }
        }

        if ($returnEvents->isNotEmpty()) {
            $returnRows = $this->epcListRows($this->eventIds($returnEvents));
            foreach ($returnRows as $row) {
                $epcId = (int) $row->epc_id;
                if (! isset($shipTimes[$epcId])) {
                    $this->add(
                        $findings,
                        $ctx,
                        'RETURNS_NOT_LINKED',
                        'Returned receiving event has no corresponding prior shipment on file in this document.',
                        (int) $row->event_id,
                        $epcId,
                        'warning',
                    );
                }
            }
        }
    }

    private function isDecommissionDisposition(?string $disposition): bool
    {
        $normalized = strtolower((string) $disposition);

        return str_contains($normalized, 'decommission') || str_contains($normalized, 'destroy');
    }

    /**
     * MIXED_PACKAGING_LEVELS — an ObjectEvent epcList containing both SGTIN and SSCC EPCs.
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkMixedPackagingLevels(EpcisValidationContext $ctx, Collection $events, array &$findings): void
    {
        if (! Schema::hasTable('event_epcs')) {
            return;
        }

        $objectEventIds = $this->eventIds($events->where('event_type', 'ObjectEvent'));
        if ($objectEventIds === []) {
            return;
        }

        $rowsByEvent = collect();
        foreach (array_chunk($objectEventIds, 1000) as $chunk) {
            $rowsByEvent = $rowsByEvent->merge(
                DB::table('event_epcs')
                    ->join('epcs', 'epcs.id', '=', 'event_epcs.epc_id')
                    ->whereIn('event_epcs.event_id', $chunk)
                    ->where('event_epcs.role', 'epcList')
                    ->get(['event_epcs.event_id', 'epcs.epc_type']),
            );
        }

        foreach ($rowsByEvent->groupBy('event_id') as $eventId => $rows) {
            $types = $rows->pluck('epc_type')->unique();
            if ($types->contains('sgtin') && $types->contains('sscc')) {
                $this->add(
                    $findings,
                    $ctx,
                    'MIXED_PACKAGING_LEVELS',
                    'ObjectEvent epcList mixes SGTIN and SSCC packaging levels.',
                    (int) $eventId,
                );
            }
        }
    }

    /**
     * DROP_SHIPMENT_INDICATOR_MISSING — GS1 US R1.3 shipping documents must declare drop-shipment status.
     *
     * @param  Collection<int, mixed>  $events
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkDropShipmentIndicator(EpcisValidationContext $ctx, Collection $events, array &$findings): void
    {
        $declared = (string) ($ctx->declaredGuidelineVersion ?? '');
        $isR13 = $ctx->r13Hard || str_contains($declared, '1.3');
        if (! $isR13) {
            return;
        }

        $shippingEvents = $events->filter(
            fn ($e) => $e->event_type === 'ObjectEvent' && EpcisCbvAllowlist::isShipping($e->biz_step),
        );
        if ($shippingEvents->isEmpty()) {
            return;
        }

        $path = $ctx->payloadPath;
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return;
        }

        $contents = @file_get_contents($path);
        if ($contents !== false && stripos($contents, 'dropShipment') !== false) {
            return;
        }

        $this->add(
            $findings,
            $ctx,
            'DROP_SHIPMENT_INDICATOR_MISSING',
            'GS1 US R1.3 shipping document has no dropShipment indicator.',
        );
    }

    /**
     * MISSING_BIZ_TRANSACTION — inbound document with neither ASN nor customer PO reference.
     *
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkMissingBizTransaction(EpcisValidationContext $ctx, array &$findings): void
    {
        $document = $ctx->document;
        if ($ctx->direction !== 'inbound' || filled($document->asn_number) || filled($document->customer_po)) {
            return;
        }

        $this->add(
            $findings,
            $ctx,
            'MISSING_BIZ_TRANSACTION',
            'Inbound EPCIS document has no ASN or customer PO business transaction reference.',
        );
    }

    /**
     * FILE_SIZE_EXCEEDED — payload exceeds the configured maximum upload size.
     *
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkFileSizeExceeded(EpcisValidationContext $ctx, array &$findings): void
    {
        $path = $ctx->payloadPath;
        if ($path === null || ! is_file($path)) {
            return;
        }

        $maxBytes = ((int) config('tracepharma.epcis.max_upload_kb', 81920)) * 1024;
        $size = filesize($path);
        if ($size !== false && $maxBytes > 0 && $size > $maxBytes) {
            $this->add(
                $findings,
                $ctx,
                'FILE_SIZE_EXCEEDED',
                'Payload file size ('.number_format($size).' bytes) exceeds the configured maximum ('.number_format($maxBytes).' bytes).',
            );
        }
    }

    /**
     * DUPLICATE_TRANSMISSION — another document with an identical file checksum already exists.
     *
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkDuplicateTransmission(EpcisValidationContext $ctx, array &$findings): void
    {
        $document = $ctx->document;
        if (blank($document->file_sha256)) {
            return;
        }

        $exists = EpcisDocument::query()
            ->where('file_sha256', $document->file_sha256)
            ->where('direction', $document->direction)
            ->whereKeyNot($document->getKey())
            ->exists();

        if ($exists) {
            $this->add(
                $findings,
                $ctx,
                'DUPLICATE_TRANSMISSION',
                'Another EPCIS document with an identical file checksum already exists.',
            );
        }
    }

    /**
     * UNSUPPORTED_EPC_TYPE / INVALID_COMPANY_PREFIX — walk document EPCs once for both checks.
     *
     * @param  list<int>  $documentEpcIds
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkEpcTypeAndPrefix(EpcisValidationContext $ctx, array $documentEpcIds, array &$findings): void
    {
        if ($documentEpcIds === []) {
            return;
        }

        foreach (array_chunk($documentEpcIds, 1000) as $chunk) {
            $rows = DB::table('epcs')->whereIn('id', $chunk)->get(['id', 'epc_type', 'company_prefix']);

            foreach ($rows as $row) {
                $epcId = (int) $row->id;
                $type = (string) $row->epc_type;

                if (! in_array($type, ['sgtin', 'sscc'], true)) {
                    $this->add(
                        $findings,
                        $ctx,
                        'UNSUPPORTED_EPC_TYPE',
                        'EPC type is not supported for DSCSA tracing: '.($type !== '' ? $type : '(blank)'),
                        null,
                        $epcId,
                    );

                    continue;
                }

                $prefixLength = strlen((string) $row->company_prefix);
                if ($prefixLength > 0 && ($prefixLength < 6 || $prefixLength > 12)) {
                    $this->add(
                        $findings,
                        $ctx,
                        'INVALID_COMPANY_PREFIX',
                        "GS1 company prefix length ({$prefixLength}) is outside the valid 6-12 digit range.",
                        null,
                        $epcId,
                    );
                }
            }
        }
    }

    /**
     * BROKEN_AGGREGATION — child EPC in this document whose active parent is outside the
     * document and is not an SSCC (i.e. not a plausible logistics unit established elsewhere).
     *
     * @param  list<int>  $documentEpcIds
     * @param  list<EpcisValidationFinding>  $findings
     */
    private function checkBrokenAggregation(EpcisValidationContext $ctx, array $documentEpcIds, array &$findings): void
    {
        if ($documentEpcIds === [] || ! Schema::hasTable('aggregation_links')) {
            return;
        }

        $documentEpcIdSet = array_fill_keys($documentEpcIds, true);
        $parentIds = [];
        $linksByChild = [];

        foreach (array_chunk($documentEpcIds, 1000) as $chunk) {
            $rows = $this->scopeActiveAggregationLinksForDocument(
                DB::table('aggregation_links')
                    ->whereIn('child_epc_id', $chunk)
                    ->whereNull('valid_to'),
                $ctx->document,
            )->get(['child_epc_id', 'parent_epc_id']);

            foreach ($rows as $row) {
                $parentId = (int) $row->parent_epc_id;
                if (isset($documentEpcIdSet[$parentId])) {
                    continue;
                }
                $parentIds[$parentId] = true;
                $linksByChild[(int) $row->child_epc_id] = $parentId;
            }
        }

        if ($parentIds === []) {
            return;
        }

        $nonSsccParents = [];
        foreach (array_chunk(array_keys($parentIds), 1000) as $chunk) {
            $rows = DB::table('epcs')->whereIn('id', $chunk)->where('epc_type', '!=', 'sscc')->pluck('id');
            foreach ($rows as $id) {
                $nonSsccParents[(int) $id] = true;
            }
        }

        foreach ($linksByChild as $childId => $parentId) {
            if (isset($nonSsccParents[$parentId])) {
                $this->add(
                    $findings,
                    $ctx,
                    'BROKEN_AGGREGATION',
                    'Child EPC aggregation parent is outside this document and is not an SSCC.',
                    null,
                    $childId,
                );
            }
        }
    }

    /**
     * @param  Collection<int, mixed>  $events
     * @return Collection<int, mixed>
     */
    private function commissioningAddEvents(Collection $events): Collection
    {
        return $events->filter(
            fn ($e) => $e->event_type === 'ObjectEvent'
                && strtoupper((string) $e->action) === 'ADD'
                && EpcisCbvAllowlist::isCommissioning($e->biz_step),
        );
    }

    /**
     * @param  Collection<int, mixed>  $events
     * @return list<int>
     */
    private function eventIds(Collection $events): array
    {
        return $events->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Packing AggregationEvent participants (childEPC + parentID).
     *
     * TraceLink flags any packing activity before commission; SGTINs may appear as
     * either child or parent on AggregationEvent (see demo2 inbound #7309 parentID case).
     *
     * @param  list<int>  $eventIds
     * @return Collection<int, mixed>
     */
    private function packingEpcRows(array $eventIds): Collection
    {
        $result = collect();
        if ($eventIds === [] || ! Schema::hasTable('event_epcs')) {
            return $result;
        }

        foreach (array_chunk($eventIds, 1000) as $chunk) {
            $result = $result->merge(
                DB::table('event_epcs')
                    ->whereIn('event_id', $chunk)
                    ->whereIn('role', ['childEPC', 'parentID'])
                    ->get(['event_epcs.event_id', 'event_epcs.epc_id']),
            );
        }

        return $result;
    }

    /**
     * epcList role event_epcs rows for the given event ids, optionally restricted to an epc_type.
     *
     * @param  list<int>  $eventIds
     * @return Collection<int, mixed>
     */
    private function epcListRows(array $eventIds, ?string $epcType = null): Collection
    {
        $result = collect();
        if ($eventIds === [] || ! Schema::hasTable('event_epcs')) {
            return $result;
        }

        foreach (array_chunk($eventIds, 1000) as $chunk) {
            $query = DB::table('event_epcs')->whereIn('event_id', $chunk)->where('role', 'epcList');

            if ($epcType !== null) {
                $query->join('epcs', 'epcs.id', '=', 'event_epcs.epc_id')->where('epcs.epc_type', $epcType);
            }

            $result = $result->merge($query->get(['event_epcs.event_id', 'event_epcs.epc_id']));
        }

        return $result;
    }

    /**
     * @param  list<int>  $eventIds
     * @return list<int>
     */
    private function epcListEpcIds(array $eventIds, ?string $epcType = null): array
    {
        $ids = [];
        foreach ($this->epcListRows($eventIds, $epcType) as $row) {
            $ids[(int) $row->epc_id] = true;
        }

        return array_keys($ids);
    }

    /**
     * @return list<int>
     */
    private function documentEpcIds(EpcisDocument $document): array
    {
        return $document->epcsQuery()->pluck('epcs.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Scope open aggregation links to this document's current ingest generation.
     *
     * @param  Builder  $query
     * @return Builder
     */
    private function scopeActiveAggregationLinksForDocument($query, EpcisDocument $document)
    {
        if (! Schema::hasColumn('aggregation_links', 'established_by_event_id')) {
            return $query;
        }

        return $query->whereIn('established_by_event_id', function ($sub) use ($document): void {
            $sub->select('id')
                ->from('epcis_events')
                ->where('document_id', $document->getKey());

            if (
                Schema::hasColumn('epcis_events', 'ingest_generation')
                && Schema::hasColumn('epcis_documents', 'ingest_generation')
                && filled($document->getAttribute('ingest_generation'))
            ) {
                $sub->where('ingest_generation', (int) $document->getAttribute('ingest_generation'));
            }
        });
    }
}
