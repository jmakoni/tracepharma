<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Models\Shipping\OutboundShippingSession;
use App\Models\User;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Audited escape hatch: allow send on a live-ladder connection when expected_count is unset.
 */
final class OverrideOutboundShippingQuantityGate
{
    public function handle(OutboundShippingSession $session, User $actor, string $reason): OutboundShippingSession
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('A non-empty reason is required to override the quantity gate.');
        }

        if (! $actor->can(Permissions::ShipQuantityGateOverride)) {
            throw new AuthorizationException(
                'Overriding the ship quantity gate requires the ship quantity gate override permission.',
            );
        }

        if (! $session->canSend()) {
            throw new DomainException('Cannot override the quantity gate on a closed ship order.');
        }

        if ($session->site_id !== null) {
            SiteAccess::assertCanAccessSite($actor, (int) $session->site_id);
        }

        if ((int) $session->expected_count > 0) {
            throw new DomainException('Expected unit count is already set; quantity gate override is not needed.');
        }

        if ((bool) $session->quantity_gate_overridden) {
            throw new DomainException('Quantity gate override is already recorded on this ship order.');
        }

        $session->loadMissing('outboundConnection');
        $connection = $session->outboundConnection;

        if ($connection === null || ! $connection->conformanceState()->requiresExpectedQuantity()) {
            throw new DomainException(
                'Quantity gate override applies only to live-ladder outbound connections (first live lot, hypercare, or live).',
            );
        }

        $session->forceFill([
            'quantity_gate_overridden' => true,
            'quantity_gate_overridden_at' => now(),
            'quantity_gate_override_reason' => $reason,
            'quantity_gate_overridden_by' => $actor->getKey(),
        ])->save();

        activity()
            ->performedOn($session)
            ->causedBy($actor)
            ->withProperties([
                'outbound_connection_id' => $connection->getKey(),
                'conformance_state' => $connection->conformanceState()->value,
                'expected_count' => (int) $session->expected_count,
                'reason' => $reason,
            ])
            ->log('outbound_shipping_quantity_gate_overridden');

        return $session->refresh();
    }
}
