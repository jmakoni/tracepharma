<?php

namespace App\Actions\Shipping;

use App\Models\Shipping\OutboundShippingSession;
use App\Models\User;
use App\Support\Auth\JobRoleAccess;
use App\Support\Auth\Permissions;
use App\Support\Auth\SiteAccess;
use DomainException;
use Illuminate\Support\Arr;

/**
 * Update ASN / PO / invoice / references on a ship order session.
 */
final class UpdateOutboundShippingReferences
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(OutboundShippingSession $session, array $data): OutboundShippingSession
    {
        if (! JobRoleAccess::allowsForActor(Permissions::NavShip, auth()->user())) {
            throw new DomainException('Shipping is not authorized for your job role.');
        }

        if (! $session->canScan()) {
            throw new DomainException('Cannot update references on a closed ship order.');
        }

        $user = auth()->user();
        if ($user instanceof User && $session->site_id !== null) {
            SiteAccess::assertCanAccessSite($user, (int) $session->site_id);
        }

        $fill = [
            'asn_number' => Arr::has($data, 'asn_number')
                ? ($data['asn_number'] !== null && $data['asn_number'] !== '' ? (string) $data['asn_number'] : null)
                : $session->asn_number,
            'customer_po' => Arr::has($data, 'customer_po')
                ? ($data['customer_po'] !== null && $data['customer_po'] !== '' ? (string) $data['customer_po'] : null)
                : $session->customer_po,
            'invoice_number' => Arr::has($data, 'invoice_number')
                ? ($data['invoice_number'] !== null && $data['invoice_number'] !== '' ? (string) $data['invoice_number'] : null)
                : $session->invoice_number,
            'shipment_reference' => Arr::has($data, 'shipment_reference')
                ? ($data['shipment_reference'] !== null && $data['shipment_reference'] !== '' ? (string) $data['shipment_reference'] : null)
                : $session->shipment_reference,
            'dscsa_affirm' => Arr::has($data, 'dscsa_affirm')
                ? (bool) $data['dscsa_affirm']
                : $session->dscsa_affirm,
            'is_drop_shipment' => Arr::has($data, 'is_drop_shipment')
                ? (bool) $data['is_drop_shipment']
                : $session->is_drop_shipment,
        ];

        if (Arr::has($data, 'expected_count')) {
            $newExpected = max(0, (int) $data['expected_count']);
            $currentExpected = (int) $session->expected_count;
            $hasConfirmedScans = (int) $session->confirmed_count > 0
                || $session->scanLines()->where('status', 'confirmed')->exists();

            if ($hasConfirmedScans && $newExpected < $currentExpected) {
                throw new DomainException(
                    'Cannot lower expected unit count after scanning. Declare a split/partial shipment instead.',
                );
            }

            $fill['expected_count'] = $newExpected;
        }

        $session->forceFill($fill)->save();

        return $session->refresh();
    }
}
