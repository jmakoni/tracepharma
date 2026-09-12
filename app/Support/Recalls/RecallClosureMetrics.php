<?php

declare(strict_types=1);

namespace App\Support\Recalls;

use App\Enums\TracingRequestNotificationStatus;
use App\Enums\TracingRequestStatus;
use App\Filament\App\Pages\SiteRecallReconciliation;
use App\Filament\App\Resources\TracingRequests\TracingRequestResource;
use App\Models\TracingRequest;
use App\Support\Receiving\EligibleReceiveSites;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Lean recall closure packaging: partner ack % + unreconciled on-hand hits.
 */
final class RecallClosureMetrics
{
    /**
     * @return list<array{
     *     id: int,
     *     title: string,
     *     status: string,
     *     ack_percent: int|null,
     *     ack_label: string,
     *     unreconciled: int,
     *     unreconciled_truncated: bool,
     *     href: string|null,
     *     site_recall_href: string|null
     * }>
     */
    public function rows(): array
    {
        /** @var Collection<int, TracingRequest> $recalls */
        $recalls = TracingRequest::query()
            ->where('is_recall', true)
            ->whereIn('status', [TracingRequestStatus::Open, TracingRequestStatus::InProgress])
            ->with(['notifications' => fn ($q) => $q->select([
                'id',
                'tracing_request_id',
                'status',
                'acknowledged_at',
            ])])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $siteIds = EligibleReceiveSites::forOrganization()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $recalls->map(function (TracingRequest $request) use ($siteIds): array {
            $ack = $this->ackStats($request);
            $unreconciled = $this->unreconciledStats($request, $siteIds);

            return [
                'id' => (int) $request->getKey(),
                'title' => (string) ($request->title ?: 'Recall #'.$request->getKey()),
                'status' => $request->status?->label() ?? '—',
                'ack_percent' => $ack['percent'],
                'ack_label' => $ack['label'],
                'unreconciled' => $unreconciled['count'],
                'unreconciled_truncated' => $unreconciled['truncated'],
                'href' => $this->tracingUrl($request),
                'site_recall_href' => $this->siteRecallUrl(),
            ];
        })->all();
    }

    /**
     * @return array{percent: int|null, label: string}
     */
    private function ackStats(TracingRequest $request): array
    {
        $notified = $request->notifications->filter(
            fn ($n): bool => in_array(
                $n->status,
                [
                    TracingRequestNotificationStatus::Sent,
                    TracingRequestNotificationStatus::Failed,
                    TracingRequestNotificationStatus::Acknowledged,
                ],
                true,
            ),
        );

        $total = $notified->count();
        if ($total === 0) {
            return ['percent' => null, 'label' => 'No broadcasts sent'];
        }

        $acked = $notified->filter(
            fn ($n): bool => $n->status === TracingRequestNotificationStatus::Acknowledged
                || $n->acknowledged_at !== null,
        )->count();

        $percent = (int) round(($acked / $total) * 100);

        return [
            'percent' => $percent,
            'label' => "{$acked}/{$total} acknowledged ({$percent}%)",
        ];
    }

    /**
     * @param  list<int>  $siteIds
     * @return array{count: int, truncated: bool}
     */
    private function unreconciledStats(TracingRequest $request, array $siteIds): array
    {
        if ($siteIds === [] || blank($request->gtin)) {
            return ['count' => 0, 'truncated' => false];
        }

        $meta = is_array($request->response_metadata) ? $request->response_metadata : [];
        $hits = app(OpenRecallHits::class);
        $cap = OpenRecallHits::DISPLAY_CAP;
        $unreconciled = 0;
        $truncated = false;

        foreach ($siteIds as $siteId) {
            if ($hits->isTruncated($siteId, $cap)) {
                $truncated = true;
            }

            $accounted = array_map('intval', $meta['reconciled']['site_'.$siteId] ?? []);
            foreach ($hits->epcsAtSite($siteId, $cap) as $epc) {
                if (! $this->epcMatchesRecall($epc, $request)) {
                    continue;
                }
                if (! in_array((int) $epc->getKey(), $accounted, true)) {
                    $unreconciled++;
                }
            }
        }

        return ['count' => $unreconciled, 'truncated' => $truncated];
    }

    private function epcMatchesRecall(\App\Models\Epcis\Epc $epc, TracingRequest $request): bool
    {
        if ((string) $epc->gtin14 !== (string) $request->gtin) {
            return false;
        }

        if (filled($request->serial) && (string) $epc->serial_number !== (string) $request->serial) {
            return false;
        }

        if (filled($request->lot)) {
            $lot = $epc->ilmd?->lot_number;
            if ((string) $lot !== (string) $request->lot) {
                return false;
            }
        }

        return true;
    }

    private function tracingUrl(TracingRequest $request): ?string
    {
        try {
            return TracingRequestResource::getUrl('view', ['record' => $request], panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }

    private function siteRecallUrl(): ?string
    {
        try {
            if (! SiteRecallReconciliation::canAccess()) {
                return null;
            }

            return SiteRecallReconciliation::getUrl(panel: 'app');
        } catch (Throwable) {
            return null;
        }
    }
}
