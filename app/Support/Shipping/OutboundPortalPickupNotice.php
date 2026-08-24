<?php

namespace App\Support\Shipping;

use App\Models\Shipping\OutboundShippingSession;
use App\Models\TradingPartner;
use App\Services\Outbound\CustomerPortalService;
use Throwable;

/**
 * Signed buyer-portal URL for the send notification. Never fails the send.
 */
final class OutboundPortalPickupNotice
{
    public static function signedUrl(?OutboundShippingSession $session): ?string
    {
        if ($session === null) {
            return null;
        }

        $session->loadMissing('tradingPartner');
        $partner = $session->tradingPartner;

        if (! $partner instanceof TradingPartner || ! $partner->is_active) {
            return null;
        }

        try {
            return app(CustomerPortalService::class)->signedCustomerPortalUrl($partner);
        } catch (Throwable) {
            return null;
        }
    }
}
