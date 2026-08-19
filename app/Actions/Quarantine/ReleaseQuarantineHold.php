<?php

namespace App\Actions\Quarantine;

use App\Enums\ExceptionDisposition;
use App\Models\Exceptions\ExceptionCase;
use App\Models\Quarantine\QuarantineHold;
use App\Models\User;
use App\Services\Quarantine\QuarantineService;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class ReleaseQuarantineHold
{
    public function handle(
        QuarantineHold $hold,
        string $reason = 'Released',
        ?User $user = null,
        ?ExceptionCase $excludingCase = null,
    ): QuarantineHold {
        if ($hold->status !== 'open') {
            return $hold;
        }

        $excludingCase ??= $hold->exception;

        if ($excludingCase?->disposition === ExceptionDisposition::Illegitimate) {
            throw ValidationException::withMessages([
                'disposition' => 'Cannot release quarantine while disposition is Illegitimate. Holds must remain for physical segregation and FDA follow-up.',
            ]);
        }

        if ($hold->epc_id !== null && $this->shouldRetainHoldForEpc((int) $hold->epc_id, $excludingCase)) {
            return $hold;
        }

        if (! JobRoleAccess::allows(Permissions::NavExceptions)) {
            throw new DomainException('Exceptions are not authorized for your job role.');
        }

        $user ??= auth()->user();

        if ($user instanceof User) {
            $shipToSiteId = $hold->document?->ship_to_site_id !== null
                ? (int) $hold->document->ship_to_site_id
                : null;

            if (! SiteAccess::canAccessShipToSite($user, $shipToSiteId)) {
                throw new AuthorizationException('You do not have access to release holds for this site.');
            }
        }

        $hold->forceFill([
            'status' => 'released',
            'closed_at' => now(),
            'closed_reason' => $reason,
        ])->save();

        $refreshed = $hold->refresh();

        if ($refreshed->exception_id !== null) {
            $exceptionCase = ExceptionCase::query()->find((int) $refreshed->exception_id);
            if ($exceptionCase !== null) {
                app(QuarantineService::class)->refreshSerialsAffected($exceptionCase);
            }
        }

        return $refreshed;
    }

    /**
     * Whether another case still requires this EPC hold: an open sibling, or any sibling
     * (open or terminal) with an Illegitimate disposition.
     */
    public function shouldRetainHoldForEpc(int $epcId, ?ExceptionCase $excluding): bool
    {
        if ($excluding !== null && $this->hasOtherOpenCaseForEpc($epcId, $excluding)) {
            return true;
        }

        if ($excluding === null) {
            return ExceptionCase::query()
                ->open()
                ->whereHas('epcs', fn ($query) => $query->where('epcs.id', $epcId))
                ->exists()
                || ExceptionCase::query()
                    ->where('disposition', ExceptionDisposition::Illegitimate->value)
                    ->whereHas('epcs', fn ($query) => $query->where('epcs.id', $epcId))
                    ->exists();
        }

        return ExceptionCase::query()
            ->whereKeyNot($excluding->getKey())
            ->where('disposition', ExceptionDisposition::Illegitimate->value)
            ->whereHas('epcs', fn ($query) => $query->where('epcs.id', $epcId))
            ->exists();
    }

    /**
     * Whether an open, non-terminal case other than $excluding still references $epcId.
     */
    private function hasOtherOpenCaseForEpc(int $epcId, ExceptionCase $excluding): bool
    {
        return ExceptionCase::query()
            ->open()
            ->whereKeyNot($excluding->getKey())
            ->whereHas('epcs', fn ($query) => $query->where('epcs.id', $epcId))
            ->exists();
    }
}
