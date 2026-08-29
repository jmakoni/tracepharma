<?php

namespace App\Actions\Shipping;

use App\Models\Shipping\OutboundShippingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use DomainException;

/**
 * Authorize an under-scan complete: residual units stay on the parent session expected count.
 */
final class DeclareOutboundShippingSplit
{
    public function handle(OutboundShippingSession $session, ?User $actor = null): OutboundShippingSession
    {
        $actor ??= auth()->user() instanceof User ? auth()->user() : null;

        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, $actor)) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        if (! $session->canScan()) {
            throw new DomainException('Cannot declare a split on a closed ship order.');
        }

        if ($actor instanceof User && $session->site_id !== null) {
            SiteAccess::assertCanAccessSite($actor, (int) $session->site_id);
        }

        if ((int) $session->expected_count <= 0) {
            throw new DomainException('Set an expected unit count before declaring a split shipment.');
        }

        $session->forceFill([
            'split_declared' => true,
            'split_declared_at' => now(),
            'split_declared_by' => $actor?->getKey(),
        ])->save();

        return $session->refresh();
    }
}
