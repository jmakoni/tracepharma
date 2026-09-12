<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Enums\SsccLabelBatchStatus;
use App\Models\Epcis\Epc;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hours from SSCC labeling / commission-source to L4 commissioning event_time.
 */
final class L3L4IngestLag
{
    /**
     * @return array{
     *     batch_id: int,
     *     source_at: string,
     *     l4_event_time: string,
     *     lag_seconds: int,
     *     lag_hours: float,
     *     sla_hours: int,
     *     over_sla: bool
     * }|null
     */
    public function forBatch(SsccLabelBatch $batch): ?array
    {
        $source = $batch->commissioned_at ?? $batch->printed_at;
        if ($source === null) {
            return null;
        }

        $epcIds = $this->resolveEpcIds($batch->labels()->get(['sscc_18', 'sscc_urn']));
        $l4At = $this->minCommissioningEventTime($epcIds);
        if ($l4At === null) {
            return null;
        }

        $sourceUtc = Carbon::parse($source)->utc();
        $l4Utc = $l4At->copy()->utc();
        $lagSeconds = max(0, $l4Utc->getTimestamp() - $sourceUtc->getTimestamp());
        $lagHours = round($lagSeconds / 3600, 2);
        $slaHours = $this->slaHours();

        return [
            'batch_id' => (int) $batch->getKey(),
            'source_at' => $sourceUtc->toDateTimeString(),
            'l4_event_time' => $l4Utc->toDateTimeString(),
            'lag_seconds' => $lagSeconds,
            'lag_hours' => $lagHours,
            'sla_hours' => $slaHours,
            'over_sla' => $lagHours > $slaHours,
        ];
    }

