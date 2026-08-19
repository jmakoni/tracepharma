<?php

namespace App\Actions\MasterData;

use App\Models\Product;
use App\Models\TradingPartner;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Update trading_partner_product assortment fields for a partner–product link.
 */
final class UpdatePartnerProductAssortment
{
    public function __construct(
        private SetPrimaryReceiveFromPartner $setPrimary,
    ) {}

    /**
     * @param  array{partner_item_number?: ?string, uom_code?: ?string, units_per_case?: ?int, is_primary?: ?bool}  $pivotAttrs
     *
     * @throws DomainException when the partner item number already names another product
     */
    public function handle(TradingPartner $partner, Product $product, array $pivotAttrs): void
    {
        DB::transaction(function () use ($partner, $product, $pivotAttrs): void {
            $payload = [];

            foreach (['partner_item_number', 'uom_code', 'units_per_case', 'is_primary'] as $field) {
                if (array_key_exists($field, $pivotAttrs)) {
                    $payload[$field] = $pivotAttrs[$field];
                }
            }

            if (array_key_exists('partner_item_number', $payload)) {
                $itemNumber = self::normalizeItemNumber($payload['partner_item_number']);
                $payload['partner_item_number'] = $itemNumber;

                $conflictId = self::conflictingProductId($partner, $itemNumber, (int) $product->getKey());

                if ($conflictId !== null) {
                    throw new DomainException(self::conflictMessage($itemNumber, $conflictId));
                }
            }

            if ($payload !== []) {
                $partner->products()->updateExistingPivot($product->getKey(), $payload);
            }

            if (($pivotAttrs['is_primary'] ?? false) === true) {
                $this->setPrimary->handle($product->getKey(), $partner->getKey());
            }
        });
    }

    /**
     * Blank is stored as null so the uniqueness check can ignore unset item numbers
     * without treating '' and null as two different "no value" states.
     */
    public static function normalizeItemNumber(mixed $itemNumber): ?string
    {
        $trimmed = trim((string) $itemNumber);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * The other product in this partner's assortment already carrying $itemNumber.
     *
     * A partner item number is how an order line names one product to that partner, so
     * two products under the same number would make the order ambiguous. Null/blank is
     * exempt: most of the assortment has no partner SKU on file at all.
     */
    public static function conflictingProductId(
        TradingPartner $partner,
        mixed $itemNumber,
        ?int $ignoreProductId = null,
    ): ?int {
        $normalized = self::normalizeItemNumber($itemNumber);

        if ($normalized === null) {
            return null;
        }

        $conflictId = DB::table('trading_partner_product')
            ->where('trading_partner_id', $partner->getKey())
            ->where('partner_item_number', $normalized)
            ->when(
                $ignoreProductId !== null,
                fn ($query) => $query->where('product_id', '!=', $ignoreProductId),
            )
            ->orderBy('product_id')
            ->value('product_id');

        return $conflictId === null ? null : (int) $conflictId;
    }

    public static function conflictMessage(string $itemNumber, int $conflictProductId): string
    {
        $name = Product::query()->whereKey($conflictProductId)->value('name');

        return sprintf(
            'Partner item number "%s" is already used by %s for this partner. Partner item numbers must name one product.',
            $itemNumber,
            filled($name) ? '"'.$name.'"' : 'another product',
        );
    }
}
