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
 * Cancel an open or in-progress ship order without an authored document.
 */
final class CancelOutboundShippingSession
{
    public function handle(OutboundShippingSession $session, ?int $actorId = null): OutboundShippingSession
    {
        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        $user = auth()->user();
        if ($user instanceof User && $session->site_id !== null) {
            SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
        }

        return DB::transaction(function () use ($session): OutboundShippingSession {
            $session = OutboundShippingSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if ($session->epcis_document_id !== null) {
                throw new DomainException('Cannot cancel a ship order that already has an EPCIS document.');
            }

            if (! in_array($session->status, ['open', 'in_progress'], true)) {
                throw new DomainException("Cannot cancel ship order with status [{$session->status}].");
            }

            $session->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ])->save();

            return $session->refresh();
        });
    }
}
