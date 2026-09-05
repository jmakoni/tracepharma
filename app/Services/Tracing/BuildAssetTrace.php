<?php

namespace App\Services\Tracing;

use App\Actions\Epcis\ResolveEpcFromScan;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcIlmd;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\EventLocation;
use App\Models\Product;
use App\Models\Quarantine\QuarantineHold;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Custody\ResolveEpcCustodyAsOf;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Epcis\ArchivedEpcEvents;
use App\Support\Epcis\LastGoodIngestProjection;
use App\Support\Gs1\Ndc;
use App\Support\Tracing\AssetTrackingUrl;
use App\Support\Tracing\BizTransactionLabel;
use App\Support\Tracing\Gs1DualDisplay;
use App\Support\Tracing\LocationDisplayResolver;
use App\Support\Tracing\VerifyUrlParams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Build the full Asset Tracking payload for a single scanned identifier
 * (SGTIN or SSCC): identity, product/lot context, timeline, map points,
 * parties, and biz transactions.
 *
 * Table-backed sections of the Filament page (events, children) should use
 * {@see self::eventsForTrackingTable()} / {@see self::childrenQuery()} rather than
 * relying on the small preview lists returned here. Tracking table helpers are
 * hard-capped ({@see self::trackingTableLimit()}) and may report truncated=true.
 */
final class BuildAssetTrace
{
    public function __construct(
        private ResolveEpcFromScan $resolveEpcFromScan,
        private LocationDisplayResolver $locations,
        private ResolveEpcCustodyAsOf $custodyAsOf,
        private ArchivedEpcEvents $archivedEpcEvents,
    ) {}

    /**
     * @return array{
     *   found: bool,
     *   scan: string,
     *   epc: ?int,
     *   identity: array,
     *   status: string,
     *   status_tone: string,
     *   primary_identifier: ?string,
     *   gs1_barcode: ?string,
     *   urn: ?string,
     *   disposition: ?string,
     *   disposition_uri: ?string,
     *   disposition_at: ?string,
     *   container_type: ?string,
     *   serial_number: ?string,
     *   last_seen_at: ?string,
     *   children_count: int,
     *   parent: ?array{epc_id: int, primary_identifier: ?string, gs1_barcode: ?string, scan: ?string, url: ?string},
     *   product: array<string, mixed>,
     *   lot: array<string, mixed>,
     *   parties: array<string, mixed>,
     *   timeline: list<array>,
     *   map_points: list<array{lat: float, lng: float, label: string, at: ?string, seq: int}>,
     *   events: list<array>,
     *   children: list<array>,
     *   transactions: list<array{name: string, urn: string, value: string}>,
     *   verify_url_params: ?array{barcode: string, gtin: ?string, serial: ?string},
     *   transformation_links: list<array{
     *     role: string,
     *     counterpart_role: string,
     *     counterpart_epc_id: int,
     *     counterpart_urn: ?string,
     *     counterpart_primary: ?string,
     *     event_id: int,
     *     transformation_id: ?string,
     *     event_time: ?string,
     *     url: ?string,
     *   }>,
     *   as_of: ?string,
     * }
     */
    public function handle(string $scan, ?Carbon $asOf = null): array
    {
        $resolved = $this->resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'];
        $identity = $resolved['identity'];

        if ($epc === null) {
            return $this->notFoundResult($scan, $identity);
        }

        $epc->loadMissing(['product.tradingPartner', 'ilmd']);

        $eventRelations = ['locations', 'bizTransactions', 'document'];
        $asOfUtc = $asOf?->copy()->utc();

        // Status HUD fields use direct EPC events only — not closed-ancestor implied events.
        $latestDirectEvent = $this->eventsQuery($epc, $asOfUtc)
            ->with($eventRelations)
            ->reorder()
            ->orderByDesc('event_time')
            ->orderByDesc('id')
            ->first();

        $events = $this->eventsQuery($epc, $asOfUtc)
            ->with($eventRelations)
            ->reorder()
            ->orderByDesc('event_time')
            ->orderByDesc('id')
            ->limit($this->initialDirectEventsLimit())
            ->get()
            ->reverse()
            ->values();

        $archived = $this->archivedEpcEvents->forEpc((int) $epc->getKey(), $asOfUtc);
        $events = $events
            ->concat($archived)
            ->unique(fn (EpcisEvent $event): int => (int) $event->getKey())
            ->sortBy([
                fn (EpcisEvent $event): int => $event->event_time?->getTimestamp() ?? 0,
                fn (EpcisEvent $event): int => (int) $event->getKey(),
            ])
            ->values();

        if ($archived->isNotEmpty()) {
            $latestDirectEvent = collect([$latestDirectEvent])
                ->filter()
                ->concat($archived)
                ->unique(fn (EpcisEvent $event): int => (int) $event->getKey())
                ->sortByDesc(fn (EpcisEvent $event): array => [
                    $event->event_time?->getTimestamp() ?? 0,
                    (int) $event->getKey(),
                ])
                ->first();
        }

        $this->preloadEventLocations($events);
        $this->preloadManufacturerLocation($epc, $latestDirectEvent);

        [$displayEvents, $inferredFromByEventId] = $this->mergeDisplayEvents($epc, $events);

        $displayEvents = $this->capTimelineEvents($displayEvents);
        $quarantined = $asOfUtc === null && $this->hasOpenQuarantineHold($epc);

        $custodySnapshot = null;
        if ($asOfUtc !== null) {
            $custodySnapshot = $this->custodyAsOf->handle($epc, $asOfUtc);
            $inCustody = in_array($custodySnapshot['status'], ['Commissioned', 'In custody'], true);
            $inTransitViaParent = $custodySnapshot['status'] === 'In transit';
            $status = $custodySnapshot['status'];
            $statusTone = $custodySnapshot['status_tone'];
            $dispositionLabel = $custodySnapshot['disposition'];
            $dispositionUri = $custodySnapshot['disposition_uri'];
            $dispositionAt = $custodySnapshot['event_time'];
            $lastSeen = $custodySnapshot['event_time'];
        } else {
            $inCustody = app(EpcCustodyGate::class)->isInCustody($epc);
            $inTransitViaParent = $this->isInTransitInsideOpenParent($epc);
            $status = $quarantined
                ? 'Quarantined'
                : ($inTransitViaParent
                    ? 'In transit'
                    : ($inCustody ? 'In custody' : 'Not in custody'));
            $statusTone = $quarantined
                ? 'warn'
                : ($inTransitViaParent || ! $inCustody ? 'warn' : 'ok');
            [$dispositionLabel, $dispositionUri, $dispositionAt, $lastSeen] = $this->latestEventDisplay($latestDirectEvent);
            // Packed children follow the open parent's location (SSCC-only transfer/ship events).
            $effectiveGln = app(ResolveEpcLastKnownGln::class)->forEpc($epc);
            if ($effectiveGln !== null) {
                $fromEffective = $this->locations->resolve($effectiveGln, null)['label'] ?? null;
                if (filled($fromEffective)) {
                    $lastSeen = $fromEffective;
                }
            }
        }

        $display = Gs1DualDisplay::forEpc($epc);
        // H7: as-of children use aggregation_links temporal window (valid_from/valid_to),
        // not live open() only — packing after T must not inflate as-of counts.
        $childrenCount = $this->childrenQuery($epc, $asOfUtc)->count();

        return [
            'found' => true,
            'scan' => $scan,
            'epc' => (int) $epc->getKey(),
            'identity' => $identity,
            'status' => $status,
            'status_tone' => $statusTone,
            'primary_identifier' => $display['primary'],
            'gs1_barcode' => $display['gs1_barcode'],
            'urn' => $display['urn'],
            'disposition' => $dispositionLabel,
            'disposition_uri' => $dispositionUri,
            'disposition_at' => $dispositionAt,
            'container_type' => $this->containerType($epc, $childrenCount),
            'serial_number' => $epc->serial_number,
            'last_seen_at' => $lastSeen,
            'children_count' => $childrenCount,
            'parent' => $this->parentArray($epc),
            'product' => $this->productArray($epc->product),
            'lot' => $this->lotArray($epc->ilmd),
            'parties' => $this->partiesArray($latestDirectEvent),
            'timeline' => $this->buildTimeline($displayEvents, $inferredFromByEventId),
            'map_points' => $this->buildMapPoints($epc, $displayEvents),
            'events' => $this->buildEventsSummary($displayEvents),
            'children' => $this->buildChildrenPreview($epc, asOf: $asOfUtc),
            'transactions' => $this->buildTransactions($displayEvents),
            'verify_url_params' => $this->verifyUrlParams($epc),
            'transformation_links' => $this->buildTransformationLinks($epc),
            'as_of' => $asOfUtc?->toIso8601String(),
        ];
    }

