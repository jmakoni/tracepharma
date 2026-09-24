<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use App\Enums\ExceptionStatus;
use App\Models\Exceptions\ExceptionCase;

/**
 * Honesty board statuses. Existing ExceptionStatus values stay on the case for audit.
 *
 *   open            ← new, triaged, investigating, waiting_internal, pending_approval
 *   waiting_partner ← waiting_partner
 *   cleared         ← cleared (predicate now false)
 *   resolved        ← resolved, closed
 *   overridden      ← overridden (manual close while predicate still true)
 *
 * cancelled stays audit-only and is not on the honesty board.
 */
final class ExceptionHonestyStatus
{
    public const Open = 'open';

    public const WaitingPartner = 'waiting_partner';

    public const Cleared = 'cleared';

    public const Resolved = 'resolved';

    public const Overridden = 'overridden';

    /**
     * @return array<string, string>
     */
    public static function map(): array
    {
        return [
            ExceptionStatus::New->value => self::Open,
            ExceptionStatus::Triaged->value => self::Open,
            ExceptionStatus::Investigating->value => self::Open,
            ExceptionStatus::WaitingInternal->value => self::Open,
            ExceptionStatus::PendingApproval->value => self::Open,
            ExceptionStatus::WaitingPartner->value => self::WaitingPartner,
            ExceptionStatus::Cleared->value => self::Cleared,
            ExceptionStatus::Resolved->value => self::Resolved,
            ExceptionStatus::Closed->value => self::Resolved,
            ExceptionStatus::Overridden->value => self::Overridden,
        ];
    }

    public static function from(ExceptionStatus $status): ?string
    {
        return self::map()[$status->value] ?? null;
    }

    public static function conditionLabel(ExceptionCase $case): string
    {
        if ($case->status === ExceptionStatus::Cleared || $case->condition_still_true === false) {
            return 'cleared';
        }

        return 'still true';
    }
}