    /**
     * @return array{
     *     summary: array{
     *         batches: int,
     *         avg_lag_hours: ?float,
     *         max_lag_hours: ?float,
     *         over_sla_count: int,
     *         sla_hours: int
     *     },
     *     rows: list<array<string, mixed>>
     * }
     */
    public function forRange(Carbon $since, int $limit): array
    {
        $slaHours = $this->slaHours();
        $empty = [
            'summary' => [
                'batches' => 0,
                'avg_lag_hours' => null,
                'max_lag_hours' => null,
                'over_sla_count' => 0,
                'sla_hours' => $slaHours,
            ],
            'rows' => [],
        ];

        if (! Schema::hasTable('sscc_label_batches') || ! Schema::hasTable('epcis_events')) {
            return $empty;
        }

        $rows = $this->rowsInRange($since);

        if ($rows->isEmpty()) {
            return $empty;
        }

        $lagHours = $rows->pluck('lag_hours')->map(fn (mixed $hours): float => (float) $hours);

        return [
            'summary' => [
                'batches' => $rows->count(),
                'avg_lag_hours' => round((float) $lagHours->avg(), 2),
                'max_lag_hours' => round((float) $lagHours->max(), 2),
                'over_sla_count' => $rows->where('over_sla', true)->count(),
                'sla_hours' => $slaHours,
            ],
            'rows' => $rows
                ->sortByDesc(fn (array $row): float => (float) $row['lag_hours'])
                ->take(max(1, $limit))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, array{
     *     batch_id: int,
     *     source_at: string,
     *     l4_event_time: string,
     *     lag_seconds: int,
     *     lag_hours: float,
     *     sla_hours: int,
     *     over_sla: bool
     * }>
     */
    private function rowsInRange(Carbon $since): Collection
    {
        if (! Schema::hasTable('sscc_labels') || ! Schema::hasTable('event_epcs') || ! Schema::hasTable('epcs')) {
            return collect();
        }

        $slaHours = $this->slaHours();

        $batchIds = SsccLabelBatch::query()
            ->where('status', SsccLabelBatchStatus::Completed)
            ->whereRaw('COALESCE(commissioned_at, printed_at) >= ?', [$since->toDateTimeString()])
            ->pluck('id');

        if ($batchIds->isEmpty()) {
            return collect();
        }

        $raw = DB::table('sscc_labels as l')
            ->join('sscc_label_batches as b', 'b.id', '=', 'l.batch_id')
            ->join('epcs as e', 'e.epc_uri', '=', 'l.sscc_urn')
            ->join('event_epcs as ee', 'ee.epc_id', '=', 'e.id')
            ->join('epcis_events as ev', 'ev.id', '=', 'ee.event_id')
            ->whereIn('l.batch_id', $batchIds->all())
            ->where('e.epc_type', 'sscc')
            ->where('ev.event_type', 'ObjectEvent')
            ->where('ev.action', 'ADD')
            ->where(function ($query): void {
                $query->where('ev.biz_step', 'urn:epcglobal:cbv:bizstep:commissioning')
                    ->orWhere('ev.biz_step', 'commissioning')
                    ->orWhere('ev.biz_step', 'like', '%:commissioning');
            })
            ->groupBy('b.id', 'b.commissioned_at', 'b.printed_at')
            ->orderByDesc(DB::raw('COALESCE(b.commissioned_at, b.printed_at)'))
            ->get([
                'b.id as batch_id',
                DB::raw('COALESCE(b.commissioned_at, b.printed_at) as source_at'),
                DB::raw('MIN(ev.event_time) as l4_event_time'),
            ]);

        return $raw
            ->map(function (object $row) use ($slaHours): ?array {
                if (! filled($row->source_at) || ! filled($row->l4_event_time)) {
                    return null;
                }

                $sourceUtc = Carbon::parse((string) $row->source_at)->utc();
                $l4Utc = Carbon::parse((string) $row->l4_event_time)->utc();
                $lagSeconds = max(0, $l4Utc->getTimestamp() - $sourceUtc->getTimestamp());
                $lagHours = round($lagSeconds / 3600, 2);

                return [
                    'batch_id' => (int) $row->batch_id,
                    'source_at' => $sourceUtc->toDateTimeString(),
                    'l4_event_time' => $l4Utc->toDateTimeString(),
                    'lag_seconds' => $lagSeconds,
                    'lag_hours' => $lagHours,
                    'sla_hours' => $slaHours,
                    'over_sla' => $lagHours > $slaHours,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, SsccLabel>  $labels
     * @return list<int>
     */
    private function resolveEpcIds(Collection $labels): array
    {
        $urns = $labels->pluck('sscc_urn')->filter(fn ($value): bool => filled($value))->unique()->values()->all();
        $sscc18s = $labels->pluck('sscc_18')->filter(fn ($value): bool => filled($value))->unique()->values()->all();

        if ($urns === [] && $sscc18s === []) {
            return [];
        }

        return Epc::query()
            ->where('epc_type', 'sscc')
            ->where(function ($query) use ($urns, $sscc18s): void {
                if ($urns !== []) {
                    $query->orWhereIn('epc_uri', $urns);
                }
                if ($sscc18s !== []) {
                    $query->orWhereIn('sscc18', $sscc18s);
                }
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $epcIds
     */
    private function minCommissioningEventTime(array $epcIds): ?Carbon
    {
        if ($epcIds === [] || ! Schema::hasTable('event_epcs')) {
            return null;
        }

        $value = DB::table('event_epcs as ee')
            ->join('epcis_events as ev', 'ev.id', '=', 'ee.event_id')
            ->whereIn('ee.epc_id', $epcIds)
            ->where('ev.event_type', 'ObjectEvent')
            ->where('ev.action', 'ADD')
            ->where(function ($query): void {
                $query->where('ev.biz_step', 'urn:epcglobal:cbv:bizstep:commissioning')
                    ->orWhere('ev.biz_step', 'commissioning')
                    ->orWhere('ev.biz_step', 'like', '%:commissioning');
            })
            ->min('ev.event_time');

        return filled($value) ? Carbon::parse((string) $value) : null;
    }

    private function slaHours(): int
    {
        return max(1, (int) config('tracepharma.l3_l4_ingest.sla_hours', 4));
    }
}
