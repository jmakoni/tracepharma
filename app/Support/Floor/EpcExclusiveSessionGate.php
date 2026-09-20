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

        return $this->checkDirect($epcId, $except)
            ?? $this->checkReservedOpenAncestors($epcId, $except)
            ?? $this->checkReservedOpenDescendants($epcId, $except);
    }

    public function existsOnAnyExclusiveSession(Epc $epc): bool
    {
        return $this->check($epc, ExclusiveSessionContext::none()) !== null;
    }

    private function checkDirect(int $epcId, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        return $this->checkReceiving($epcId, $except)
            ?? $this->checkShipping($epcId, $except)
            ?? $this->checkTransferring($epcId, $except)
            ?? $this->checkPacking($epcId, $except)
            ?? $this->checkDisposition($epcId, $except);
    }

    private function checkReservedOpenAncestors(int $epcId, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        $currentId = $epcId;
        $seen = [$currentId => true];

        for ($depth = 0; $depth < self::HIERARCHY_WALK_LIMIT; $depth++) {
            $parentId = AggregationLink::query()
                ->open()
                ->where('child_epc_id', $currentId)
                ->value('parent_epc_id');

            if ($parentId === null) {
                return null;
            }

            $parentId = (int) $parentId;
            if (isset($seen[$parentId])) {
                return null;
            }
            $seen[$parentId] = true;

            $block = $this->checkDirect($parentId, $except);
            if ($block !== null) {
                return $block;
            }

            $currentId = $parentId;
        }

        return null;
    }

    private function checkReservedOpenDescendants(int $epcId, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        $frontier = [$epcId];
        $seen = [$epcId => true];

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
                $block = $this->checkDirect($childId, $except);
                if ($block !== null) {
                    return $block;
                }
                $next[] = $childId;
            }

            $frontier = $next;
        }

        return null;
    }

    private function checkReceiving(int $epcId, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        $line = ReceivingScanLine::query()
            ->where('epc_id', $epcId)
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

    private function checkShipping(int $epcId, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        $line = OutboundShippingScanLine::query()
            ->where('epc_id', $epcId)
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

    private function checkTransferring(int $epcId, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        $line = TransferringScanLine::query()
            ->where('epc_id', $epcId)
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

    private function checkPacking(int $epcId, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        $line = PackingScanLine::query()
            ->where('epc_id', $epcId)
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

        $session = PackingSession::query()
            ->where('parent_epc_id', $epcId)
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

    private function checkDisposition(int $epcId, ExclusiveSessionContext $except): ?EpcExclusiveBlock
    {
        $line = DispositionScanLine::query()
            ->where('epc_id', $epcId)
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
