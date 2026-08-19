<?php

namespace App\Observers;

use App\Models\Product;
use App\Support\Gs1\Ndc;

class ProductObserver
{
    /**
     * Keep the canonical NDC-11 in step with the FDA package NDC / product NDC.
     *
     * Only derives when the packaging NDC actually changed or ndc11 is blank, so an
     * NDC-11 supplied by the catalog or an operator is never silently overwritten.
     *
     * `products.ndc11` is UNIQUE and a case shares its NDC-11 with the units inside
     * it, so a derived value another product already holds is left off this row: the
     * packaging level that claimed it keeps it, and this one is known by its GTIN.
     * An NDC-11 set explicitly still fails loudly rather than being dropped.
     */
    public function saving(Product $product): void
    {
        $sourceChanged = $product->isDirty('package_ndc') || $product->isDirty('ndc');

        if (filled($product->ndc11) && ! $sourceChanged) {
            return;
        }

        if ($product->isDirty('ndc11') && filled($product->ndc11)) {
            return;
        }

        $derived = Ndc::derive($product->package_ndc, $product->ndc);

        if ($derived === null || $derived === $product->ndc11) {
            return;
        }

        $taken = Product::query()
            ->where('ndc11', $derived)
            ->when($product->exists, fn ($query) => $query->whereKeyNot($product->getKey()))
            ->exists();

        if (! $taken) {
            $product->ndc11 = $derived;
        }
    }
}
