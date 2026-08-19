<?php

namespace App\Actions\MasterData;

use Illuminate\Support\Facades\DB;

/**
 * Ensures at most one primary receive-from partner per tenant product.
 */
final class SetPrimaryReceiveFromPartner
{
    public function productHasPrimary(int $productId): bool
    {
        return DB::table('trading_partner_product')
            ->where('product_id', $productId)
            ->where('is_primary', true)
            ->exists();
    }

    public function handle(int $productId, int $tradingPartnerId): void
    {
        DB::table('trading_partner_product')
            ->where('product_id', $productId)
            ->where('is_primary', true)
            ->where('trading_partner_id', '!=', $tradingPartnerId)
            ->update(['is_primary' => false]);

        DB::table('trading_partner_product')
            ->where('product_id', $productId)
            ->where('trading_partner_id', $tradingPartnerId)
            ->update(['is_primary' => true]);
    }
}
