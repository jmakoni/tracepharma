<?php

declare(strict_types=1);

namespace App\Support\Dashboard;

use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisEvent;
use App\Models\Epcis\TransmissionMdn;
use App\Models\Exceptions\ExceptionCase;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class LeadershipDscsaPackMetrics
{
    public const DRILL_LIMIT = 100;

    public function __construct(
        private readonly User $user,
        private readonly string $range = 'mtd',
    ) {}

    public static function make(User $user, string $range = 'mtd'): self
    {
        return new self($user, self::normalizeRange($range));
    }

    /**
     * @return array<string, array{summary: array<string, mixed>, rows: list<array<string, mixed>>}>
     */
    public function all(): array
    {
        return [
            'transmit_success' => $this->transmitSuccess(),
            'mdn_success' => $this->mdnSuccess(),
            'late_missing_mdn' => $this->lateMissingMdn(),
            'decommission_by_reason' => $this->decommissionByReason(),
            'stuck_serials' => $this->stuckSerialsByStatus(),
            'open_exceptions_by_code' => $this->openExceptionsByCode(),
            'l3_l4_ingest_lag' => $this->l3L4IngestLag(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exportRows(): array
    {
        $rows = [];

        foreach ($this->all() as $metric => $payload) {
            foreach ($payload['rows'] as $row) {
                $rows[] = array_merge(['metric' => $metric], $row);
            }
        }

        return $rows;
    }

    /**
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function transmitSuccess(): array
    {
        $aggregates = $this->outboundTransmitBaseQuery()
            ->whereIn('transmission_status', ['sent', 'failed'])
            ->selectRaw('transmission_status, COUNT(*) as aggregate')
            ->groupBy('transmission_status')
            ->pluck('aggregate', 'transmission_status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $sent = $aggregates['sent'] ?? 0;
        $failed = $aggregates['failed'] ?? 0;
        $totalScored = $sent + $failed;

        $drillRows = $this->outboundTransmitBaseQuery()
            ->whereIn('transmission_status', ['sent', 'failed'])
            ->leftJoin('trading_partners', 'trading_partners.id', '=', 'epcis_documents.trading_partner_id')
            ->orderByDesc(DB::raw('COALESCE(epcis_documents.sent_at, epcis_documents.creation_date)'))
            ->limit($this->drillLimit())
            ->get([
                'epcis_documents.id as document_id',
                'epcis_documents.trading_partner_id',
                'trading_partners.name as partner_name',
                'epcis_documents.transmission_status',
                'epcis_documents.sent_at',
            ])
            ->map(fn (object $row): array => [
                'document_id' => (int) $row->document_id,
                'trading_partner_id' => $row->trading_partner_id !== null ? (int) $row->trading_partner_id : null,
                'partner_name' => filled($row->partner_name) ? (string) $row->partner_name : null,
                'transmission_status' => (string) $row->transmission_status,
                'sent_at' => $row->sent_at !== null ? (string) $row->sent_at : null,
            ])
            ->all();

        return [
            'summary' => [
                'sent' => $sent,
                'failed' => $failed,
                'total_scored' => $totalScored,
                'percent' => $totalScored > 0 ? round(($sent / $totalScored) * 100, 1) : null,
            ],
            'rows' => $drillRows,
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function mdnSuccess(): array
    {
        $aggregates = $this->mdnWindowBaseQuery()
            ->whereIn('mdn_status', ['received', 'failed'])
            ->selectRaw('mdn_status, COUNT(*) as aggregate')
            ->groupBy('mdn_status')
            ->pluck('aggregate', 'mdn_status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $received = $aggregates['received'] ?? 0;
        $failed = $aggregates['failed'] ?? 0;
        $totalScored = $received + $failed;

        $drillRows = $this->mdnWindowBaseQuery()
            ->whereIn('mdn_status', ['received', 'failed'])
            ->orderByDesc('created_at')
            ->limit($this->drillLimit())
            ->get(['id', 'document_id', 'trading_partner_id', 'mdn_status'])
            ->map(fn (TransmissionMdn $mdn): array => [
                'mdn_id' => (int) $mdn->getKey(),
                'document_id' => $mdn->document_id !== null ? (int) $mdn->document_id : null,
                'trading_partner_id' => $mdn->trading_partner_id !== null ? (int) $mdn->trading_partner_id : null,
                'mdn_status' => (string) $mdn->mdn_status,
            ])
            ->all();

        return [
            'summary' => [
                'received' => $received,
                'failed' => $failed,
                'total_scored' => $totalScored,
                'percent' => $totalScored > 0 ? round(($received / $totalScored) * 100, 1) : null,
            ],
            'rows' => $drillRows,
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function lateMissingMdn(): array
    {
        [$missingBefore, $lateBefore] = $this->mdnSlaCutoffs();

        $missingPending = (int) TransmissionMdn::query()
            ->where('mdn_status', 'pending')
            ->where('created_at', '<=', $missingBefore)
            ->where('created_at', '>', $lateBefore)
            ->count();

        $latePending = (int) TransmissionMdn::query()
            ->where('mdn_status', 'pending')
            ->where('created_at', '<=', $lateBefore)
            ->count();

        $openMissingCases = $this->openMdnExceptionCaseCount('MISSING_MDN');
        $openLateCases = $this->openMdnExceptionCaseCount('LATE_MDN');

        $rows = [];

        $pendingMdns = TransmissionMdn::query()
            ->where('mdn_status', 'pending')
            ->where('created_at', '<=', $missingBefore)
            ->with(['tradingPartner:id,name', 'document:id'])
            ->orderBy('created_at')
            ->limit($this->drillLimit())
            ->get();

        foreach ($pendingMdns as $mdn) {
            $isLate = $mdn->created_at !== null && $mdn->created_at->lessThanOrEqualTo($lateBefore);

            $rows[] = [
                'row_type' => 'pending_mdn',
                'mdn_id' => (int) $mdn->getKey(),
                'document_id' => $mdn->document_id !== null ? (int) $mdn->document_id : null,
                'trading_partner_id' => $mdn->trading_partner_id !== null ? (int) $mdn->trading_partner_id : null,
                'partner_name' => $mdn->tradingPartner?->name,
                'mdn_status' => (string) $mdn->mdn_status,
                'age_bucket' => $isLate ? 'late' : 'missing',
                'created_at' => $mdn->created_at?->toIso8601String(),
            ];
        }

        $remaining = max(0, $this->drillLimit() - count($rows));

        if ($remaining > 0) {
            $caseRows = $this->openMdnExceptionCasesQuery(['MISSING_MDN', 'LATE_MDN'])
                ->with('tradingPartner:id,name')
                ->orderByDesc('exceptions.created_at')
                ->limit($remaining)
                ->get([
                    'exceptions.id',
                    'exceptions.trading_partner_id',
                    'exceptions.severity',
                    'exception_types.code',
                ]);

            foreach ($caseRows as $case) {
                $rows[] = [
                    'row_type' => 'exception_case',
                    'case_id' => (int) $case->getKey(),
                    'code' => (string) $case->code,
                    'trading_partner_id' => $case->trading_partner_id !== null ? (int) $case->trading_partner_id : null,
                    'partner_name' => $case->tradingPartner?->name,
                    'severity' => $this->stringifySeverity($case->severity),
                ];
            }
        }

        return [
            'summary' => [
                'missing_mdn_pending' => $missingPending,
                'late_mdn_pending' => $latePending,
                'open_missing_mdn_cases' => $openMissingCases,
                'open_late_mdn_cases' => $openLateCases,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function decommissionByReason(): array
    {
        $reasonExpression = $this->decommissionReasonExpression();

        $reasonCounts = $this->decommissionEventsBaseQuery()
            ->selectRaw("{$reasonExpression} as reason, COUNT(*) as aggregate")
            ->groupBy('reason')
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn (object $row): array => [
                'reason' => (string) $row->reason,
                'count' => (int) $row->aggregate,
            ])
            ->all();

        $firstGtinSubquery = DB::table('event_epcs')
            ->join('epcs', 'epcs.id', '=', 'event_epcs.epc_id')
            ->selectRaw('event_epcs.event_id, MIN(epcs.gtin14) as gtin14')
            ->groupBy('event_epcs.event_id');

        $drillRows = $this->decommissionEventsBaseQuery()
            ->leftJoinSub($firstGtinSubquery, 'first_epc', 'first_epc.event_id', '=', 'epcis_events.id')
            ->select([
                'epcis_events.id',
                'epcis_events.event_id',
                'epcis_events.trading_partner_id',
                'first_epc.gtin14',
            ])
            ->selectRaw("{$reasonExpression} as reason")
            ->orderByDesc('epcis_events.event_time')
            ->limit($this->drillLimit())
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'event_id' => (string) $row->event_id,
                'trading_partner_id' => $row->trading_partner_id !== null ? (int) $row->trading_partner_id : null,
                'gtin14' => filled($row->gtin14) ? (string) $row->gtin14 : null,
                'reason' => (string) $row->reason,
            ])
            ->all();

        return [
            'summary' => [
                'reasons' => $reasonCounts,
                'total' => array_sum(array_column($reasonCounts, 'count')),
            ],
            'rows' => $drillRows,
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function stuckSerialsByStatus(): array
    {
        $byStatus = ExceptionCase::query()
            ->overdue()
            ->join('exception_epcs', 'exception_epcs.exception_id', '=', 'exceptions.id')
            ->selectRaw('exceptions.status, COUNT(DISTINCT exception_epcs.epc_id) as epc_count')
            ->groupBy('exceptions.status')
            ->orderByDesc('epc_count')
            ->get()
            ->map(fn (object $row): array => [
                'status' => $this->stringifySeverity($row->status) ?? '',
                'epc_count' => (int) $row->epc_count,
            ])
            ->all();

        $firstGtinSubquery = DB::table('exception_epcs')
            ->join('epcs', 'epcs.id', '=', 'exception_epcs.epc_id')
            ->selectRaw('exception_epcs.exception_id, MIN(epcs.gtin14) as gtin14')
            ->groupBy('exception_epcs.exception_id');

        $drillRows = ExceptionCase::query()
            ->overdue()
            ->join('exception_epcs', 'exception_epcs.exception_id', '=', 'exceptions.id')
            ->join('epcs', 'epcs.id', '=', 'exception_epcs.epc_id')
            ->leftJoinSub($firstGtinSubquery, 'first_epc', 'first_epc.exception_id', '=', 'exceptions.id')
            ->select([
                'exceptions.id as case_id',
                'exceptions.status',
                'exceptions.trading_partner_id',
                'exceptions.event_id',
                'epcs.gtin14',
            ])
            ->orderByDesc('exceptions.due_at')
            ->limit($this->drillLimit())
            ->get()
            ->map(fn (object $row): array => [
                'case_id' => (int) $row->case_id,
                'status' => $this->stringifySeverity($row->status) ?? '',
                'trading_partner_id' => $row->trading_partner_id !== null ? (int) $row->trading_partner_id : null,
                'gtin14' => filled($row->gtin14) ? (string) $row->gtin14 : null,
                'event_id' => $row->event_id !== null ? (int) $row->event_id : null,
            ])
            ->all();

        return [
            'summary' => [
                'by_status' => $byStatus,
                'total_epcs' => array_sum(array_column($byStatus, 'epc_count')),
            ],
            'rows' => $drillRows,
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function openExceptionsByCode(): array
    {
        $byCode = ExceptionCase::query()
            ->open()
            ->join('exception_types', 'exception_types.id', '=', 'exceptions.exception_type_id')
            ->selectRaw('exception_types.code, COUNT(*) as aggregate')
            ->groupBy('exception_types.code')
            ->orderByDesc('aggregate')
            ->get()
            ->map(fn (object $row): array => [
                'code' => (string) $row->code,
                'count' => (int) $row->aggregate,
            ])
            ->all();

        $drillRows = ExceptionCase::query()
            ->open()
            ->join('exception_types', 'exception_types.id', '=', 'exceptions.exception_type_id')
            ->select([
                'exceptions.id as case_id',
                'exception_types.code',
                'exceptions.trading_partner_id',
                'exceptions.severity',
            ])
            ->orderByDesc('exceptions.created_at')
            ->limit($this->drillLimit())
            ->get()
            ->map(fn (object $row): array => [
                'case_id' => (int) $row->case_id,
                'code' => (string) $row->code,
                'trading_partner_id' => $row->trading_partner_id !== null ? (int) $row->trading_partner_id : null,
                'severity' => $this->stringifySeverity($row->severity),
            ])
            ->all();

        return [
            'summary' => [
                'by_code' => $byCode,
                'total' => array_sum(array_column($byCode, 'count')),
            ],
            'rows' => $drillRows,
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function l3L4IngestLag(): array
    {
        return app(L3L4IngestLag::class)->forRange($this->since(), $this->drillLimit());
    }

    public function since(): Carbon
    {
        return match ($this->range) {
            '7' => now()->subDays(7)->startOfDay(),
            '30' => now()->subDays(30)->startOfDay(),
            default => now()->startOfMonth(),
        };
    }

    /**
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function forKey(string $key): array
    {
        return match ($key) {
            'transmit_success' => $this->transmitSuccess(),
            'mdn_success' => $this->mdnSuccess(),
            'late_missing_mdn' => $this->lateMissingMdn(),
            'decommission_by_reason' => $this->decommissionByReason(),
            'stuck_serials' => $this->stuckSerialsByStatus(),
            'open_exceptions_by_code' => $this->openExceptionsByCode(),
            'l3_l4_ingest_lag' => $this->l3L4IngestLag(),
            default => ['summary' => [], 'rows' => []],
        };
    }

    private static function normalizeRange(string $range): string
    {
        return match ($range) {
            '7', '30' => $range,
            default => 'mtd',
        };
    }

    /**
     * @return Builder<EpcisDocument>
     */
    private function outboundTransmitBaseQuery(): Builder
    {
        $since = $this->since();

        return EpcisDocument::query()
            ->where('direction', 'outbound')
            ->where(function (Builder $query) use ($since): void {
                $query->where('sent_at', '>=', $since)
                    ->orWhere(function (Builder $inner) use ($since): void {
                        $inner->whereNull('sent_at')
                            ->where('creation_date', '>=', $since);
                    });
            });
    }

    /**
     * @return Builder<TransmissionMdn>
     */
    private function mdnWindowBaseQuery(): Builder
    {
        return TransmissionMdn::query()
            ->where('created_at', '>=', $this->since())
            ->where('mdn_status', '!=', 'superseded');
    }

    /**
     * @return Builder<EpcisEvent>
     */
    private function decommissionEventsBaseQuery(): Builder
    {
        return EpcisEvent::query()
            ->notSuperseded()
            ->where('event_time', '>=', $this->since())
            ->whereRaw("LOWER(COALESCE(epcis_events.biz_step, '')) LIKE ?", ['%decommissioning%']);
    }

    private function decommissionReasonExpression(): string
    {
        return "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(epcis_events.extension_json, '$.decommission_reason')), ''), 'unknown')";
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function mdnSlaCutoffs(): array
    {
        $missingAfterHours = max(1, (int) config('tracepharma.as2_mdn.missing_after_hours', 24));
        $lateAfterHours = max($missingAfterHours + 1, (int) config('tracepharma.as2_mdn.late_after_hours', 72));

        return [
            now()->subHours($missingAfterHours),
            now()->subHours($lateAfterHours),
        ];
    }

    private function openMdnExceptionCaseCount(string $code): int
    {
        return (int) $this->openMdnExceptionCasesQuery([$code])->count();
    }

    /**
     * @param  list<string>  $codes
     * @return Builder<ExceptionCase>
     */
    private function openMdnExceptionCasesQuery(array $codes): Builder
    {
        return ExceptionCase::query()
            ->open()
            ->join('exception_types', 'exception_types.id', '=', 'exceptions.exception_type_id')
            ->whereIn('exception_types.code', $codes)
            ->select('exceptions.*', 'exception_types.code');
    }

    private function drillLimit(): int
    {
        return max(1, (int) config('tracepharma.leadership.drill_limit', self::DRILL_LIMIT));
    }

    private function stringifySeverity(mixed $severity): ?string
    {
        if ($severity === null) {
            return null;
        }

        if ($severity instanceof \BackedEnum) {
            return (string) $severity->value;
        }

        return (string) $severity;
    }
}
