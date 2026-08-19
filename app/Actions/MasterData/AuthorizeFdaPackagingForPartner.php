<?php

namespace App\Actions\MasterData;

use App\Enums\AuthorizationStatus;
use App\Enums\PackLevel;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Product;
use App\Models\ProductPackagingLink;
use App\Models\TradingPartner;
use App\Support\Fda\FdaPrefill;
use App\Support\Fda\FdaTenantLink;
use App\Support\Gs1\Gtin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authorize an FDA package SKU for a tenant trading partner assortment.
 */
final class AuthorizeFdaPackagingForPartner
{
    public function __construct(
        private SetPrimaryReceiveFromPartner $setPrimary,
    ) {}

    /**
     * @param  array{partner_item_number?: ?string, uom_code?: ?string, units_per_case?: ?int, is_primary?: ?bool}  $pivotAttrs
     * @return array{added: int, attached: int, skipped: int, manufacturer_pending: int, manufacturer_added: int, product_id: ?int}
     */
    public function handle(
        TradingPartner $partner,
        FdaProductPackaging $packaging,
        array $pivotAttrs = [],
        bool $autoAddManufacturer = false,
        ?string $gtinOverride = null,
    ): array {
        $empty = [
            'added' => 0,
            'attached' => 0,
            'skipped' => 0,
            'manufacturer_pending' => 0,
            'manufacturer_added' => 0,
            'product_id' => null,
        ];

        $preferredGtin = Gtin::fromUpc($gtinOverride)
            ?? (filled($packaging->gtin) ? (string) $packaging->gtin : null);

        return DB::transaction(function () use ($partner, $packaging, $pivotAttrs, $autoAddManufacturer, $empty, $preferredGtin): array {
            $result = $empty;
            $product = $this->findProduct($packaging, $preferredGtin);
            $created = false;

            if ($product === null) {
                $attrs = FdaPrefill::packagingAttributes($packaging);

                if ($preferredGtin !== null) {
                    $attrs['gtin'] = $preferredGtin;
                }

                $packageNdc11 = $attrs['ndc11'] ?? (filled($packaging->ndc11) ? (string) $packaging->ndc11 : null);
                $attrs['ndc11'] = $this->ndc11ForNewProduct($packageNdc11);

                $product = Product::query()->create($attrs);
                $listing = $packaging->relationLoaded('product') ? $packaging->product : $packaging->product()->first();
                if ($listing !== null) {
                    FdaTenantLink::stampProduct($product, $listing, $packaging);
                }
                $created = true;
                $result['added'] = 1;

                if ($attrs['ndc11'] === null && $packageNdc11 !== null) {
                    $this->linkOuterPackagingToUnit($product, $packageNdc11, $pivotAttrs);
                }
            } else {
                $this->backfillExistingProduct($product, $packaging, $preferredGtin);
            }

            $manufacturerOutcome = $this->ensureManufacturer($product, $packaging, $autoAddManufacturer);
            $result['manufacturer_pending'] = $manufacturerOutcome['pending'];
            $result['manufacturer_added'] = $manufacturerOutcome['added'];

            $authorizationStatus = $manufacturerOutcome['pending'] === 1
                ? AuthorizationStatus::PendingManufacturer->value
                : AuthorizationStatus::Authorized->value;

            $existingPivot = $partner->products()
                ->where('products.id', $product->getKey())
                ->first()
                ?->pivot;

            if ($existingPivot !== null) {
                if (
                    $existingPivot->authorization_status === AuthorizationStatus::PendingManufacturer->value
                    && $manufacturerOutcome['pending'] === 0
                ) {
                    $partner->products()->updateExistingPivot($product->getKey(), [
                        'authorization_status' => AuthorizationStatus::Authorized->value,
                        'authorized_at' => $existingPivot->authorized_at ?? now(),
                    ]);
                }

                $result['skipped'] = 1;
                $result['product_id'] = $product->getKey();

                return $result;
            }

            $pivot = [
                'authorization_status' => $authorizationStatus,
                'authorized_at' => now(),
            ];

            foreach (['partner_item_number', 'uom_code', 'units_per_case'] as $field) {
                if (array_key_exists($field, $pivotAttrs) && $pivotAttrs[$field] !== null) {
                    $pivot[$field] = $pivotAttrs[$field];
                }
            }

            if (array_key_exists('is_primary', $pivotAttrs) && $pivotAttrs['is_primary'] !== null) {
                $pivot['is_primary'] = (bool) $pivotAttrs['is_primary'];
            } elseif (! $this->setPrimary->productHasPrimary($product->getKey())) {
                $pivot['is_primary'] = true;
            }

            $partner->products()->attach($product->getKey(), $pivot);

            if (($pivot['is_primary'] ?? false) === true) {
                $this->setPrimary->handle($product->getKey(), $partner->getKey());
            }

            if (! $created) {
                $result['attached'] = 1;
            }

            $result['product_id'] = $product->getKey();

            return $result;
        });
    }

