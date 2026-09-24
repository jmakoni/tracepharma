<?php

declare(strict_types=1);

namespace App\Actions\Epcis;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionStatus;
use App\Models\Epcis\EpcisDocument;
use App\Models\Epcis\EpcisException;
use App\Models\Exceptions\ExceptionCase;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Support\Epcis\Validation\EpcisValidationCatalog;
use App\Support\Epcis\Validation\EpcisValidationFinding;
use App\Support\Receiving\ReceiveExceptionTypes;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Re-run the current validator on the stored payload and clear stale ingest
 * ExceptionCase rows whose type is absent from the new finding set.
 *
 * When a case is cleared, matching open {@see EpcisException} signals are
 * marked resolved (same fields as {@see ExceptionService::closeMatchingDocumentSignals}).
 * A second pass closes leftover open signals whose linked case is already
 * cleared or resolved and whose type is absent from the new finding set.
 *
 * Does not call {@see ReprocessEpcisDocument} and does not rewrite events,
 * receiving sessions, or aggregation links.
 */
final class ReevaluateEpcisDocumentFindings
{
    public function __construct(
        private readonly ValidateEpcis12Document $validator,
        private readonly RefreshUnmatchedGlnsFromMasterData $refreshUnmatchedGlns,
        private readonly ExceptionService $exceptions,
    ) {}

    /**
     * @return array{cleared: list<int>, left_open: list<int>, emitted: list<string>}
     */
    public function handle(EpcisDocument $document, ?User $actor = null): array
    {
        $this->refreshUnmatchedGlns->handle($document);

        $validationFailed = false;

        try {
            $findings = $this->validator->computeFindings($document);
        } catch (InvalidArgumentException|Throwable $e) {
            $validationFailed = true;
            $findings = [
                new EpcisValidationFinding(
                    exceptionType: 'INGESTION_PARSE_ERROR',
                    severity: 'error',
                    description: $e->getMessage(),
                ),
            ];
        }

        $emitted = [];
        foreach ($findings as $finding) {
            $code = strtoupper(trim($finding->exceptionType));
            if ($code !== '') {
                $emitted[$code] = $code;
            }
        }
        $emitted = array_values($emitted);

        $cleared = [];
        $leftOpen = [];

        foreach ($this->openIngestCasesForDocument($document) as $case) {
            $code = strtoupper(trim((string) $case->type?->code));
            $openedAt = $case->created_at?->toDateTimeString();

            if ($validationFailed) {
                $case->logActivity(
                    ExceptionActivityKind::System,
                    $actor,
                    'Re-evaluate findings: validation failed; case left open.',
                    ExceptionActivityVisibility::Internal,
                    [
                        'opened_at' => $openedAt,
                    ],
                );
                $leftOpen[] = (int) $case->getKey();

                continue;
            }

            if ($this->mustLeaveOpen($code, $emitted)) {
                $case->forceFill(['condition_still_true' => true])->save();
                $case->logActivity(
                    ExceptionActivityKind::System,
                    $actor,
                    'Re-evaluate findings: '.$code.' still emitted.',
                    ExceptionActivityVisibility::Internal,
                    [
                        'condition_still_true' => true,
                        'opened_at' => $openedAt,
                    ],
                );
                $leftOpen[] = (int) $case->getKey();

                continue;
            }

            $this->clearCase($case, $actor);
            $this->closeSignalsForClearedCase($case, $emitted);
            $cleared[] = (int) $case->getKey();
        }

        if (! $validationFailed) {
            $this->closeLeftoverSignalsForDocument($document, $emitted);
        }

        return [
            'cleared' => $cleared,
            'left_open' => $leftOpen,
            'emitted' => $emitted,
        ];
    }

    /**
     * @param  iterable<ExceptionCase>  $cases
     * @return array{cleared: list<int>, left_open: list<int>, emitted: list<string>}
     */
    public function handleCases(iterable $cases, ?User $actor = null): array
    {
        $cleared = [];
        $leftOpen = [];
        $emitted = [];
        $seen = [];

        foreach ($cases as $case) {
            if (! $case instanceof ExceptionCase || $case->document_id === null) {
                continue;
            }

            $documentId = (int) $case->document_id;
            if (isset($seen[$documentId])) {
                continue;
            }
            $seen[$documentId] = true;

            $document = EpcisDocument::query()->find($documentId);
            if ($document === null) {
                continue;
            }

            $result = $this->handle($document, $actor);
            $cleared = array_merge($cleared, $result['cleared']);
            $leftOpen = array_merge($leftOpen, $result['left_open']);
            $emitted = array_values(array_unique(array_merge($emitted, $result['emitted'])));
        }

        return [
            'cleared' => $cleared,
            'left_open' => $leftOpen,
            'emitted' => $emitted,
        ];
    }

    /**
     * @param  list<string>  $emitted
     */
    private function mustLeaveOpen(string $code, array $emitted): bool
    {
        return in_array($code, $emitted, true);
    }

    /**
     * Open ingest signals whose investigation case is already terminal, but the
     * signal row was never flipped (e.g. a prior Re-evaluate that only cleared
     * the case). Does not reopen cases or rewrite events.
     *
     * @param  list<string>  $emitted
     */
    private function closeLeftoverSignalsForDocument(EpcisDocument $document, array $emitted): void
    {
        $signals = EpcisException::query()
            ->with('case')
            ->where('document_id', $document->getKey())
            ->where('status', 'open')
            ->whereNotNull('case_id')
            ->get();

        $now = now();

        foreach ($signals as $signal) {
            $type = strtoupper(trim((string) $signal->exception_type));
            if ($type === '' || in_array($type, $emitted, true)) {
                continue;
            }

            if (in_array($type, [
                RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE,
                RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE,
            ], true)) {
                continue;
            }

            $case = $signal->case;
            if ($case === null || ! in_array($case->status, [
                ExceptionStatus::Cleared,
                ExceptionStatus::Resolved,
            ], true)) {
                continue;
            }

            $signal->forceFill([
                'status' => 'resolved',
                'resolved_at' => $now,
            ])->save();
        }
    }

