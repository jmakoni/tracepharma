<?php

namespace App\Support\Exceptions;

use App\Models\Exceptions\ExceptionCase;
use Carbon\CarbonInterface;

/**
 * DSCSA 72-hour supplier-correction clock for the Investigator SLA page.
 * After a successful supplier email, due_at is the running deadline.
 * Until then, display overlays created_at plus 72 hours.
 */
final class InvestigatorSlaClock
{
    public const HOURS = 72;

    public function deadline(ExceptionCase $case): CarbonInterface
    {
        if ($case->due_at !== null) {
            return $case->due_at;
        }

        $created = $case->created_at ?? now();

        return $created->copy()->addHours(self::HOURS);
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
