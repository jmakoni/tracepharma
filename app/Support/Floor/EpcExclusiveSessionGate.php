<?php

namespace App\Support\Floor;

use App\Models\Disposition\DispositionScanLine;
use App\Models\Disposition\DispositionSession;
use App\Models\Epcis\AggregationLink;
use App\Models\Epcis\Epc;
use App\Models\Packing\PackingScanLine;
use App\Models\Packing\PackingSession;
use App\Models\Receiving\ReceivingScanLine;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Transferring\TransferringScanLine;
use App\Models\Transferring\TransferringSession;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Tenant-wide serial reservation: one EPC cannot sit on two unsubmitted work sessions.
 *
 * A reserved sealed parent also reserves its open aggregation descendants.
 * A reserved child blocks sealed-parent scans of its open ancestors.
 * Siblings of a reserved child stay free.
 */
final class EpcExclusiveSessionGate
{
    /** @var list<string> */
    public const RECEIVING_RESERVATION_STATUSES = ['staged', 'confirmed', 'unexpected'];

    /** @var list<string> */
    public const PACKING_RESERVATION_STATUSES = ['staged', 'confirmed'];

    /** @var list<string> */
    public const DISPOSITION_RESERVATION_STATUSES = ['staged', 'confirmed'];

    private const HIERARCHY_WALK_LIMIT = 8;

    public function check(Epc $epc, ExclusiveSessionContext $except = new ExclusiveSessionContext): ?EpcExclusiveBlock
    {
        $epcId = (int) $epc->getKey();
        $ancestors = $this->openAncestorIds($epcId);
        $descendants = $this->openDescendantIds($epcId);

        return $this->checkDirectMany([$epcId, ...$ancestors, ...$descendants], $except);
    }

    /**
     * Confirm-path lookup: this EPC only. Does not walk ancestors or descendants.
     */
    public function checkScannedEpc(Epc $epc, ExclusiveSessionContext $except = new ExclusiveSessionContext): ?EpcExclusiveBlock
    {
        return $this->checkDirect((int) $epc->getKey(), $except);
    }

    /**
     * Complete-path: first reserved EPC among this unit, its open ancestors, and open descendants.
     *
     * @return array{block: EpcExclusiveBlock, epc: Epc}|null
     */
    public function firstHierarchyReservation(Epc $epc, ExclusiveSessionContext $except = new ExclusiveSessionContext): ?array
    {
        $parentId = (int) $epc->getKey();
        $ids = [$parentId, ...$this->openAncestorIds($parentId), ...$this->openDescendantIds($parentId)];

        foreach ($ids as $epcId) {
            $block = $this->checkDirect($epcId, $except);
            if ($block === null) {
                continue;
            }

            $reserved = $epcId === $parentId
                ? $epc
                : Epc::query()->find($epcId);

            if (! $reserved instanceof Epc) {
                continue;
            }

            return [
                'block' => $block,
                'epc' => $reserved,
            ];
        }

        return null;
    }

    /**
     * Complete-path: throw if any parent or its open descendants is reserved elsewhere.
     *
     * @param  list<int>  $epcIds
     *
     * @throws InvalidArgumentException
     */
    public function assertParentsHierarchyFree(array $epcIds, ExclusiveSessionContext $except = new ExclusiveSessionContext): void
    {
        foreach ($epcIds as $epcId) {
            $parent = Epc::query()->find((int) $epcId);
            if (! $parent instanceof Epc) {
                continue;
            }

            $reservation = $this->firstHierarchyReservation($parent, $except);
            if ($reservation === null) {
                continue;
            }

            throw new InvalidArgumentException(
                $reservation['block']->message.' Reserved EPC: '.$this->epcLabel($reservation['epc']).'.',
            );
        }
    }

    public function epcLabel(Epc $epc): string
    {
        if (filled($epc->gtin14)) {
            return (string) $epc->gtin14.(filled($epc->serial_number) ? ' / '.$epc->serial_number : '');
        }

        if (filled($epc->sscc18)) {
            return (string) $epc->sscc18;
        }

        return (string) $epc->epc_uri;
    }

    public function existsOnAnyExclusiveSession(Epc $epc): bool
    {
        return $this->check($epc, ExclusiveSessionContext::none()) !== null;
    }

