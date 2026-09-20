<?php

namespace App\Support\Floor;

use App\Models\Disposition\DispositionSession;
use App\Models\Packing\PackingSession;
use App\Models\Receiving\ReceivingSession;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\Transferring\TransferringSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Unsubmitted floor sessions for Operations Hub and mobile open-work lists.
 */
final class OpenFloorWork
{
    /**
     * @return Collection<int, array{type: string, id: int, label: string, detail: string, url: ?string, opened_at: ?Carbon}>
     */
    public static function itemsForSite(int $siteId, int $limit = 10): Collection
    {
        $resolver = app(ResolveOpenFloorSessionUrl::class);
        $items = collect();

        foreach (ReceivingSession::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->where('site_id', $siteId)
            ->orderByDesc('opened_at')
            ->limit($limit)
            ->get(['id', 'session_kind', 'opened_at']) as $session) {
            $items->push([
                'type' => FloorSessionType::Receiving->value,
                'id' => (int) $session->getKey(),
                'label' => 'Receive',
                'detail' => $session->session_kind?->badgeLabel() ?? 'Receive',
                'url' => $resolver->url(FloorSessionType::Receiving, (int) $session->getKey()),
                'opened_at' => $session->opened_at,
            ]);
        }

        foreach (OutboundShippingSession::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->where('site_id', $siteId)
            ->orderByDesc('opened_at')
            ->limit($limit)
            ->get(['id', 'opened_at']) as $session) {
            $items->push([
                'type' => FloorSessionType::Shipping->value,
                'id' => (int) $session->getKey(),
                'label' => 'Ship',
                'detail' => 'Ship order #'.$session->getKey(),
                'url' => $resolver->url(FloorSessionType::Shipping, (int) $session->getKey()),
                'opened_at' => $session->opened_at,
            ]);
        }

        foreach (TransferringSession::query()
            ->whereIn('status', ['open', 'in_transit'])
            ->where(function ($query) use ($siteId): void {
                $query->where('from_site_id', $siteId)
                    ->orWhere('to_site_id', $siteId);
            })
            ->orderByDesc('opened_at')
            ->limit($limit)
            ->get(['id', 'opened_at']) as $session) {
            $items->push([
                'type' => FloorSessionType::Transferring->value,
                'id' => (int) $session->getKey(),
                'label' => 'Transfer',
                'detail' => 'Transfer #'.$session->getKey(),
                'url' => $resolver->url(FloorSessionType::Transferring, (int) $session->getKey()),
                'opened_at' => $session->opened_at,
            ]);
        }

        foreach (PackingSession::query()
            ->where('status', 'open')
            ->where('site_id', $siteId)
            ->orderByDesc('opened_at')
            ->limit($limit)
            ->get(['id', 'session_kind', 'opened_at']) as $session) {
            $items->push([
                'type' => FloorSessionType::Packing->value,
                'id' => (int) $session->getKey(),
                'label' => $session->session_kind?->label() ?? 'Pack',
                'detail' => 'Session #'.$session->getKey(),
                'url' => $resolver->url(FloorSessionType::Packing, (int) $session->getKey()),
                'opened_at' => $session->opened_at,
            ]);
        }

        foreach (DispositionSession::query()
            ->where('status', 'open')
            ->where('site_id', $siteId)
            ->orderByDesc('opened_at')
            ->limit($limit)
            ->get(['id', 'biz_step', 'opened_at']) as $session) {
            $items->push([
                'type' => FloorSessionType::Disposition->value,
                'id' => (int) $session->getKey(),
                'label' => match ($session->biz_step) {
                    'returning' => 'Return',
                    'saleable_return' => 'Saleable return',
                    default => 'Decommission',
                },
                'detail' => 'Session #'.$session->getKey(),
                'url' => $resolver->url(FloorSessionType::Disposition, (int) $session->getKey()),
                'opened_at' => $session->opened_at,
            ]);
        }

        return $items
            ->sortByDesc(fn (array $item): int => $item['opened_at']?->getTimestamp() ?? 0)
            ->take($limit)
            ->values();
    }
}
