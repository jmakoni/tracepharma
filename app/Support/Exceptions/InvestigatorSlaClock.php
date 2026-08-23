<?php

namespace App\Support\Exceptions;

use App\Models\Exceptions\ExceptionCase;
use Carbon\CarbonInterface;

/**
 * DSCSA 72-hour supplier-correction clock for the Investigator SLA page.
 * Internal exception due_at may be tighter; the earlier deadline wins.
 */
final class InvestigatorSlaClock
{
    public const HOURS = 72;

    public function deadline(ExceptionCase $case): CarbonInterface
    {
        $created = $case->created_at ?? now();
        $dscsa = $created->copy()->addHours(self::HOURS);

        if ($case->due_at !== null && $case->due_at->lt($dscsa)) {
            return $case->due_at;
        }

        return $dscsa;
    }

    public function isBreached(ExceptionCase $case): bool
    {
        return $this->deadline($case)->isPast();
    }

    public function remainingLabel(ExceptionCase $case): string
    {
        $deadline = $this->deadline($case);

        if ($deadline->isPast()) {
            return 'Breached '.$deadline->diffForHumans();
        }

        return $deadline->diffForHumans(['parts' => 2]);
    }
}
