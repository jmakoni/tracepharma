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
use App\Support\Gs1\Ndc;
use App\Support\Tracing\AssetTrackingUrl;
use App\Support\Tracing\BizTransactionLabel;
use App\Support\Tracing\Gs1DualDisplay;
use App\Support\Tracing\LocationDisplayResolver;
use App\Support\Tracing\VerifyUrlParams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Build the full Asset Tracking payload for a single scanned identifier
 * (SGTIN or SSCC): identity, product/lot context, timeline, map points,
 * parties, and biz transactions.
 *
 * Table-backed sections of the Filament page (events, children) should query
 * via {@see self::eventsQuery()} / {@see self::childrenQuery()} rather than
 * relying on the small preview lists returned here.
 */
final class BuildAssetTrace
{
    public function __construct(
        private ResolveEpcFromScan $resolveEpcFromScan,
        private LocationDisplayResolver $locations,
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
     * }
     */
    public function handle(string $scan): array
    {
        $resolved = $this->resolveEpcFromScan->handle($scan);
        $epc = $resolved['epc'];
        $identity = $resolved['identity'];

        if ($epc === null) {
            return $this->notFoundResult($scan, $identity);
        }

        $epc->loadMissing(['product.tradingPartner', 'ilmd']);

        $eventRelations = ['locations', 'bizTransactions', 'document'];

        // Status HUD fields use direct EPC events only — not closed-ancestor implied events.
        $latestDirectEvent = $this->eventsQuery($epc)
            ->with($eventRelations)
            ->reorder()
            ->orderByDesc('event_time')
            ->orderByDesc('id')
            ->first();

        $events = $this->eventsQuery($epc)
            ->with($eventRelations)
            ->reorder()
            ->orderByDesc('event_time')
            ->orderByDesc('id')
            ->limit($this->initialDirectEventsLimit())
            ->get()
            ->reverse()
            ->values();

        $this->preloadEventLocations($events);
        $this->preloadManufacturerLocation($epc, $latestDirectEvent);

        [$displayEvents, $inferredFromByEventId] = $this->mergeDisplayEvents($epc, $events);

        $displayEvents = $this->capTimelineEvents($displayEvents);
        $quarantined = $this->hasOpenQuarantineHold($epc);
        $inCustody = app(EpcCustodyGate::class)->isInCustody($epc);
        $inTransitViaParent = $this->isInTransitInsideOpenParent($epc);

        $display = Gs1DualDisplay::forEpc($epc);
        $childrenCount = AggregationLink::query()
            ->open()
            ->where('parent_epc_id', $epc->getKey())
            ->count();

        [$dispositionLabel, $dispositionUri, $dispositionAt, $lastSeen] = $this->latestEventDisplay($latestDirectEvent);

        return [
            'found' => true,
            'scan' => $scan,
            'epc' => (int) $epc->getKey(),
            'identity' => $identity,
            'status' => $quarantined
                ? 'Quarantined'
                : ($inTransitViaParent
                    ? 'In transit'
                    : ($inCustody ? 'In custody' : 'Not in custody')),
            'status_tone' => $quarantined
                ? 'warn'
                : ($inTransitViaParent || ! $inCustody ? 'warn' : 'ok'),
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
            'children' => $this->buildChildrenPreview($epc),
            'transactions' => $this->buildTransactions($displayEvents),
            'verify_url_params' => $this->verifyUrlParams($epc),
        ];
    }

    /**
     * @return Builder<EpcisEvent>
     */
    public function eventsQuery(Epc $epc): Builder
    {
        $query = EpcisEvent::query()
            ->whereIn('id', function ($query) use ($epc): void {
                $query->select('event_id')
                    ->from('event_epcs')
                    ->where('epc_id', $epc->getKey());
            });

        if (Schema::hasColumn('epcis_events', 'ingest_generation')
            && Schema::hasTable('epcis_documents')
            && Schema::hasColumn('epcis_documents', 'ingest_generation')) {
            $query->whereExists(function ($exists): void {
                $exists->selectRaw('1')
                    ->from('epcis_documents')
                    ->whereColumn('epcis_documents.id', 'epcis_events.document_id')
                    ->whereColumn('epcis_events.ingest_generation', 'epcis_documents.ingest_generation');
            });
        }

        return $query
            ->orderBy('event_time')
            ->orderBy('id');
    }

    /**
     * Child Epcs currently aggregated under this parent (open aggregation links).
     *
     * @return Builder<Epc>
     */
    public function childrenQuery(Epc $epc): Builder
    {
        return Epc::query()
            ->with('ilmd')
            ->whereIn('id', function ($query) use ($epc): void {
                $query->select('child_epc_id')
                    ->from('aggregation_links')
                    ->where('parent_epc_id', $epc->getKey())
                    ->whereNull('valid_to');
            });
    }

    /**
     * Distinct biz transactions (type_uri + value) across all events for this Epc.
     *
     * @return Collection<int, array{name: string, urn: string, value: string}>
     */
    public function transactionsForEpc(Epc $epc): Collection
    {
        $events = $this->eventsQuery($epc)->with('bizTransactions')->get();

        return collect($this->buildTransactions($events));
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
        ];
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
     * Lightweight preview of currently aggregated children.
     * The Filament children table queries {@see self::childrenQuery()} directly.
     *
     * @return list<array>
     */
    private function buildChildrenPreview(Epc $epc, int $limit = 5): array
    {
        return $this->childrenQuery($epc)
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
        $limit = max(1, (int) config('tracepharma.tracing.initial_timeline_events_limit', 100));

        if ($events->count() <= $limit) {
            return $events;
        }

        return $events->slice(-$limit)->values();
    }
}
