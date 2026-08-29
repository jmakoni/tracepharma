<?php

declare(strict_types=1);

namespace App\Actions\Disposition;

use App\Actions\Labeling\PersistAuthoredSsccEpcis;
use App\Actions\Outbound\GenerateDispositionEpcisDocument;
use App\Actions\Outbound\GenerateDispositionObjectEvent;
use App\Domain\Aggregation\AggregationHierarchyService;
use App\Domain\Aggregation\HierarchyDriftReport;
use App\Enums\DecommissionReason;
use App\Enums\EpcisAuthoredKind;
use App\Enums\ExceptionStatus;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Services\Custody\EpcCustodyGate;
use App\Services\Exceptions\ExceptionService;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Custody\TerminalEpcDisposition;
use App\Support\Disposition\AcquireDecommissionEpcLocks;
use App\Support\Disposition\AssertDecommissionMassApproval;
use App\Support\Receiving\EpcOnAnotherOpenReceivingSession;
use App\Support\Shipping\EpcOnOpenShippingSession;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\Transferring\EpcOnOpenTransferringSession;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class EmitDecommissioningEpcis
{
    public function __construct(
        private readonly GenerateDispositionEpcisDocument $documentGenerator,
        private readonly PersistAuthoredSsccEpcis $persist,
        private readonly EpcCustodyGate $custodyGate,
        private readonly ShippableEpcsAtSite $shippableEpcsAtSite,
        private readonly AcquireDecommissionEpcLocks $decommissionLocks,
        private readonly ResolveEpcLastKnownGln $lastKnownGln,
        private readonly EpcOnOpenShippingSession $epcOnOpenShippingSession,
        private readonly EpcOnOpenTransferringSession $epcOnOpenTransferringSession,
        private readonly EpcOnAnotherOpenReceivingSession $epcOnAnotherOpenReceivingSession,
        private readonly AggregationHierarchyService $aggregationHierarchy,
        private readonly ExceptionService $exceptionService,
        private readonly AssertDecommissionMassApproval $assertMassApproval,
    ) {}

    /**
     * @param  list<int>  $epcIds
     * @param  array{
     *     sync?: bool,
     *     dispatch?: bool,
     *     reason?: DecommissionReason|string|null,
     *     approver_user_id?: int|null
     * }  $options
     * @return array{
     *     document: EpcisDocument|null,
     *     decommissioned_count: int,
     *     drift_notes: string|null,
     *     drift_count: int,
     *     path: string|null
     * }
     */
    public function handle(array $epcIds, int $siteId, array $options = []): array
    {
        $epcIds = array_values(array_unique(array_filter(
            array_map(intval(...), $epcIds),
            fn (int $id): bool => $id > 0,
        )));

        if ($epcIds === []) {
            return [
                'document' => null,
                'decommissioned_count' => 0,
                'drift_notes' => null,
                'drift_count' => 0,
                'path' => null,
            ];
        }

        $reason = DecommissionReason::tryFromMixed($options['reason'] ?? null);
        if ($reason === null) {
            throw new InvalidArgumentException(
                'A decommission reason is required (destroyed, expired, recalled, returned, suspect/illegitimate, or QA reject).',
            );
        }

        $approverUserId = isset($options['approver_user_id']) && $options['approver_user_id'] !== null && $options['approver_user_id'] !== ''
            ? (int) $options['approver_user_id']
            : null;

        $this->assertMassApproval->handle(count($epcIds), $approverUserId, null, $siteId);

        $locks = $this->decommissionLocks->acquire($epcIds);

        try {
            return $this->emitWithinLock($epcIds, $siteId, $options, $reason, $approverUserId);
        } finally {
            $this->decommissionLocks->release($locks);
        }
    }

    /**
     * @param  list<int>  $epcIds
     * @param  array{sync?: bool, dispatch?: bool}  $options
     * @return array{
     *     document: EpcisDocument|null,
     *     decommissioned_count: int,
     *     drift_notes: string|null,
     *     drift_count: int,
     *     path: string|null
     * }
     */
    private function emitWithinLock(
        array $epcIds,
        int $siteId,
        array $options,
        DecommissionReason $reason,
        ?int $approverUserId,
    ): array {
        $epcs = Epc::query()
            ->whereIn('id', $epcIds)
            ->get()
            ->keyBy(fn (Epc $epc): int => (int) $epc->getKey());

        foreach ($epcIds as $epcId) {
            $epc = $epcs->get($epcId);
            if (! $epc instanceof Epc) {
                throw new InvalidArgumentException("EPC #{$epcId} is missing for decommissioning.");
            }

            $meta = $this->lastKnownGln->latestEventMeta($epc);
            if (TerminalEpcDisposition::matches($meta)) {
                throw new InvalidArgumentException(
                    'Cannot decommission — the latest event records this unit as '.
                    TerminalEpcDisposition::label($meta['disposition'] ?? null).'.',
                );
            }
        }

        $notOnHand = [];
        foreach ($epcIds as $epcId) {
            if (! $this->shippableEpcsAtSite->contains($siteId, $epcId)) {
                $notOnHand[] = $epcId;
            }
        }

        if ($notOnHand !== []) {
            throw new InvalidArgumentException(
                'Cannot decommission — EPC(s) are not on hand at the selected site: '.
                implode(', ', array_map(fn (int $id): string => '#'.$id, $notOnHand)).'.',
            );
        }

        // Terminal + quarantine refusal (custody already implied by on-hand).
        $this->custodyGate->assertOperableFor($epcIds, 'decommissioning');

        $uris = [];
        foreach ($epcIds as $epcId) {
            $epc = $epcs->get($epcId);
            if (! $epc instanceof Epc || blank($epc->epc_uri)) {
                throw new InvalidArgumentException("EPC #{$epcId} is missing an epc_uri for decommissioning.");
            }

            if ($this->epcOnOpenShippingSession->exists($epc)) {
                throw new InvalidArgumentException(
                    'Cannot decommission — this unit is already confirmed on an open ship order.',
                );
            }

            if ($this->epcOnOpenTransferringSession->exists($epc)) {
                throw new InvalidArgumentException(
                    'Cannot decommission — this unit is already confirmed on an open or in-transit transfer.',
                );
            }

            if ($this->epcOnAnotherOpenReceivingSession->existsOnAnyExclusiveSession($epc)) {
                throw new InvalidArgumentException(
                    'Cannot decommission — this unit is already confirmed on an open receive session.',
                );
            }

            $uris[] = (string) $epc->epc_uri;
        }

        $xml = $this->documentGenerator->execute(
            $uris,
            GenerateDispositionObjectEvent::KIND_DECOMMISSIONING,
            $siteId,
            null,
            [
                'disposition' => $reason->dispositionLocal(),
                'decommission_reason' => $reason->value,
            ],
        );

        $uuid = (string) Str::uuid();
        $path = 'epcis/outbound/decommission-'.$uuid.'.xml';
        $approverNote = $approverUserId !== null ? " mass_approver_user_id={$approverUserId}" : '';

        $document = $this->persist->handle($xml, $path, [
            'authored_kind' => EpcisAuthoredKind::Decommissioning,
            'original_filename' => 'decommission-'.$uuid.'.xml',
            'notes' => 'Generated decommissioning EPCIS for '.count($uris).' EPC(s).'
                .' reason='.$reason->value
                .' disposition='.$reason->dispositionUri()
                .$approverNote.'.',
            'ship_from_site_id' => $siteId,
            'sync' => (bool) ($options['sync'] ?? true),
            'dispatch' => (bool) ($options['dispatch'] ?? true),
        ]);

        $this->stampReasonOnEvents($document, $reason);

        $expandedLinkIds = $this->expandOpenLinkComponent($epcIds);
        [$driftCount, $driftNotes] = $this->detectAndRecordDrift(
            $expandedLinkIds,
            $uris,
            $document,
            $siteId,
        );

        $decommissionTime = $this->resolveDecommissionEventTime($document);
        $this->closeOpenAggregationLinks($expandedLinkIds, $decommissionTime);

        return [
            'document' => $document,
            'decommissioned_count' => count($uris),
            'drift_notes' => $driftNotes,
            'drift_count' => $driftCount,
            'path' => $path,
        ];
    }

    private function stampReasonOnEvents(EpcisDocument $document, DecommissionReason $reason): void
    {
        $events = EpcisEvent::query()
            ->where('document_id', $document->getKey())
            ->where('event_type', 'ObjectEvent')
            ->get();

        foreach ($events as $event) {
            $extension = is_array($event->extension_json) ? $event->extension_json : [];
            $extension['decommission_reason'] = $reason->value;
            $event->forceFill(['extension_json' => $extension])->save();
        }
    }

    /**
     * Snapshot open links, run domain drift detection, open BROKEN_AGGREGATION cases.
     * Does not throw — ObjectEvent already persisted.
     *
     * @param  list<int>  $linkIds
     * @param  list<string>  $decommissionedUris
     * @return array{0: int, 1: string|null}
     */
    private function detectAndRecordDrift(
        array $linkIds,
        array $decommissionedUris,
        EpcisDocument $document,
        int $siteId,
    ): array {
        if ($linkIds === []) {
            return [0, null];
        }

        $uriPairs = $this->openLinkUriPairs($linkIds);
        if ($uriPairs === []) {
            return [0, null];
        }

        $roots = $this->aggregationHierarchy->rebuildFromLinks($uriPairs);
        if ($roots === []) {
            return [0, null];
        }

        /** @var list<string> $orphaned */
        $orphaned = [];
        /** @var list<string> $brokenParents */
        $brokenParents = [];
        /** @var array<string, int> $quantityGaps */
        $quantityGaps = [];

        foreach ($roots as $root) {
            $report = $this->aggregationHierarchy->detectDriftAfterDecommission($root, $decommissionedUris);
            $orphaned = [...$orphaned, ...$report->orphanedUris];
            $brokenParents = [...$brokenParents, ...$report->brokenParentRefs];
            foreach ($report->quantityGapsByParent as $parentUri => $gap) {
                $quantityGaps[$parentUri] = ($quantityGaps[$parentUri] ?? 0) + (int) $gap;
            }
        }

        $merged = new HierarchyDriftReport(
            orphanedUris: array_values(array_unique($orphaned)),
            brokenParentRefs: array_values(array_unique($brokenParents)),
            removedUris: array_values(array_unique($decommissionedUris)),
            quantityGapsByParent: $quantityGaps,
        );

        if (! $merged->hasDrift()) {
            return [0, null];
        }

        $driftCount = array_sum($merged->quantityGapsByParent) + count($merged->orphanedUris);
        $parentCount = count(array_unique([
            ...array_keys($merged->quantityGapsByParent),
            ...$merged->brokenParentRefs,
        ]));
        $driftNotes = sprintf(
            'Hierarchy drift after decommission: %d missing child placement(s) across %d parent(s).',
            $driftCount,
            max(1, $parentCount),
        );

        $this->openDriftExceptions($merged, $document, $siteId, $driftNotes);

        return [$driftCount, $driftNotes];
    }

    /**
     * @param  list<int>  $linkIds
     * @return list<array{parent: string, child: string}>
     */
    private function openLinkUriPairs(array $linkIds): array
    {
        $links = AggregationLink::query()
            ->open()
            ->whereIn('id', $linkIds)
            ->with(['parentEpc:id,epc_uri', 'childEpc:id,epc_uri'])
            ->get();

        $pairs = [];
        foreach ($links as $link) {
            $parentUri = trim((string) ($link->parentEpc?->epc_uri ?? ''));
            $childUri = trim((string) ($link->childEpc?->epc_uri ?? ''));
            if ($parentUri === '' || $childUri === '') {
                continue;
            }
            $pairs[] = ['parent' => $parentUri, 'child' => $childUri];
        }

        return $pairs;
    }

    private function openDriftExceptions(
        HierarchyDriftReport $report,
        EpcisDocument $document,
        int $siteId,
        string $driftNotes,
    ): void {
        $type = ExceptionType::query()->where('code', 'BROKEN_AGGREGATION')->first();
        if ($type === null) {
            (new ExceptionTypeSeeder)->run();
            $type = ExceptionType::query()->where('code', 'BROKEN_AGGREGATION')->first();
        }

        if ($type === null) {
            return;
        }

        $driftedParentUris = array_values(array_unique([
            ...array_keys($report->quantityGapsByParent),
            ...$report->brokenParentRefs,
        ]));

        if ($driftedParentUris === []) {
            return;
        }

        $parents = Epc::query()
            ->whereIn('epc_uri', $driftedParentUris)
            ->get()
            ->keyBy(fn (Epc $epc): string => (string) $epc->epc_uri);

        $removedChildIds = Epc::query()
            ->whereIn('epc_uri', $report->removedUris)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        /** @var array<int, list<int>> $removedChildIdsByParentId */
        $removedChildIdsByParentId = [];

        if ($removedChildIds !== [] && $parents->isNotEmpty()) {
            $parentIds = $parents->map(fn (Epc $epc): int => (int) $epc->getKey())->values()->all();

            $links = AggregationLink::query()
                ->open()
                ->whereIn('parent_epc_id', $parentIds)
                ->whereIn('child_epc_id', $removedChildIds)
                ->get(['parent_epc_id', 'child_epc_id']);

            foreach ($links as $link) {
                $parentId = (int) $link->parent_epc_id;
                $removedChildIdsByParentId[$parentId][] = (int) $link->child_epc_id;
            }
        }

        foreach ($driftedParentUris as $parentUri) {
            $parent = $parents->get($parentUri);
            if (! $parent instanceof Epc) {
                continue;
            }

            $parentId = (int) $parent->getKey();

            $alreadyOpen = ExceptionCase::query()
                ->where('exception_type_id', $type->getKey())
                ->whereNotIn('status', [
                    ExceptionStatus::Resolved->value,
                    ExceptionStatus::Closed->value,
                    ExceptionStatus::Cancelled->value,
                ])
                ->whereHas('epcs', fn ($query) => $query->where('epcs.id', $parentId))
                ->exists();

            if ($alreadyOpen) {
                continue;
            }

            $epcIds = array_values(array_unique([
                $parentId,
                ...($removedChildIdsByParentId[$parentId] ?? []),
            ]));

            $this->exceptionService->create([
                'exception_type_id' => $type->getKey(),
                'document_id' => $document->getKey(),
                'site_id' => $siteId,
                'title' => $type->name,
                'description' => $driftNotes.' Parent: '.$parentUri,
                'status' => ExceptionStatus::New->value,
            ], $epcIds);
        }
    }

    /**
     * @param  list<int>  $linkIds
     */
    private function closeOpenAggregationLinks(array $linkIds, string $validTo): void
    {
        if ($linkIds === []) {
            return;
        }

        AggregationLink::query()
            ->whereIn('id', $linkIds)
            ->whereNull('valid_to')
            ->where('valid_from', '<=', $validTo)
            ->update(['valid_to' => $validTo]);
    }

    private function resolveDecommissionEventTime(EpcisDocument $document): string
    {
        $event = EpcisEvent::query()
            ->where('document_id', $document->getKey())
            ->where('event_type', 'ObjectEvent')
            ->orderBy('id')
            ->first();

        return $event?->event_time?->format('Y-m-d H:i:s.u') ?? now()->format('Y-m-d H:i:s.u');
    }

    /**
     * BFS over open aggregation_links from seed EPC IDs until the connected component closes.
     *
     * @param  list<int>  $seedEpcIds
     * @return list<int> open link IDs in the component
     */
    private function expandOpenLinkComponent(array $seedEpcIds): array
    {
        /** @var array<int, true> $componentEpcIds */
        $componentEpcIds = array_fill_keys($seedEpcIds, true);
        /** @var array<int, true> $linkIds */
        $linkIds = [];
        $frontier = $seedEpcIds;

        while ($frontier !== []) {
            $links = AggregationLink::query()
                ->open()
                ->where(function ($query) use ($frontier): void {
                    $query->whereIn('parent_epc_id', $frontier)
                        ->orWhereIn('child_epc_id', $frontier);
                })
                ->get(['id', 'parent_epc_id', 'child_epc_id']);

            $nextFrontier = [];
            foreach ($links as $link) {
                $linkIds[(int) $link->getKey()] = true;

                foreach ([(int) $link->parent_epc_id, (int) $link->child_epc_id] as $epcId) {
                    if (! isset($componentEpcIds[$epcId])) {
                        $componentEpcIds[$epcId] = true;
                        $nextFrontier[] = $epcId;
                    }
                }
            }

            $frontier = $nextFrontier;
        }

        return array_keys($linkIds);
    }
}
