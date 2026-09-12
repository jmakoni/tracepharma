<?php

declare(strict_types=1);

namespace App\Support\Receiving;

use App\Enums\CmoOwnership;
use App\Enums\TenantProfile;
use App\Models\Epcis\EpcisDocument;
use App\Models\TradingPartner;
use App\Support\TenantSettings;

/**
 * Manufacturer inbound from a CMO that already packed our owned product —
 * soft-handle missing TS / biz-transaction (not a sale to us).
 *
 * Rule (everywhere): tenant autoReceiveFromCmo() + partner is_cmo + cmo_ownership=own_product.
 */
final class CmoOwnProductInbound
{
    public static function applies(?EpcisDocument $document): bool
    {
        if ($document === null) {
            return false;
        }

        $tenant = tenant();
        if ($tenant === null || $tenant->profile !== TenantProfile::Manufacturer) {
            return false;
        }

        if (! TenantSettings::forTenant($tenant)->autoReceiveFromCmo()) {
            return false;
        }

        if ((string) ($document->direction ?? '') !== 'inbound') {
            return false;
        }

        $partner = self::senderPartner($document);
        if ($partner === null || ! $partner->is_active) {
            return false;
        }

        if (! (bool) $partner->is_cmo) {
            return false;
        }

        $ownership = $partner->cmo_ownership;
        if ($ownership instanceof CmoOwnership) {
            return $ownership === CmoOwnership::OwnProduct;
        }

        return (string) $ownership === CmoOwnership::OwnProduct->value;
    }

    public static function senderPartner(EpcisDocument $document): ?TradingPartner
    {
        $partnerId = $document->trading_partner_id;
        if ($partnerId === null) {
            return null;
        }

        $partner = TradingPartner::query()->find($partnerId);

        return $partner instanceof TradingPartner ? $partner : null;
    }
}
