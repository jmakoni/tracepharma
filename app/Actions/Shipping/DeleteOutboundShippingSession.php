<?php

namespace App\Actions\Shipping;

use App\Models\Shipping\OutboundShippingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Hard-delete an open or in-progress ship order without an authored EPCIS document.
 */
final class DeleteOutboundShippingSession
{
    public function handle(OutboundShippingSession $session, ?int $actorId = null): void
    {
        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        $user = auth()->user();
        if ($user instanceof User && $session->site_id !== null) {
            SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
        }

        DB::transaction(function () use ($session): void {
            $session = OutboundShippingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->epcis_document_id !== null) {
                throw new DomainException('Cannot delete a ship order that already has an EPCIS document.');
            }

            if (! in_array($session->status, ['open', 'in_progress'], true)) {
                throw new DomainException("Cannot delete ship order with status [{$session->status}].");
            }

            $session->delete();
        });
    }
}
