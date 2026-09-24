<?php

namespace App\Actions\Packing;

use App\Models\Packing\PackingSession;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Hard-delete an open pack session without authored packing EPCIS.
 */
final class DeletePackingSession
{
    public function handle(PackingSession $session): void
    {
        if (! JobRoleAccess::allows(Permissions::NavShip)) {
            throw new DomainException('Packing is not authorized for your job role.');
        }

        DB::transaction(function () use ($session): void {
            $session = PackingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->packing_events_generated_at !== null) {
                throw new DomainException('Cannot delete a pack session that already has authored packing EPCIS.');
            }

            if ($session->status !== 'open') {
                throw new DomainException("Cannot delete pack session with status [{$session->status}].");
            }

            $session->delete();
        });
    }
}