    private function checkDirect(int $epcId, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        return $this->checkDirectMany([$epcId], $except);
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function checkDirectMany(array $epcIds, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        $epcIds = array_values(array_unique(array_map('intval', $epcIds)));
        if ($epcIds === []) {
            return null;
        }

        return $this->checkReceivingMany($epcIds, $except)
            ?? $this->checkShippingMany($epcIds, $except)
            ?? $this->checkTransferringMany($epcIds, $except)
            ?? $this->checkPackingMany($epcIds, $except)
            ?? $this->checkDispositionMany($epcIds, $except);
    }

    /**
     * @return list<int>
     */
    private function openAncestorIds(int $epcId): array
    {
        $currentId = $epcId;
        $seen = [$currentId => true];
        $ancestors = [];

        for ($depth = 0; $depth < self::HIERARCHY_WALK_LIMIT; $depth++) {
            $parentId = AggregationLink::query()
                ->open()
                ->where('child_epc_id', $currentId)
                ->value('parent_epc_id');

            if ($parentId === null) {
                return $ancestors;
            }

            $parentId = (int) $parentId;
            if (isset($seen[$parentId])) {
                return $ancestors;
            }
            $seen[$parentId] = true;
            $ancestors[] = $parentId;
            $currentId = $parentId;
        }

        return $ancestors;
    }

    /**
     * @return list<int>
     */
    private function openDescendantIds(int $epcId): array
    {
        $frontier = [$epcId];
        $seen = [$epcId => true];
        $descendants = [];

        for ($depth = 0; $depth < self::HIERARCHY_WALK_LIMIT && $frontier !== []; $depth++) {
            $childIds = AggregationLink::query()
                ->open()
                ->whereIn('parent_epc_id', $frontier)
                ->pluck('child_epc_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $next = [];
            foreach ($childIds as $childId) {
                if (isset($seen[$childId])) {
                    continue;
                }
                $seen[$childId] = true;
                $next[] = $childId;
                $descendants[] = $childId;
            }

            $frontier = $next;
        }

        return $descendants;
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function checkReceivingMany(array $epcIds, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        $line = ReceivingScanLine::query()
            ->whereIn('epc_id', $epcIds)
            ->whereIn('status', self::RECEIVING_RESERVATION_STATUSES)
            ->whereHas('session', function ($query) use ($except): void {
                $this->applyReceivingExclusiveScope($query);
                if ($except->receiving !== null) {
                    $query->whereKeyNot($except->receiving->getKey());
                }
            })
            ->with(['session' => fn ($q) => $q->select(['id', 'status', 'session_kind', 'opened_at'])])
            ->orderByDesc('id')
            ->first();

        if ($line === null || $line->session === null) {
            return null;
        }

        $sessionId = (int) $line->session->getKey();
        $crossWorkflow = $except->shipping !== null
            || $except->transferring !== null
            || $except->packing !== null
            || $except->disposition !== null;

        return new EpcExclusiveBlock(
            FloorSessionType::Receiving,
            $sessionId,
            $crossWorkflow ? 'on_open_receive' : 'double_receive',
            $this->messageWithSession(
                $crossWorkflow
                    ? 'Already confirmed on an open receive session'
                    : 'Already confirmed on another open receive session',
                $sessionId,
            ),
        );
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function checkShippingMany(array $epcIds, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        $line = OutboundShippingScanLine::query()
            ->whereIn('epc_id', $epcIds)
            ->where('status', 'confirmed')
            ->whereHas('session', function ($query) use ($except): void {
                $this->applyShippingExclusiveScope($query);
                if ($except->shipping !== null) {
                    $query->whereKeyNot($except->shipping->getKey());
                }
            })
            ->with(['session' => fn ($q) => $q->select(['id', 'status'])])
            ->orderByDesc('id')
            ->first();

        if ($line === null || $line->session === null) {
            return null;
        }

        $sessionId = (int) $line->session->getKey();

        return new EpcExclusiveBlock(
            FloorSessionType::Shipping,
            $sessionId,
            'on_open_ship',
            $this->messageWithSession('Already on another open ship order', $sessionId),
        );
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function checkTransferringMany(array $epcIds, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        $line = TransferringScanLine::query()
            ->whereIn('epc_id', $epcIds)
            ->whereIn('status', ['confirmed', 'received'])
            ->whereHas('session', function ($query) use ($except): void {
                $this->applyTransferringExclusiveScope($query);
                if ($except->transferring !== null) {
                    $query->whereKeyNot($except->transferring->getKey());
                }
            })
            ->with(['session' => fn ($q) => $q->select(['id', 'status'])])
            ->orderByDesc('id')
            ->first();

        if ($line === null || $line->session === null) {
            return null;
        }

        $sessionId = (int) $line->session->getKey();

        return new EpcExclusiveBlock(
            FloorSessionType::Transferring,
            $sessionId,
            'double_transfer',
            $this->messageWithSession('Already on another open transfer session', $sessionId),
        );
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function checkPackingMany(array $epcIds, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        $line = PackingScanLine::query()
            ->whereIn('epc_id', $epcIds)
            ->whereIn('status', self::PACKING_RESERVATION_STATUSES)
            ->whereHas('session', function ($query) use ($except): void {
                $this->applyPackingExclusiveScope($query);
                if ($except->packing !== null) {
                    $query->whereKeyNot($except->packing->getKey());
                }
            })
            ->with(['session' => fn ($q) => $q->select(['id', 'status', 'session_kind'])])
            ->orderByDesc('id')
            ->first();

        if ($line !== null && $line->session !== null) {
            $sessionId = (int) $line->session->getKey();

            return new EpcExclusiveBlock(
                FloorSessionType::Packing,
                $sessionId,
                'on_open_pack',
                $this->messageWithSession('Already on an open pack session', $sessionId),
            );
        }

        $containerIds = Epc::query()
            ->whereIn('id', $epcIds)
            ->where('epc_type', 'sscc')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($containerIds === []) {
            return null;
        }

        $session = PackingSession::query()
            ->whereIn('parent_epc_id', $containerIds)
            ->where(function ($query): void {
                $this->applyPackingExclusiveScope($query);
            })
            ->when(
                $except->packing !== null,
                fn ($query) => $query->whereKeyNot($except->packing->getKey()),
            )
            ->orderByDesc('id')
            ->first();

        if ($session === null) {
            return null;
        }

        $sessionId = (int) $session->getKey();

        return new EpcExclusiveBlock(
            FloorSessionType::Packing,
            $sessionId,
            'on_open_pack',
            $this->messageWithSession('Already on an open pack session', $sessionId),
        );
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function checkDispositionMany(array $epcIds, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        $line = DispositionScanLine::query()
            ->whereIn('epc_id', $epcIds)
            ->whereIn('status', self::DISPOSITION_RESERVATION_STATUSES)
            ->whereHas('session', function ($query) use ($except): void {
                $this->applyDispositionExclusiveScope($query);
                if ($except->disposition !== null) {
                    $query->whereKeyNot($except->disposition->getKey());
                }
            })
            ->with(['session' => fn ($q) => $q->select(['id', 'status', 'biz_step'])])
            ->orderByDesc('id')
            ->first();

        if ($line === null || $line->session === null) {
            return null;
        }

        $sessionId = (int) $line->session->getKey();

        return new EpcExclusiveBlock(
            FloorSessionType::Disposition,
            $sessionId,
            'on_open_disposition',
            $this->messageWithSession('Already on an open disposition session', $sessionId),
        );
    }

    private function messageWithSession(string $base, int $sessionId): string
    {
        return $base.' (#'.$sessionId.').';
    }

    /**
     * @param  Builder<ReceivingSession>  $query
     */
    private function applyReceivingExclusiveScope($query): void
    {
        $query->where(function ($exclusive): void {
            $exclusive
                ->whereIn('status', ['open', 'in_progress'])
                ->orWhere(function ($pendingGenerate): void {
                    $pendingGenerate
                        ->where('status', 'completed')
                        ->whereNull('receiving_events_generated_at');
                });
        });
    }

    /**
     * @param  Builder<OutboundShippingSession>  $query
     */
    private function applyShippingExclusiveScope($query): void
    {
        $query->where(function ($inner): void {
            $inner
                ->whereIn('status', ['open', 'in_progress'])
                ->orWhere(function ($completed): void {
                    $completed
                        ->where('status', 'completed')
                        ->whereNull('shipping_events_generated_at');
                });
        });
    }

    /**
     * @param  Builder<TransferringSession>  $query
     */
    private function applyTransferringExclusiveScope($query): void
    {
        $query->where(function ($inner): void {
            $inner
                ->whereIn('status', ['open', 'in_transit'])
                ->orWhere(function ($pendingReceiveEpcis): void {
                    $pendingReceiveEpcis
                        ->where('status', 'completed')
                        ->whereNull('receive_events_generated_at');
                });
        });
    }

    /**
     * @param  Builder<PackingSession>  $query
     */
    private function applyPackingExclusiveScope($query): void
    {
        $query->where(function ($inner): void {
            $inner
                ->where('status', 'open')
                ->orWhere(function ($pending): void {
                    $pending
                        ->where('status', 'completed')
                        ->whereNull('packing_events_generated_at');
                });
        });
    }

    /**
     * @param  Builder<DispositionSession>  $query
     */
    private function applyDispositionExclusiveScope($query): void
    {
        $query->where(function ($inner): void {
            $inner
                ->where('status', 'open')
                ->orWhere(function ($pending): void {
                    $pending
                        ->where('status', 'completed')
                        ->whereNull('disposition_events_generated_at');
                });
        });
    }
}
