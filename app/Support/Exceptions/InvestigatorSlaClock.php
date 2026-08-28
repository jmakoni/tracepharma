<?php

namespace App\Support\Exceptions;

use App\Enums\ExceptionActivityKind;
use App\Models\Exceptions\ExceptionActivity;
use App\Models\Exceptions\ExceptionCase;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * DSCSA 72-hour supplier-correction clock for the Investigator SLA page.
 * After a successful supplier email, due_at is the running deadline.
 * Until then, display is min(due_at, created_at plus 72 hours).
 */
final class InvestigatorSlaClock
{
    public const HOURS = 72;

    public const EMAIL_ACTIVITY_PREFIX = 'DSCSA exception email sent';

    public function deadline(ExceptionCase $case): CarbonInterface
    {
        $created = $case->created_at ?? now();
        $overlay = $created->copy()->addHours(self::HOURS);

        if ($this->supplierWasEmailed($case)) {
            return $case->due_at ?? $overlay;
        }

        if ($case->due_at === null) {
            return $overlay;
        }

        return $case->due_at->lt($overlay) ? $case->due_at : $overlay;
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

    public function supplierWasEmailed(ExceptionCase $case): bool
    {
        if ($case->relationLoaded('activities')) {
            return $case->activities->contains(
                fn (mixed $activity): bool => $this->isSupplierEmailActivity($activity),
            );
        }

        if (! $case->exists) {
            return false;
        }

        return $this->constrainSupplierEmailActivities($case->activities())->exists();
    }

    public function lastSupplierEmailAt(ExceptionCase $case): ?CarbonInterface
    {
        if ($case->relationLoaded('activities')) {
            $latest = $case->activities
                ->filter(fn (mixed $activity): bool => $this->isSupplierEmailActivity($activity))
                ->sortByDesc(fn (ExceptionActivity $activity): int => $activity->created_at?->getTimestamp() ?? 0)
                ->first();

            return $latest instanceof ExceptionActivity ? $latest->created_at : null;
        }

        if (! $case->exists) {
            return null;
        }

        $latest = $this->constrainSupplierEmailActivities($case->activities())
            ->orderByDesc('created_at')
            ->first(['created_at']);

        return $latest?->created_at;
    }

    public function isSupplierEmailActivity(mixed $activity): bool
    {
        return $activity instanceof ExceptionActivity
            && $activity->kind === ExceptionActivityKind::System
            && str_starts_with((string) $activity->body, self::EMAIL_ACTIVITY_PREFIX);
    }

    /**
     * @param  Builder<ExceptionActivity>|Relation<ExceptionActivity, ExceptionCase, mixed>  $query
     * @return Builder<ExceptionActivity>|Relation<ExceptionActivity, ExceptionCase, mixed>
     */
    public function constrainSupplierEmailActivities(Builder|Relation $query): Builder|Relation
    {
        return $query
            ->where('kind', ExceptionActivityKind::System)
            ->where('body', 'like', self::EMAIL_ACTIVITY_PREFIX.'%');
    }
}
