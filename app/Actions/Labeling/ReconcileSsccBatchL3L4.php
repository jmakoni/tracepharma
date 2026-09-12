<?php

namespace App\Actions\Labeling;

use App\Enums\ExceptionSeverity;
use App\Enums\ExceptionStatus;
use App\Enums\SsccLabelBatchStatus;
use App\Models\Epcis\Epc;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Exceptions\ExceptionType;
use App\Models\SsccLabel;
use App\Models\SsccLabelBatch;
use App\Services\Exceptions\ExceptionService;
use Database\Seeders\ExceptionTypeSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compare SSCC label counts (L3/source) to L4 commissioning ObjectEvent links for a batch.
 * On mismatch, open a single fingerprint-deduped L2_L3_RECONCILIATION_FAILURE workbench case.
 */
final class ReconcileSsccBatchL3L4
{
    public const EXCEPTION_CODE = 'L2_L3_RECONCILIATION_FAILURE';

    public function __construct(
        private readonly ExceptionService $exceptionService,
    ) {}

    /**
     * @return array{
     *     matched: bool,
     *     skipped: bool,
     *     expected: int,
     *     actual: int,
     *     exception_case_id: ?int,
     *     opened: bool
     * }
     */
    public function handle(SsccLabelBatch $batch, bool $dryRun = false): array
    {
        if ($batch->status !== SsccLabelBatchStatus::Completed) {
            return $this->result(matched: true, skipped: true, expected: 0, actual: 0);
        }

        if ($batch->commissioned_at === null && ! filled($batch->commissioning_epcis_file_path)) {
            return $this->result(matched: true, skipped: true, expected: 0, actual: 0);
        }

        $labels = $batch->labels()->get(['id', 'sscc_18', 'sscc_urn']);
        $expected = $labels->count();

        if ($expected === 0) {
            return $this->result(matched: true, skipped: true, expected: 0, actual: 0);
        }

        $epcIds = $this->resolveEpcIds($labels);
        $actual = $this->countCommissioningLinks($epcIds);

        if ($actual === $expected) {
            return $this->result(matched: true, skipped: false, expected: $expected, actual: $actual);
        }

        $fingerprint = $this->fingerprint((int) $batch->getKey());
        $existingId = $this->findOpenCaseId($fingerprint);

        if ($existingId !== null) {
            return $this->result(
                matched: false,
                skipped: false,
                expected: $expected,
                actual: $actual,
                exceptionCaseId: $existingId,
                opened: false,
            );
        }

        if ($dryRun) {
            return $this->result(
                matched: false,
                skipped: false,
                expected: $expected,
                actual: $actual,
                exceptionCaseId: null,
                opened: false,
            );
        }

        $type = $this->resolveType();
        if ($type === null) {
            return $this->result(
                matched: false,
                skipped: false,
                expected: $expected,
                actual: $actual,
            );
        }

        $direction = $actual < $expected ? 'L4 short' : 'L4 extra';
        $sampleSscc = (string) ($labels->first()?->sscc_18 ?? '');
        $message = sprintf(
            '%s: SSCC batch #%d at site %s — L3 labels=%d, L4 commissioning links=%d%s.',
            $direction,
            (int) $batch->getKey(),
            $batch->commission_site_id !== null ? (string) $batch->commission_site_id : 'n/a',
            $expected,
            $actual,
            $sampleSscc !== '' ? ' (sample SSCC '.$sampleSscc.')' : '',
        );

        $case = $this->exceptionService->create([
            'exception_type_id' => $type->getKey(),
            'document_id' => null,
            'site_id' => $batch->commission_site_id,
            'trading_partner_id' => $batch->trading_partner_id,
            'title' => 'L3↔L4 reconcile · batch #'.$batch->getKey(),
            'description' => $message.' ['.$fingerprint.'; expected='.$expected.'; actual='.$actual.']',
            'severity' => ExceptionSeverity::High->value,
            'status' => ExceptionStatus::New->value,
        ], $epcIds);

        return $this->result(
            matched: false,
            skipped: false,
            expected: $expected,
            actual: $actual,
            exceptionCaseId: (int) $case->getKey(),
            opened: true,
        );
    }

    public function fingerprint(int $batchId): string
    {
        return 'sscc-batch-#'.$batchId.'-l3-l4-recon';
    }

    /**
     * @param  Collection<int, SsccLabel>  $labels
     * @return list<int>
     */
    private function resolveEpcIds($labels): array
    {
        $urns = $labels->pluck('sscc_urn')->filter(fn ($v): bool => filled($v))->unique()->values()->all();
        $sscc18s = $labels->pluck('sscc_18')->filter(fn ($v): bool => filled($v))->unique()->values()->all();

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
    private function countCommissioningLinks(array $epcIds): int
    {
        if ($epcIds === []) {
            return 0;
        }

        return (int) DB::table('event_epcs as ee')
            ->join('epcis_events as ev', 'ev.id', '=', 'ee.event_id')
            ->whereIn('ee.epc_id', $epcIds)
            ->where('ev.event_type', 'ObjectEvent')
            ->where('ev.action', 'ADD')
            ->where(function ($query): void {
                $query->where('ev.biz_step', 'urn:epcglobal:cbv:bizstep:commissioning')
                    ->orWhere('ev.biz_step', 'commissioning')
                    ->orWhere('ev.biz_step', 'like', '%:commissioning');
            })
            ->count();
    }

    private function findOpenCaseId(string $fingerprint): ?int
    {
        $type = ExceptionType::query()->where('code', self::EXCEPTION_CODE)->first();
        if ($type === null) {
            return null;
        }

        $id = ExceptionCase::query()
            ->where('exception_type_id', $type->getKey())
            ->whereNotIn('status', [
                ExceptionStatus::Resolved->value,
                ExceptionStatus::Closed->value,
                ExceptionStatus::Cancelled->value,
            ])
            ->where('description', 'like', '%'.$fingerprint.'%')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function resolveType(): ?ExceptionType
    {
        $type = ExceptionType::query()->where('code', self::EXCEPTION_CODE)->first();

        if ($type === null) {
            (new ExceptionTypeSeeder)->run();
            $type = ExceptionType::query()->where('code', self::EXCEPTION_CODE)->first();
        }

        return $type;
    }

    /**
     * @return array{
     *     matched: bool,
     *     skipped: bool,
     *     expected: int,
     *     actual: int,
     *     exception_case_id: ?int,
     *     opened: bool
     * }
     */
    private function result(
        bool $matched,
        bool $skipped,
        int $expected,
        int $actual,
        ?int $exceptionCaseId = null,
        bool $opened = false,
    ): array {
        return [
            'matched' => $matched,
            'skipped' => $skipped,
            'expected' => $expected,
            'actual' => $actual,
            'exception_case_id' => $exceptionCaseId,
            'opened' => $opened,
        ];
    }
}