    /**
     * Hot + archived events for the Asset Tracking events table.
     * {@see self::eventsQuery()} stays hot-table only.
     *
     * Hard-capped to {@see self::trackingTableLimit()} most recent events (by event_time, then id).
     * When truncated is true, older events were omitted from the returned records.
     *
     * @return array{records: Collection<int, EpcisEvent>, truncated: bool}
     */
    public function eventsForTrackingTable(Epc $epc, ?Carbon $asOf = null): array
    {
        $hot = $this->eventsQuery($epc, $asOf)->with(['locations'])->get();
        $archived = $this->archivedEpcEvents->forEpc((int) $epc->getKey(), $asOf);

        $merged = $hot
            ->concat($archived)
            ->unique(fn (EpcisEvent $event): int => (int) $event->getKey())
            ->sort(function (EpcisEvent $a, EpcisEvent $b): int {
                $timeCmp = ($a->event_time?->getTimestamp() ?? 0) <=> ($b->event_time?->getTimestamp() ?? 0);

                return $timeCmp !== 0 ? $timeCmp : ((int) $a->getKey() <=> (int) $b->getKey());
            })
            ->values();

        return $this->capTrackingRecords($merged);
    }

    /**
     * Hard cap reused by Asset Tracking table helpers (mirrors timeline initial limit).
     */
    public function trackingTableLimit(): int
    {
        return max(1, (int) config('tracepharma.tracing.initial_timeline_events_limit', 100));
    }

