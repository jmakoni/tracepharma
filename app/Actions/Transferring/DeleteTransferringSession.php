<?php

namespace App\Actions\Transferring;

use App\Models\Transferring\TransferringSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\TenantFeatures;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Hard-delete an open transfer before shipping EPCIS has been authored.
 */
final class DeleteTransferringSession
{
    public function handle(TransferringSession $session, ?int $actorId = null): void
    {
        if (! TenantFeatures::forTenant(tenant())->supportsTransferring()) {
            throw new DomainException('Transferring is not available for this tenant profile.');
        }

        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        $user = auth()->user();
        if ($user instanceof User) {
            SiteAccess::assertCanAccessSite($user, (int) $session->from_site_id);
        }

        DB::transaction(function () use ($session): void {
            $session = TransferringSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->transfer_events_generated_at !== null || $session->transfer_epcis_document_id !== null) {
                throw new DomainException('Cannot delete a transfer that already has authored transferring EPCIS.');
            }

            if ($session->status !== 'open') {
                throw new DomainException("Cannot delete transfer with status [{$session->status}].");
            }

            $session->delete();
        });
    }
}
