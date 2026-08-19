<?php

namespace App\Actions\MasterData;

use App\Enums\AuthorizationStatus;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Product;
use App\Models\TradingPartner;
use App\Support\Fda\FdaTenantLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Link tenant products to a resolved manufacturer and upgrade pending pivots.
 */
final class ReconcilePendingManufacturerAuthorizations
{
    /**
     * @return array{products_linked: int, pivots_authorized: int}
     */
    public function handle(TradingPartner $manufacturer): array
    {
        $organizationId = FdaTenantLink::organizationId($manufacturer);

        if ($organizationId === null) {
            return ['products_linked' => 0, 'pivots_authorized' => 0];
        }

        $fdaProductIds = FdaProduct::query()
            ->where('fda_organization_id', $organizationId)
            ->pluck('id');

        $packagingIds = $fdaProductIds->isEmpty()
            ? collect()
            : FdaProductPackaging::query()
                ->whereIn('fda_product_id', $fdaProductIds)
                ->pluck('id');

        if ($fdaProductIds->isEmpty() && $packagingIds->isEmpty()) {
            return ['products_linked' => 0, 'pivots_authorized' => 0];
        }

        $products = $this->matchingProducts($manufacturer, $fdaProductIds, $packagingIds);

        $productsLinked = 0;
        $pivotsAuthorized = 0;

        foreach ($products as $product) {
            if ($product->trading_partner_id !== $manufacturer->getKey()) {
                $product->update(['trading_partner_id' => $manufacturer->getKey()]);
                $productsLinked++;
            }

            $pivotsAuthorized += $this->authorizePendingPivots($product);
        }

        return [
            'products_linked' => $productsLinked,
            'pivots_authorized' => $pivotsAuthorized,
        ];
    }

    /**
     * @param  Collection<int, int|string>  $fdaProductIds
     * @param  Collection<int, int|string>  $packagingIds
     * @return Collection<int, Product>
     */
    private function matchingProducts(
        TradingPartner $manufacturer,
        Collection $fdaProductIds,
        Collection $packagingIds,
    ): Collection {
        return Product::query()
            ->where(function ($query) use ($manufacturer, $fdaProductIds, $packagingIds): void {
                $query->where(function ($labelerMatch) use ($manufacturer, $fdaProductIds, $packagingIds): void {
                    $labelerMatch
                        ->where(function ($manufacturerScope) use ($manufacturer): void {
                            $manufacturerScope
                                ->whereNull('trading_partner_id')
                                ->orWhere('trading_partner_id', $manufacturer->getKey());
                        })
                        ->where(function ($identityMatch) use ($fdaProductIds, $packagingIds): void {
                            if ($fdaProductIds->isNotEmpty()) {
                                $identityMatch->whereIn('fda_product_id', $fdaProductIds);
                            }

                            if ($packagingIds->isNotEmpty()) {
                                $method = $fdaProductIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                                $identityMatch->{$method}('fda_product_packaging_id', $packagingIds);
                            }
                        });
                });

                $query->orWhere(function ($pendingPivot) use ($fdaProductIds, $packagingIds): void {
                    $pendingPivot
                        ->where(function ($identity) use ($fdaProductIds, $packagingIds): void {
                            if ($fdaProductIds->isNotEmpty()) {
                                $identity->whereIn('fda_product_id', $fdaProductIds);
                            }

                            if ($packagingIds->isNotEmpty()) {
                                $method = $fdaProductIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                                $identity->{$method}('fda_product_packaging_id', $packagingIds);
                            }
                        })
                        ->whereExists(function ($sub): void {
                            $sub->selectRaw('1')
                                ->from('trading_partner_product')
                                ->whereColumn('trading_partner_product.product_id', 'products.id')
                                ->where(
                                    'trading_partner_product.authorization_status',
                                    AuthorizationStatus::PendingManufacturer->value,
                                );
                        });
                });
            })
            ->get();
    }

    private function authorizePendingPivots(Product $product): int
    {
        return DB::table('trading_partner_product')
            ->where('product_id', $product->getKey())
            ->where('authorization_status', AuthorizationStatus::PendingManufacturer->value)
            ->update([
                'authorization_status' => AuthorizationStatus::Authorized->value,
                'authorized_at' => DB::raw('COALESCE(authorized_at, NOW())'),
                'updated_at' => now(),
            ]);
    }
}
