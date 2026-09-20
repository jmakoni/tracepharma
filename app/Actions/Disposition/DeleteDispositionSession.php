<?php

namespace App\Actions\Disposition;

use App\Models\Disposition\DispositionSession;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Hard-delete an open disposition session without authored EPCIS.
 */
final class DeleteDispositionSession
{
    public function handle(DispositionSession $session): void
    {
        if (! JobRoleAccess::allows(Permissions::NavShip)) {
            throw new DomainException('Disposition is not authorized for your job role.');
        }

        DB::transaction(function () use ($session): void {
            $session = DispositionSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->disposition_events_generated_at !== null) {
                throw new DomainException('Cannot delete a disposition session that already has authored EPCIS.');
            }

            if ($session->status !== 'open') {
                throw new DomainException("Cannot delete disposition session with status [{$session->status}].");
            }

            $session->delete();
        });
    }
}