    /**
     * @param  list<string>  $emitted
     */
    private function closeSignalsForClearedCase(ExceptionCase $case, array $emitted): void
    {
        $code = strtoupper(trim((string) $case->type?->code));
        if ($code === '' || in_array($code, $emitted, true)) {
            return;
        }

        if (in_array($code, [
            RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE,
            RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE,
        ], true)) {
            return;
        }

        $now = now();
        $referencedId = $this->referencedEpcisExceptionId($case);

        $signals = EpcisException::query()
            ->where('status', 'open')
            ->where(function ($query) use ($case, $code, $referencedId): void {
                $query->where(function ($linked) use ($case, $code): void {
                    $linked->where('document_id', $case->document_id)
                        ->where('case_id', $case->getKey())
                        ->whereRaw('UPPER(exception_type) = ?', [$code]);
                });

                if ($referencedId !== null) {
                    $query->orWhereKey($referencedId);
                }
            })
            ->get();

        foreach ($signals as $signal) {
            $signalType = strtoupper(trim((string) $signal->exception_type));
            if (in_array($signalType, $emitted, true)) {
                continue;
            }

            if (in_array($signalType, [
                RecordDestinationGlnMismatch::OWNING_PARTY_EXCEPTION_TYPE,
                RecordDestinationGlnMismatch::LOCATION_EXCEPTION_TYPE,
            ], true)) {
                continue;
            }

            $signal->forceFill([
                'status' => 'resolved',
                'resolved_at' => $now,
            ])->save();
        }
    }

    private function referencedEpcisExceptionId(ExceptionCase $case): ?int
    {
        $case->loadMissing('activities');

        foreach ($case->activities as $activity) {
            $meta = $activity->meta;
            if (! is_array($meta)) {
                continue;
            }

            $id = $meta['epcis_exception_id'] ?? null;
            if (is_numeric($id) && (int) $id > 0) {
                return (int) $id;
            }
        }

        return null;
    }

    private function clearCase(ExceptionCase $case, ?User $actor): void
    {
        $case->forceFill([
            'condition_still_true' => false,
            'sla_stopped_at' => $case->sla_stopped_at ?? now(),
        ])->save();

        if ($case->status === ExceptionStatus::Cleared) {
            return;
        }

        if ($case->status?->allowsTransitionTo(ExceptionStatus::Cleared)) {
            $this->exceptions->transition($case, ExceptionStatus::Cleared, $actor, 'Re-evaluate findings: type no longer emitted.');

            return;
        }

        if ($case->status === ExceptionStatus::New && $case->status->allowsTransitionTo(ExceptionStatus::Triaged)) {
            $this->exceptions->transition($case, ExceptionStatus::Triaged, $actor, 'Auto-triaged before clear.');
            $case->refresh();
        }

        if ($case->status?->allowsTransitionTo(ExceptionStatus::Cleared)) {
            $this->exceptions->transition($case, ExceptionStatus::Cleared, $actor, 'Re-evaluate findings: type no longer emitted.');

            return;
        }

        $priorStatus = $case->status;

        $case->forceFill([
            'status' => ExceptionStatus::Cleared,
            'condition_still_true' => false,
            'sla_stopped_at' => $case->sla_stopped_at ?? now(),
        ])->save();
        $case->logActivity(
            ExceptionActivityKind::StatusChange,
            $actor,
            'Re-evaluate findings: type no longer emitted.',
            ExceptionActivityVisibility::Internal,
            ['from' => $priorStatus?->value, 'to' => ExceptionStatus::Cleared->value],
        );
    }

    /**
     * @return list<ExceptionCase>
     */
    private function openIngestCasesForDocument(EpcisDocument $document): array
    {
        $documentId = (int) $document->getKey();
        $signalIds = Schema::hasTable('epcis_exceptions')
            ? EpcisException::query()
                ->where('document_id', $documentId)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all()
            : [];

        $receiveTypes = array_values(array_filter(
            array_keys(ReceiveExceptionTypes::typeMap()),
            fn (string $code): bool => $code !== ReceiveExceptionTypes::REASON_UNDECLARED_PARTIAL,
        ));
        $clearable = EpcisValidationCatalog::clearableCodes();

        $cases = ExceptionCase::query()
            ->open()
            ->with('type')
            ->where(function ($query) use ($documentId, $signalIds): void {
                $query->where('document_id', $documentId);

                if ($signalIds !== []) {
                    $query->orWhereHas('activities', function ($activities) use ($signalIds): void {
                        $activities->whereIn('meta->epcis_exception_id', $signalIds);
                    });
                }

                $query->orWhereHas('signals', fn ($signals) => $signals->where('document_id', $documentId));
            })
            ->get();

        return $cases
            ->filter(function (ExceptionCase $case) use ($receiveTypes, $clearable): bool {
                $code = strtoupper(trim((string) $case->type?->code));
                if ($code === '' || in_array($code, $receiveTypes, true)) {
                    return false;
                }

                return in_array($code, $clearable, true);
            })
            ->values()
            ->all();
    }
}
