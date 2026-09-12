<?php

namespace App\Actions\Shipping;

use App\Models\Shipping\OutboundShippingScanLine;
use App\Models\Shipping\OutboundShippingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use App\Support\Shipping\OpenShipOrderQuantityCase;
use DomainException;

/**
 * Authorize an under-scan complete: residual units stay on the parent session expected count.
 *
 * Live-ladder connections may also declare a partial/batch shipment with expected_count = 0
 * (expected total unknown at the scan station — e.g. a PO/ASN shipping in batches over days).
 * That declaration opens a reconciliation case so the open partial stays visible until the
 * order completes.
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

        $expected = (int) $session->expected_count;
        $partialWithoutExpected = false;

        if ($expected <= 0) {
            $session->loadMissing('outboundConnection');
            $connection = $session->outboundConnection;

            if ($connection === null || ! $connection->conformanceState()->requiresExpectedQuantity()) {
                throw new DomainException('Set an expected unit count before declaring a split shipment.');
            }

            $partialWithoutExpected = true;
        }

        $session->forceFill([
            'split_declared' => true,
            'split_declared_at' => now(),
            'split_declared_by' => $actor?->getKey(),
        ])->save();

        if ($partialWithoutExpected) {
            $confirmed = OutboundShippingScanLine::query()
                ->where('outbound_shipping_session_id', $session->getKey())
                ->where('status', 'confirmed')
                ->count();

            app(OpenShipOrderQuantityCase::class)->handle(
                $session,
                'Partial/batch shipment declared with unknown expected total. '
                    .'Reconcile against the ASN/order when the remaining batches ship.',
                0,
                $confirmed,
            );
        }

        return $session->refresh();
    }
}