    /**
     * @return Builder<EpcisEvent>
     */
    public function eventsQuery(Epc $epc, ?Carbon $asOf = null): Builder
    {
        $query = EpcisEvent::query()
            ->whereIn('id', function ($query) use ($epc): void {
                $query->select('event_id')
                    ->from('event_epcs')
                    ->where('epc_id', $epc->getKey());
            });

        if ($asOf !== null) {
            $asOfUtc = $asOf->copy()->utc();
            $query->where('epcis_events.event_time', '<=', $asOfUtc->toDateTimeString());

            if (Schema::hasColumn('epcis_events', 'superseded_at')) {
                $query->where(function ($q) use ($asOfUtc): void {
                    $q->whereNull('epcis_events.superseded_at')
                        ->orWhere('epcis_events.superseded_at', '>', $asOfUtc->toDateTimeString());
                });
            }

            // Prefer the generation that was active at T (lowest live ingest_generation per document).
            if (Schema::hasColumn('epcis_events', 'ingest_generation')) {
                $hasSuperseded = Schema::hasColumn('epcis_events', 'superseded_at');
                $supersedeClause = $hasSuperseded
                    ? 'AND (ev2.superseded_at IS NULL OR ev2.superseded_at > ?)'
                    : '';
                $bindings = [(int) $epc->getKey(), $asOfUtc->toDateTimeString()];
                if ($hasSuperseded) {
                    $bindings[] = $asOfUtc->toDateTimeString();
                }

                $query->whereRaw(
                    'epcis_events.ingest_generation = (
                        SELECT MIN(ev2.ingest_generation)
                        FROM event_epcs ee2
                        INNER JOIN epcis_events ev2 ON ev2.id = ee2.event_id
                        WHERE ee2.epc_id = ?
                          AND ev2.document_id = epcis_events.document_id
                          AND ev2.event_time <= ?
                          '.$supersedeClause.'
                    )',
                    $bindings,
                );
            }

            // H6: match ResolveEpcCustodyAsOf — exclude error/voided document events from as-of set.
            if (Schema::hasTable('epcis_documents')) {
                $query->where(function ($status): void {
                    $status->whereNull('epcis_events.document_id')
                        ->orWhereExists(function ($exists): void {
                            $exists->selectRaw('1')
                                ->from('epcis_documents as doc')
                                ->whereColumn('doc.id', 'epcis_events.document_id')
                                ->where(function ($docStatus): void {
                                    $docStatus->whereNull('doc.status')
                                        ->orWhereNotIn('doc.status', ['error', 'voided']);
                                });
                        });
                });
            }

            return $query
                ->orderBy('event_time')
                ->orderBy('id');
        }

        if (Schema::hasColumn('epcis_events', 'ingest_generation')
            && Schema::hasTable('epcis_documents')
            && Schema::hasColumn('epcis_documents', 'ingest_generation')) {
            $query->whereExists(function ($exists): void {
                $exists->selectRaw('1')
                    ->from('epcis_documents')
                    ->whereColumn('epcis_documents.id', 'epcis_events.document_id')
                    ->whereColumn('epcis_events.ingest_generation', 'epcis_documents.ingest_generation');
                LastGoodIngestProjection::constrainDocuments(
                    $exists,
                    successfulStatuses: ['parsed', 'validated', 'received', 'generated'],
                );
            });
        }

        if (Schema::hasColumn('epcis_events', 'superseded_at')) {
            $query->whereNull('epcis_events.superseded_at');
        }

