<?php

declare(strict_types=1);

namespace App\Support\EpcisJobs;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use Illuminate\Support\Facades\DB;

final class EpcisJobStats
{
    /**
     * @return array{
     *     total_events: int,
     *     serial_numbers: int,
     *     aggregation_events: int,
     *     object_events: int,
     *     transaction_events: int,
     *     quantity_events: int,
     *     processing_time_ms: int|null
     * }
     */
    public function forDocument(EpcisDocument $document, ?int $processingTimeMs = null): array
    {
        $counts = EpcisEvent::query()
            ->where('document_id', $document->getKey())
            ->select('event_type', DB::raw('count(*) as c'))
            ->groupBy('event_type')
            ->pluck('c', 'event_type');

        $byType = static fn (string $type): int => (int) ($counts[$type] ?? $counts[strtolower($type)] ?? 0);

        return [
            'total_events' => (int) $document->event_count,
            'serial_numbers' => (int) $document->epc_count,
            'aggregation_events' => $byType('AggregationEvent'),
            'object_events' => $byType('ObjectEvent'),
            'transaction_events' => $byType('TransactionEvent'),
            'quantity_events' => $byType('QuantityEvent'),
            'processing_time_ms' => $processingTimeMs,
        ];
    }
}
