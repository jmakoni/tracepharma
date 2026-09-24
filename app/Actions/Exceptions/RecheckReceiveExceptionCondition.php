<?php

declare(strict_types=1);

namespace App\Actions\Exceptions;

use App\Enums\ExceptionActivityKind;
use App\Enums\ExceptionActivityVisibility;
use App\Enums\ExceptionStatus;
use App\Models\Exceptions\ExceptionCase;
use App\Models\User;
use App\Services\Exceptions\ExceptionService;
use App\Support\Receiving\ReceiveExceptionCondition;

/**
 * Re-run the type predicate. False → cleared + sla_stopped_at.
 * True → remain open; do not reset created_at / due_at.
 */
final class RecheckReceiveExceptionCondition
{
    public function __construct(
        private readonly ReceiveExceptionCondition $condition,
        private readonly ExceptionService $exceptions,
    ) {}

    public function handle(ExceptionCase $case, ?User $actor = null): ExceptionCase
    {
        $case->loadMissing('type');

        if ($case->status?->isOpen() !== true) {
            return $case;
        }

        $openedAt = $case->created_at?->toDateTimeString();
        $dueAt = $case->due_at?->toDateTimeString();
        $stillTrue = $this->condition->stillTrue($case);

        if ($stillTrue) {
            $case->forceFill(['condition_still_true' => true])->save();
            $case->logActivity(
                ExceptionActivityKind::System,
                $actor,
                'Re-check: condition still true.',
                ExceptionActivityVisibility::Internal,
                [
                    'condition_still_true' => true,
                    'opened_at' => $openedAt,
                    'due_at' => $dueAt,
                ],
            );

            return $case->fresh() ?? $case;
        }

        $case->forceFill([
            'condition_still_true' => false,
            'sla_stopped_at' => $case->sla_stopped_at ?? now(),
        ])->save();

        if ($case->status !== ExceptionStatus::Cleared) {
            if (! $case->status->allowsTransitionTo(ExceptionStatus::Cleared)) {
                if ($case->status === ExceptionStatus::New && $case->status->allowsTransitionTo(ExceptionStatus::Triaged)) {
                    $this->exceptions->transition($case, ExceptionStatus::Triaged, $actor, 'Auto-triaged before clear.');
                    $case->refresh();
                }
            }

            if ($case->status->allowsTransitionTo(ExceptionStatus::Cleared)) {
                $this->exceptions->transition($case, ExceptionStatus::Cleared, $actor, 'Re-check: condition cleared.');
            } else {
                $case->forceFill([
                    'status' => ExceptionStatus::Cleared,
                    'sla_stopped_at' => $case->sla_stopped_at ?? now(),
                    'condition_still_true' => false,
                ])->save();
                $case->logActivity(
                    ExceptionActivityKind::StatusChange,
                    $actor,
                    'Re-check: condition cleared.',
                    ExceptionActivityVisibility::Internal,
                    ['from' => $case->getOriginal('status'), 'to' => ExceptionStatus::Cleared->value],
                );
            }
        }

        return $case->fresh() ?? $case;
    }
}