        return $query
            ->orderBy('event_time')
            ->orderBy('id');
    }

    /**
     * Child Epcs aggregated under this parent.
     *
     * H7: when $asOf is set, use aggregation_links valid_from/valid_to so the
     * children set matches custody-at-T (not live open links only). Preferring
     * as-of-aware over hiding metrics because temporal columns already exist.
     *
     * @return Builder<Epc>
     */
    public function childrenQuery(Epc $epc, ?Carbon $asOf = null): Builder
    {
        return Epc::query()
            ->with('ilmd')
            ->whereIn('id', function ($query) use ($epc, $asOf): void {
                $query->select('child_epc_id')
                    ->from('aggregation_links')
                    ->where('parent_epc_id', $epc->getKey());

                if ($asOf !== null) {
                    $asOfUtc = $asOf->copy()->utc()->toDateTimeString();
                    $query->where('valid_from', '<=', $asOfUtc)
                        ->where(function ($openAt) use ($asOfUtc): void {
                            $openAt->whereNull('valid_to')
                                ->orWhere('valid_to', '>', $asOfUtc);
                        });
                } else {
                    $query->whereNull('valid_to');
                }
            });
    }

    /**
     * Distinct biz transactions (type_uri + value) across events for this Epc.
     *
     * Built from a hard-capped event window ({@see self::trackingTableLimit()} most recent).
     * When truncated is true, older events (and any transactions only on those events) were omitted.
     *
     * @return array{records: Collection<int, array{name: string, urn: string, value: string}>, truncated: bool}
     */
    public function transactionsForEpc(Epc $epc): array
    {
        $events = $this->eventsQuery($epc)->with('bizTransactions')->get();
        $sorted = $events
            ->sort(function (EpcisEvent $a, EpcisEvent $b): int {
                $timeCmp = ($a->event_time?->getTimestamp() ?? 0) <=> ($b->event_time?->getTimestamp() ?? 0);

                return $timeCmp !== 0 ? $timeCmp : ((int) $a->getKey() <=> (int) $b->getKey());
            })
            ->values();

        $capped = $this->capTrackingRecords($sorted);

        return [
            'records' => collect($this->buildTransactions($capped['records'])),
            'truncated' => $capped['truncated'],
        ];
    }

    /**
     * True when an event carries a meaningful trace-worthy location or CBV step:
     * a read_point_gln or biz_location_gln, or a bizStep matching shipping /
     * receiving / transporting / arriving / departing (CBV movement steps).
     */
    public static function isTrackable(EpcisEvent $event): bool
    {
        if (filled($event->read_point_gln) || filled($event->biz_location_gln)) {
            return true;
        }

        $bizStep = strtolower((string) $event->biz_step);

        foreach (['shipping', 'receiving', 'transporting', 'arriving', 'departing'] as $needle) {
            if (str_contains($bizStep, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array{
     *   found: bool, scan: string, epc: null, identity: array, status: string, status_tone: string,
     *   primary_identifier: null, gs1_barcode: null, urn: null, disposition: null, disposition_uri: null,
     *   disposition_at: null, container_type: null, serial_number: null, last_seen_at: null,
     *   children_count: int, product: array<string, mixed>, lot: array<string, mixed>, parties: array<string, mixed>,
     *   timeline: list<array>, map_points: list<array>, events: list<array>, children: list<array>,
     *   transactions: list<array>, verify_url_params: null,
     * }
     */
    private function notFoundResult(string $scan, array $identity): array
    {
        return [
            'found' => false,
            'scan' => $scan,
            'epc' => null,
            'identity' => $identity,
            'status' => 'Not found',
            'status_tone' => 'error',
            'primary_identifier' => null,
            'gs1_barcode' => null,
            'urn' => null,
            'disposition' => null,
            'disposition_uri' => null,
            'disposition_at' => null,
            'container_type' => null,
            'serial_number' => null,
            'last_seen_at' => null,
            'children_count' => 0,
            'parent' => null,
            'product' => $this->productArray(null),
            'lot' => $this->lotArray(null),
            'parties' => [],
            'timeline' => [],
            'map_points' => [],
            'events' => [],
            'children' => [],
            'transactions' => [],
            'verify_url_params' => null,
            'transformation_links' => [],
            'as_of' => null,
        ];
    }

    /**
     * TransformationEvent input↔output edges for this EPC (repack lineage).
     * Does not alter aggregation ancestor merge behavior.
     *
     * @return list<array{
     *   role: string,
     *   counterpart_role: string,
     *   counterpart_epc_id: int,
     *   counterpart_urn: ?string,
     *   counterpart_primary: ?string,
     *   event_id: int,
     *   transformation_id: ?string,
     *   event_time: ?string,
     *   url: ?string,
     * }>
     */
    private function buildTransformationLinks(Epc $epc): array
    {
        $epcId = (int) $epc->getKey();

        $participation = DB::table('event_epcs as ee')
            ->join('epcis_events as ev', 'ev.id', '=', 'ee.event_id')
            ->where('ee.epc_id', $epcId)
            ->whereIn('ee.role', ['inputEPC', 'outputEPC'])
            ->where('ev.event_type', 'TransformationEvent')
            ->select(['ee.event_id', 'ee.role', 'ev.event_time', 'ev.extension_json'])
            ->orderBy('ev.event_time')
            ->orderBy('ee.event_id')
            ->get();

        if ($participation->isEmpty()) {
            return [];
        }

        if (Schema::hasColumn('epcis_events', 'ingest_generation')
            && Schema::hasTable('epcis_documents')
            && Schema::hasColumn('epcis_documents', 'ingest_generation')) {
            $validEventIds = $this->eventsQuery($epc)
                ->where('event_type', 'TransformationEvent')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $validSet = array_fill_keys($validEventIds, true);
            $participation = $participation->filter(
                fn ($row): bool => isset($validSet[(int) $row->event_id]),
            );
        }

        if ($participation->isEmpty()) {
            return [];
        }

        $eventIds = $participation->pluck('event_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        $counterparts = DB::table('event_epcs')
            ->whereIn('event_id', $eventIds)
            ->whereIn('role', ['inputEPC', 'outputEPC'])
            ->where('epc_id', '!=', $epcId)
            ->get(['event_id', 'epc_id', 'role']);

        $counterpartEpcIds = $counterparts->pluck('epc_id')->map(fn ($id): int => (int) $id)->unique()->all();
        $counterpartEpcs = $counterpartEpcIds === []
            ? collect()
            : Epc::query()->whereIn('id', $counterpartEpcIds)->get()->keyBy(fn (Epc $e): int => (int) $e->getKey());

        $linksByEvent = [];
        foreach ($counterparts as $row) {
            $linksByEvent[(int) $row->event_id][] = $row;
        }

        $result = [];
        foreach ($participation as $row) {
            $eventId = (int) $row->event_id;
            $role = (string) $row->role;
            $counterpartRole = $role === 'inputEPC' ? 'outputEPC' : 'inputEPC';
            $extension = is_string($row->extension_json ?? null)
                ? (json_decode($row->extension_json, true) ?: [])
                : (is_array($row->extension_json ?? null) ? $row->extension_json : []);
            $transformationId = filled($extension['transformation_id'] ?? null)
                ? (string) $extension['transformation_id']
                : null;
            $eventTime = $row->event_time !== null
                ? Carbon::parse($row->event_time)->toIso8601String()
                : null;

            foreach ($linksByEvent[$eventId] ?? [] as $counterpart) {
                if ((string) $counterpart->role !== $counterpartRole) {
                    continue;
                }

                $otherId = (int) $counterpart->epc_id;
                $other = $counterpartEpcs->get($otherId);
                $display = $other instanceof Epc ? Gs1DualDisplay::forEpc($other) : null;

                $result[] = [
                    'role' => $role,
                    'counterpart_role' => $counterpartRole,
                    'counterpart_epc_id' => $otherId,
                    'counterpart_urn' => $other?->epc_uri,
                    'counterpart_primary' => $display['primary'] ?? null,
                    'event_id' => $eventId,
                    'transformation_id' => $transformationId,
                    'event_time' => $eventTime,
                    'url' => $other instanceof Epc ? AssetTrackingUrl::forEpc($other) : null,
                ];
            }
        }

        return $result;
    }

    /**
     * Immediate open aggregation parent for this EPC (HUD / Children symmetry).
     *
     * @return ?array{epc_id: int, primary_identifier: ?string, gs1_barcode: ?string, scan: ?string, url: ?string}
     */
    private function parentArray(Epc $epc): ?array
    {
        $parent = $this->resolveOpenParent($epc);

        if ($parent === null) {
            return null;
        }

        $display = Gs1DualDisplay::forEpc($parent);
        $scan = AssetTrackingUrl::scanForEpc($parent);

        return [
            'epc_id' => (int) $parent->getKey(),
            'primary_identifier' => $display['primary'] !== '—' ? $display['primary'] : null,
            'gs1_barcode' => $display['gs1_barcode'] !== '—' ? $display['gs1_barcode'] : null,
            'scan' => $scan,
            'url' => AssetTrackingUrl::forEpc($parent),
        ];
    }

    private function resolveOpenParent(Epc $epc): ?Epc
    {
        $link = AggregationLink::query()
            ->open()
            ->where('child_epc_id', $epc->getKey())
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();

        if ($link === null) {
            return null;
        }

        $parentId = (int) $link->parent_epc_id;
        if ($parentId === (int) $epc->getKey()) {
            return null;
        }

        return Epc::query()->with('ilmd')->find($parentId);
    }

    /**
     * @param  Collection<int, EpcisEvent>  $events
     */
    private function preloadEventLocations(Collection $events): void
    {
        $glns = $events
            ->flatMap(fn (EpcisEvent $event): array => [$event->biz_location_gln, $event->read_point_gln])
            ->filter()
            ->map(fn ($gln): string => (string) $gln)
            ->unique()
            ->values()
            ->all();

        if ($glns !== []) {
            $this->locations->preloadForGlns($glns);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function latestEventDisplay(?EpcisEvent $latestEvent): array
    {
        if ($latestEvent === null) {
            return [null, null, null, null];
        }

        $dispositionUri = filled($latestEvent->disposition) ? (string) $latestEvent->disposition : null;
        $dispositionLabel = $dispositionUri !== null ? $this->stripCbvPrefix($dispositionUri) : null;
        $dispositionAt = $latestEvent->event_time?->toIso8601String();

        $gln = $latestEvent->biz_location_gln ?: $latestEvent->read_point_gln;
        $location = $this->locationOfType($latestEvent, 'bizLocation') ?? $latestEvent->locations->first();
        $resolved = $this->locations->resolve($gln, $location);

        return [$dispositionLabel, $dispositionUri, $dispositionAt, $resolved['label']];
    }

    /**
     * container_type per plan rule 7:
     * - sscc: packaging_type ?: packaging_level ?: 'Pallet'
     * - sgtin: packaging_type ?: packaging_level ?: (has aggregated children ⇒ Case) ?: 'Unit'
     */
    private function containerType(Epc $epc, int $childrenCount): ?string
    {
        if ($epc->epc_type === 'sscc') {
            return $epc->packaging_type ?: ($epc->packaging_level ?: 'Pallet');
        }

        if ($epc->epc_type === 'sgtin') {
            if (filled($epc->packaging_type)) {
                return (string) $epc->packaging_type;
            }

            if (filled($epc->packaging_level)) {
                return (string) $epc->packaging_level;
            }

            return $childrenCount > 0 ? 'Case' : 'Unit';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function productArray(?Product $product): array
    {
        if ($product === null) {
            return [
                'ndc' => null,
                'ndc11' => null,
                'name' => null,
                'description' => null,
                'gtin' => null,
                'dosage_form' => null,
                'strength' => null,
                'package_ndc' => null,
                'is_active' => null,
                'linked' => false,
            ];
        }

        $description = trim(implode(' ', array_filter([
            filled($product->strength) ? (string) $product->strength : null,
            filled($product->dosage_form) ? (string) $product->dosage_form : null,
        ])));

        return [
            'ndc' => Ndc::formatPackageDisplay($product->ndc ?? $product->ndc11, $product->package_ndc),
            'ndc11' => $product->ndc11,
            'name' => $product->name,
            'description' => $description !== '' ? $description : null,
            'gtin' => $product->gtin,
            'dosage_form' => $product->dosage_form,
            'strength' => $product->strength,
            'package_ndc' => $product->package_ndc,
            'is_active' => $product->is_active,
            'linked' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lotArray(?EpcIlmd $ilmd): array
    {
        if ($ilmd === null) {
            return [
                'lot_number' => null,
                'expiry_date' => null,
                'manufacturing_date' => null,
                'best_before_date' => null,
                'additional_id' => null,
                'extra' => [],
            ];
        }

        return [
            'lot_number' => $ilmd->lot_number,
            'expiry_date' => $ilmd->expiry_date?->toDateString(),
            'manufacturing_date' => $ilmd->manufacturing_date?->toDateString(),
            'best_before_date' => $ilmd->best_before_date?->toDateString(),
            'additional_id' => $ilmd->additional_id,
            'extra' => $ilmd->extra_json ?? [],
        ];
    }

    /**
     * Flat associative array of labeled fields (Seller / Ship-from / Sold-to / Ship-to)
     * for the Parties infolist tab, sourced from the latest event's document enrichment.
     *
     * @return array<string, ?string>
     */
    private function partiesArray(?EpcisEvent $latestEvent): array
    {
        $document = $latestEvent?->document;

        if (! $document instanceof EpcisDocument) {
            return [];
        }

        $summary = $document->shippingPartiesSummary();
        $parties = [];

        foreach ($summary as $key => $value) {
            if ($key === 'ships_from_different_location' || ! is_array($value)) {
                continue;
            }

            $label = (string) ($value['label'] ?? $key);
            $name = filled($value['name'] ?? null) ? (string) $value['name'] : null;
            $gln = filled($value['gln'] ?? null) ? (string) $value['gln'] : null;

            $display = implode(' · ', array_filter([$name, $gln !== null ? "GLN {$gln}" : null]));
            $parties[$label] = $display !== '' ? $display : null;
        }

        return $parties;
    }

    /**
     * Merge direct EPC events with implied ancestor events (while contained).
     *
     * @param  Collection<int, EpcisEvent>  $directEvents
     * @return array{0: Collection<int, EpcisEvent>, 1: array<int, string>}
     */
    private function mergeDisplayEvents(Epc $epc, Collection $directEvents): array
    {
        $directIds = $directEvents
            ->mapWithKeys(fn (EpcisEvent $event): array => [(int) $event->getKey() => true])
            ->all();

        [$impliedEvents, $inferredFromByEventId] = $this->impliedAncestorEvents($epc);

        $inferredFromByEventId = array_filter(
            $inferredFromByEventId,
            fn (string $label, int $eventId): bool => ! isset($directIds[$eventId]),
            ARRAY_FILTER_USE_BOTH,
        );

        $displayEvents = $directEvents
            ->concat($impliedEvents)
            ->unique(fn (EpcisEvent $event): int => (int) $event->getKey())
            ->sort(function (EpcisEvent $left, EpcisEvent $right): int {
                $timeCompare = ($left->event_time?->getTimestamp() ?? 0)
                    <=> ($right->event_time?->getTimestamp() ?? 0);

                if ($timeCompare !== 0) {
                    return $timeCompare;
                }

                return (int) $left->getKey() <=> (int) $right->getKey();
            })
            ->values();

        return [$displayEvents, $inferredFromByEventId];
    }

    /**
     * Walk open (else latest) aggregation parents up to the configured depth.
     *
     * @return list<array{parent: Epc, valid_from: Carbon, valid_to: ?Carbon}>
     */
    private function resolveContainmentAncestors(Epc $epc, ?int $depthLimit = null): array
    {
        $limit = max(1, $depthLimit ?? (int) config('tracepharma.epcis.validation.hierarchy_depth_limit', 6));
        $ancestors = [];
        $seenParentIds = [];
        $currentId = (int) $epc->getKey();

        for ($depth = 0; $depth < $limit; $depth++) {
            $link = AggregationLink::query()
                ->where('child_epc_id', $currentId)
                ->orderByRaw('CASE WHEN valid_to IS NULL THEN 0 ELSE 1 END')
                ->orderByDesc('valid_from')
                ->orderByDesc('id')
                ->first();

            if ($link === null) {
                break;
            }

            $parentId = (int) $link->parent_epc_id;

            if ($parentId === $currentId || isset($seenParentIds[$parentId])) {
                break;
            }

            $parent = Epc::query()->find($parentId);

            if ($parent === null) {
                break;
            }

            $seenParentIds[$parentId] = true;
            $ancestors[] = [
                'parent' => $parent,
                'valid_from' => $link->valid_from,
                'valid_to' => $link->valid_to,
            ];
            $currentId = $parentId;
        }

        return $ancestors;
    }

    /**
     * Parent EPCIS events that occurred while this EPC was contained under them.
     *
     * @return array{0: Collection<int, EpcisEvent>, 1: array<int, string>}
     */
    private function impliedAncestorEvents(Epc $epc): array
    {
        $ancestors = $this->resolveContainmentAncestors(
            $epc,
            max(1, (int) config('tracepharma.tracing.initial_ancestor_depth_limit', 3)),
        );

        if ($ancestors === []) {
            return [collect(), []];
        }

        $implied = collect();
        $inferredFromByEventId = [];
        $ancestorEventsLimit = max(1, (int) config('tracepharma.tracing.initial_ancestor_events_limit', 50));

        foreach ($ancestors as $ancestor) {
            /** @var Epc $parent */
            $parent = $ancestor['parent'];
            $validFrom = $ancestor['valid_from'];
            $validTo = $ancestor['valid_to'];
            $fromLabel = Gs1DualDisplay::forEpc($parent)['primary'];

            $query = $this->eventsQuery($parent)
                ->with(['locations', 'bizTransactions', 'document']);

            if ($validFrom !== null) {
                $query->where('event_time', '>=', $validFrom);
            }

            if ($validTo !== null) {
                $query->where('event_time', '<=', $validTo);
            }

            $parentEvents = $query
                ->reorder()
                ->orderByDesc('event_time')
                ->orderByDesc('id')
                ->limit($ancestorEventsLimit)
                ->get()
                ->reverse()
                ->values();
            $this->preloadEventLocations($parentEvents);

            foreach ($parentEvents as $event) {
                $eventId = (int) $event->getKey();
                $inferredFromByEventId[$eventId] = $fromLabel;
                $implied->push($event);
            }
        }

        return [$implied, $inferredFromByEventId];
    }

    /**
     * @param  Collection<int, EpcisEvent>  $events
     * @param  array<int, string>  $inferredFromByEventId
     * @return list<array>
     */
    private function buildTimeline(Collection $events, array $inferredFromByEventId = []): array
    {
        return $events->map(function (EpcisEvent $event) use ($inferredFromByEventId): array {
            $bizLocation = $this->locationOfType($event, 'bizLocation') ?? $event->locations->first();
            $siteGln = $event->biz_location_gln ?: $event->read_point_gln;
            $site = $this->locations->resolve($siteGln, $bizLocation);

            $readPointLocation = $this->locationOfType($event, 'readPoint');
            $readPoint = filled($event->read_point_gln) || $readPointLocation !== null
                ? $this->locations->resolve($event->read_point_gln, $readPointLocation)
                : null;

            $eventId = (int) $event->getKey();
            $inferred = array_key_exists($eventId, $inferredFromByEventId);

            return [
                'id' => (int) $event->getKey(),
                'business_step' => filled($event->biz_step) ? $this->stripCbvPrefix((string) $event->biz_step) : null,
                'biz_step_uri' => $event->biz_step,
                'disposition' => filled($event->disposition) ? $this->stripCbvPrefix((string) $event->disposition) : null,
                'disposition_uri' => $event->disposition,
                'event_time' => $event->event_time?->toIso8601String(),
                'action' => $event->action,
                'site' => $site['label'],
                'read_point' => $readPoint['label'] ?? null,
                'inferred' => $inferred,
                'inferred_from' => $inferred ? $inferredFromByEventId[$eventId] : null,
            ];
        })->values()->all();
    }

    /**
     * Ordered custody path: manufacturer, then each read point/site, then current site.
     *
     * @param  Collection<int, EpcisEvent>  $events
     * @return list<array{lat: float, lng: float, label: string, at: ?string, seq: int}>
     */
    private function buildMapPoints(Epc $epc, Collection $events): array
    {
        $stops = [];

        foreach ($events as $event) {
            $at = $event->event_time?->toIso8601String();
            $readPointLocation = $this->locationOfType($event, 'readPoint');
            $bizLocation = $this->locationOfType($event, 'bizLocation');
            $readGln = filled($event->read_point_gln) ? (string) $event->read_point_gln : $readPointLocation?->gln;
            $bizGln = filled($event->biz_location_gln) ? (string) $event->biz_location_gln : $bizLocation?->gln;

            if ($this->sameJourneyGln($readGln, $bizGln)) {
                $this->pushMapStop(
                    $stops,
                    $this->locations->resolve($bizGln ?: $readGln, $bizLocation ?? $readPointLocation),
                    $at,
                );

                continue;
            }

            if (filled($readGln) || $readPointLocation !== null) {
                $this->pushMapStop(
                    $stops,
                    $this->locations->resolve($event->read_point_gln, $readPointLocation),
                    $at,
                );
            }

            if (filled($bizGln) || $bizLocation !== null) {
                $this->pushMapStop(
                    $stops,
                    $this->locations->resolve($event->biz_location_gln, $bizLocation),
                    $at,
                );
            }
        }

        $manufacturer = $this->manufacturerMapStop($epc, $events);
        if ($manufacturer !== null && ! $this->journeyHasGln($stops, $manufacturer['gln'])) {
            array_unshift($stops, $manufacturer);
        }

        $current = $this->currentSiteMapStop($events);
        if ($current !== null) {
            $last = $stops === [] ? null : $stops[array_key_last($stops)];
            if ($last === null || ! $this->sameJourneyGln($last['gln'] ?? null, $current['gln'])) {
                $stops[] = $current;
            }
        }

        $points = [];
        foreach (array_values($stops) as $index => $stop) {
            $points[] = [
                'lat' => $stop['lat'],
                'lng' => $stop['lng'],
                'label' => $stop['label'],
                'at' => $stop['at'],
                'seq' => $index + 1,
            ];
        }

        return $points;
    }

    /**
     * @param  list<array{gln: ?string, lat: float, lng: float, label: string, at: ?string}>  $stops
     * @param  array{name: ?string, gln: ?string, address: ?string, label: string, latitude: ?float, longitude: ?float}  $resolved
     */
    private function pushMapStop(array &$stops, array $resolved, ?string $at): void
    {
        if ($resolved['latitude'] === null || $resolved['longitude'] === null) {
            return;
        }

        $last = $stops === [] ? null : $stops[array_key_last($stops)];
        if ($last !== null && $resolved['gln'] !== null && $last['gln'] === $resolved['gln']) {
            return;
        }

        $stops[] = [
            'gln' => $resolved['gln'],
            'lat' => $resolved['latitude'],
            'lng' => $resolved['longitude'],
            'label' => $resolved['label'],
            'at' => $at,
        ];
    }

    /**
     * @param  list<array{gln: ?string, lat: float, lng: float, label: string, at: ?string}>  $stops
     */
    private function sameJourneyGln(?string $left, ?string $right): bool
    {
        $leftDigits = $this->digitsOnly($left);
        $rightDigits = $this->digitsOnly($right);

        return $leftDigits !== null && $leftDigits === $rightDigits;
    }

    private function digitsOnly(?string $gln): ?string
    {
        if (! filled($gln)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $gln) ?? '';

        return $digits !== '' ? $digits : null;
    }

    private function journeyHasGln(array $stops, ?string $gln): bool
    {
        if ($gln === null) {
            return false;
        }

        foreach ($stops as $stop) {
            if ($this->sameJourneyGln($stop['gln'] ?? null, $gln)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, EpcisEvent>  $events
     * @return array{gln: ?string, lat: float, lng: float, label: string, at: ?string}|null
     */
    private function manufacturerMapStop(Epc $epc, Collection $events): ?array
    {
        $document = $events
            ->map(fn (EpcisEvent $event): mixed => $event->document)
            ->first(fn (mixed $document): bool => $document instanceof EpcisDocument);

        $gln = $epc->product?->tradingPartner?->gln
            ?? (filled($document?->ship_from_gln) ? (string) $document->ship_from_gln : null)
            ?? (filled($document?->sender_gln) ? (string) $document->sender_gln : null);

        if ($gln === null) {
            $commissioning = $events->first(
                fn (EpcisEvent $event): bool => filled($event->biz_step)
                    && str_contains((string) $event->biz_step, 'commissioning'),
            );
            $gln = $commissioning?->biz_location_gln ?: $commissioning?->read_point_gln;
        }

        if (! filled($gln)) {
            return null;
        }

        $resolved = $this->locations->resolve((string) $gln);
        if ($resolved['latitude'] === null || $resolved['longitude'] === null) {
            return null;
        }

        return [
            'gln' => $resolved['gln'],
            'lat' => $resolved['latitude'],
            'lng' => $resolved['longitude'],
            'label' => $resolved['label'],
            'at' => null,
        ];
    }

    /**
     * @param  Collection<int, EpcisEvent>  $events
     * @return array{gln: ?string, lat: float, lng: float, label: string, at: ?string}|null
     */
    private function currentSiteMapStop(Collection $events): ?array
    {
        $latest = $events->last();
        if (! $latest instanceof EpcisEvent) {
            return null;
        }

        $location = $this->locationOfType($latest, 'bizLocation')
            ?? $this->locationOfType($latest, 'readPoint');
        $gln = $latest->biz_location_gln ?: $latest->read_point_gln;
        $resolved = $this->locations->resolve($gln, $location);

        if ($resolved['latitude'] === null || $resolved['longitude'] === null) {
            return null;
        }

        return [
            'gln' => $resolved['gln'],
            'lat' => $resolved['latitude'],
            'lng' => $resolved['longitude'],
            'label' => $resolved['label'],
            'at' => $latest->event_time?->toIso8601String(),
        ];
    }

    private function preloadManufacturerLocation(Epc $epc, ?EpcisEvent $latestEvent): void
    {
        $glns = array_filter([
            $epc->product?->tradingPartner?->gln,
            $latestEvent?->document?->ship_from_gln,
            $latestEvent?->document?->sender_gln,
        ]);

        if ($glns !== []) {
            $this->locations->preloadForGlns(array_values($glns));
        }
    }

    /**
     * Lightweight, most-recent-first preview for the Summary tab.
     * The Filament events table queries {@see self::eventsQuery()} directly.
     *
     * @param  Collection<int, EpcisEvent>  $events
     * @return list<array>
     */
    private function buildEventsSummary(Collection $events, int $limit = 5): array
    {
        return $events
            ->reverse()
            ->take($limit)
            ->map(fn (EpcisEvent $event): array => [
                'event_type' => $event->event_type,
                'action' => $event->action,
                'business_step' => filled($event->biz_step) ? $this->stripCbvPrefix((string) $event->biz_step) : null,
                'disposition' => filled($event->disposition) ? $this->stripCbvPrefix((string) $event->disposition) : null,
                'event_time' => $event->event_time?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Lightweight preview of aggregated children (live or as-of).
     * The Filament children table queries {@see self::childrenQuery()} directly.
     *
     * @return list<array>
     */
    private function buildChildrenPreview(Epc $epc, int $limit = 5, ?Carbon $asOf = null): array
    {
        return $this->childrenQuery($epc, $asOf)
            ->limit($limit)
            ->get()
            ->map(function (Epc $child): array {
                $display = Gs1DualDisplay::forEpc($child);

                return [
                    'id' => $child->getKey(),
                    'epc_type' => $child->epc_type,
                    'primary_identifier' => $display['primary'],
                    'urn' => $display['urn'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, EpcisEvent>  $events
     * @return list<array{name: string, urn: string, value: string}>
     */
    private function buildTransactions(Collection $events): array
    {
        $seen = [];
        $result = [];

        foreach ($events as $event) {
            foreach ($event->bizTransactions as $bizTransaction) {
                $typeUri = (string) $bizTransaction->type_uri;
                $value = (string) $bizTransaction->value;
                $key = $typeUri.'|'.$value;

                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $result[] = [
                    'name' => BizTransactionLabel::forTypeUri($typeUri),
                    'urn' => $typeUri,
                    'value' => $value,
                ];
            }
        }

        return $result;
    }

    /**
     * @return ?array{barcode: string, gtin: ?string, serial: ?string}
     */
    private function verifyUrlParams(Epc $epc): ?array
    {
        return VerifyUrlParams::forEpc($epc);
    }

    private function hasOpenQuarantineHold(Epc $epc): bool
    {
        if (! Schema::hasTable('quarantine_holds')) {
            return false;
        }

        return QuarantineHold::query()
            ->open()
            ->where('epc_id', $epc->getKey())
            ->exists();
    }

    private function isInTransitInsideOpenParent(Epc $epc): bool
    {
        $parent = $this->resolveOpenParent($epc);
        if ($parent === null) {
            return false;
        }

        $latestParentEvent = $this->eventsQuery($parent)
            ->reorder()
            ->orderByDesc('event_time')
            ->orderByDesc('id')
            ->first();

        if ($latestParentEvent === null || ! filled($latestParentEvent->disposition)) {
            return false;
        }

        return str_contains(strtolower((string) $latestParentEvent->disposition), 'in_transit');
    }

    private function locationOfType(EpcisEvent $event, string $type): ?EventLocation
    {
        return $event->locations->firstWhere('location_type', $type);
    }

    private function stripCbvPrefix(string $uri): string
    {
        if (! str_contains($uri, ':')) {
            return $uri;
        }

        $parts = explode(':', $uri);
        $segment = (string) end($parts);

        return $segment !== '' ? $segment : $uri;
    }

    private function initialDirectEventsLimit(): int
    {
        return max(1, (int) config('tracepharma.tracing.initial_direct_events_limit', 100));
    }

    /**
     * @param  Collection<int, EpcisEvent>  $events
     * @return Collection<int, EpcisEvent>
     */
    private function capTimelineEvents(Collection $events): Collection
    {
        $limit = $this->trackingTableLimit();

        if ($events->count() <= $limit) {
            return $events;
        }

        return $events->slice(-$limit)->values();
    }

    /**
     * Keep the most recent {@see self::trackingTableLimit()} rows (collection already ascending).
     *
     * @param  Collection<int, EpcisEvent>  $events
     * @return array{records: Collection<int, EpcisEvent>, truncated: bool}
     */
    private function capTrackingRecords(Collection $events): array
    {
        $limit = $this->trackingTableLimit();

        if ($events->count() <= $limit) {
            return ['records' => $events->values(), 'truncated' => false];
        }

        return [
            'records' => $events->slice(-$limit)->values(),
            'truncated' => true,
        ];
    }
}