    /**
     * GTIN is matched before NDC-11 because a case and the units inside it share an
     * NDC-11 but are different trade items. Matching NDC-11 first made a case scan
     * resolve to the unit product, which then kept its own GTIN — so the case GTIN
     * never entered product master and stayed an UNKNOWN_GTIN exception.
     */
    private function findProduct(FdaProductPackaging $packaging, ?string $preferredGtin): ?Product
    {
        if ($preferredGtin !== null) {
            $byGtin = Product::query()->where('gtin', $preferredGtin)->first();

            if ($byGtin !== null) {
                return $byGtin;
            }
        }

        $candidate = $this->findByNdc11OrPackagingLink($packaging);

        if ($candidate === null) {
            return null;
        }

        if ($preferredGtin === null || blank($candidate->gtin) || $candidate->gtin === $preferredGtin) {
            return $candidate;
        }

        return null;
    }

    private function findByNdc11OrPackagingLink(FdaProductPackaging $packaging): ?Product
    {
        if (filled($packaging->ndc11)) {
            $byNdc11 = Product::query()->where('ndc11', $packaging->ndc11)->first();

            if ($byNdc11 !== null) {
                return $byNdc11;
            }
        }

        return Product::query()->where('fda_product_packaging_id', $packaging->getKey())->first();
    }

    private function ndc11ForNewProduct(?string $ndc11): ?string
    {
        if ($ndc11 === null) {
            return null;
        }

        return Product::query()->where('ndc11', $ndc11)->exists() ? null : $ndc11;
    }

    /**
     * @param  array{partner_item_number?: ?string, uom_code?: ?string, units_per_case?: ?int, is_primary?: ?bool}  $pivotAttrs
     */
    private function linkOuterPackagingToUnit(Product $product, string $packageNdc11, array $pivotAttrs): void
    {
        $quantity = (int) ($pivotAttrs['units_per_case'] ?? 0);

        if ($quantity < 1 || ! Schema::hasTable('product_packaging_links')) {
            return;
        }

        $sibling = Product::query()
            ->where('ndc11', $packageNdc11)
            ->whereKeyNot($product->getKey())
            ->first();

        if ($sibling === null) {
            return;
        }

        $pair = $this->outerAndInner($product, $sibling);

        if ($pair === null) {
            return;
        }

        ProductPackagingLink::query()->firstOrCreate(
            [
                'parent_product_id' => $pair['outer']->getKey(),
                'child_product_id' => $pair['inner']->getKey(),
            ],
            [
                'quantity' => $quantity,
                'pack_level' => PackLevel::Case->value,
            ],
        );
    }

    /**
     * @return array{outer: Product, inner: Product}|null
     */
    private function outerAndInner(Product $product, Product $sibling): ?array
    {
        $productLevel = Gtin::packagingIndicator($product->gtin);
        $siblingLevel = Gtin::packagingIndicator($sibling->gtin);

        if ($productLevel === null || $siblingLevel === null || $productLevel === $siblingLevel) {
            return null;
        }

        return $productLevel > $siblingLevel
            ? ['outer' => $product, 'inner' => $sibling]
            : ['outer' => $sibling, 'inner' => $product];
    }

    /**
     * @return array{pending: int, added: int}
     */
    private function ensureManufacturer(Product $product, FdaProductPackaging $packaging, bool $autoAddManufacturer): array
    {
        $listing = $packaging->relationLoaded('product') ? $packaging->product : $packaging->product()->first();
        $organizationId = $listing?->fda_organization_id;

        if ($organizationId === null || filled($product->trading_partner_id)) {
            return ['pending' => 0, 'added' => 0];
        }

        $manufacturer = $this->resolveManufacturer((int) $organizationId);

        if ($manufacturer === null && $autoAddManufacturer && $listing?->fdaOrganization) {
            $manufacturer = app(EnsureOrganizationPartnerFromFda::class)
                ->handle($listing->fdaOrganization);

            if ($manufacturer !== null) {
                $product->update(['trading_partner_id' => $manufacturer->getKey()]);

                return ['pending' => 0, 'added' => 1];
            }
        }

        if ($manufacturer !== null) {
            $product->update(['trading_partner_id' => $manufacturer->getKey()]);

            return ['pending' => 0, 'added' => 0];
        }

        return ['pending' => 1, 'added' => 0];
    }

    private function backfillExistingProduct(
        Product $product,
        FdaProductPackaging $packaging,
        ?string $preferredGtin,
    ): void {
        $updates = [];

        if ($preferredGtin !== null && blank($product->gtin)) {
            $gtinTaken = Product::query()
                ->where('gtin', $preferredGtin)
                ->whereKeyNot($product->getKey())
                ->exists();

            if (! $gtinTaken) {
                $updates['gtin'] = $preferredGtin;
            }
        }

        if ($updates !== []) {
            $product->update($updates);
        }

        if (blank($product->fda_product_packaging_id)) {
            $listing = $packaging->relationLoaded('product') ? $packaging->product : $packaging->product()->first();
            if ($listing !== null) {
                FdaTenantLink::stampProduct($product->fresh() ?? $product, $listing, $packaging);
            }
        }
    }

    private function resolveManufacturer(int $organizationId): ?TradingPartner
    {
        return TradingPartner::query()
            ->where('fda_organization_id', $organizationId)
            ->first();
    }
}
