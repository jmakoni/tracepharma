<?php

declare(strict_types=1);

namespace App\Actions\Disposition;

use App\Actions\Labeling\PersistAuthoredSsccEpcis;
use App\Actions\Outbound\GenerateDispositionEpcisDocument;
use App\Actions\Outbound\GenerateDispositionObjectEvent;
use App\Enums\EpcisAuthoredKind;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Services\Custody\EpcCustodyGate;
use App\Support\Custody\ResolveEpcLastKnownGln;
use App\Support\Custody\TerminalEpcDisposition;
use App\Support\Disposition\AcquireDecommissionEpcLocks;
use App\Support\Receiving\EpcOnAnotherOpenReceivingSession;
use App\Support\Shipping\EpcOnOpenShippingSession;
use App\Support\Shipping\ShippableEpcsAtSite;
use App\Support\Transferring\EpcOnOpenTransferringSession;
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
    ) {}

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

        $locks = $this->decommissionLocks->acquire($epcIds);

        try {
            return $this->emitWithinLock($epcIds, $siteId, $options);
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
    private function emitWithinLock(array $epcIds, int $siteId, array $options): array
    {
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
        );

        $uuid = (string) Str::uuid();
        $path = 'epcis/outbound/decommission-'.$uuid.'.xml';

        $document = $this->persist->handle($xml, $path, [
            'authored_kind' => EpcisAuthoredKind::Decommissioning,
            'original_filename' => 'decommission-'.$uuid.'.xml',
            'notes' => 'Generated decommissioning EPCIS for '.count($uris).' EPC(s).',
            'ship_from_site_id' => $siteId,
            'sync' => (bool) ($options['sync'] ?? true),
            'dispatch' => (bool) ($options['dispatch'] ?? true),
        ]);

        $expandedLinkIds = $this->expandOpenLinkComponent($epcIds);
        $decommissionTime = $this->resolveDecommissionEventTime($document);
        $this->closeOpenAggregationLinks($expandedLinkIds, $decommissionTime);

        $driftCount = 0;
        $driftNotes = null;

        return [
            'document' => $document,
            'decommissioned_count' => count($uris),
            'drift_notes' => $driftNotes,
            'drift_count' => $driftCount,
            'path' => $path,
        ];
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
