<?php

namespace App\Actions\MasterData;

use App\Enums\PartnerType;
use App\Models\Fda\FdaProductPackaging;
use App\Models\TradingPartner;
use App\Support\Fda\FdaTenantLink;
use Illuminate\Support\Facades\DB;

/**
 * Add FDA package SKUs to a tenant partner's receivable assortment.
 */
final class AddFdaPackagesToTradingPartner
{
    /**
     * @param  array<int, int|string>  $packagingIds
     * @return array{added: int, attached: int, skipped: int, manufacturer_pending: int, manufacturer_added: int}
     */
    public function handle(
        TradingPartner $partner,
        array $packagingIds,
        bool $autoAddManufacturer = false,
    ): array {
        $empty = [
            'added' => 0,
            'attached' => 0,
            'skipped' => 0,
            'manufacturer_pending' => 0,
            'manufacturer_added' => 0,
        ];

        $ids = collect($packagingIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return $empty;
        }

        $organizationId = FdaTenantLink::organizationId($partner);

        if ($this->requiresLabelerScope($partner) && $organizationId === null) {
            return $empty;
        }

        $query = FdaProductPackaging::query()
            ->whereIn('id', $ids)
            ->with('product');

        if ($this->requiresLabelerScope($partner) && $organizationId !== null) {
            $query->whereHas('product', fn ($product) => $product->where('fda_organization_id', $organizationId));
        }

        $packages = $query->get();

        $added = 0;
        $attached = 0;
        $skipped = 0;
        $manufacturerPending = 0;
        $manufacturerAdded = 0;

        DB::transaction(function () use (
            $packages,
            $partner,
            $autoAddManufacturer,
            &$added,
            &$attached,
            &$skipped,
            &$manufacturerPending,
            &$manufacturerAdded,
        ): void {
            $authorizer = app(AuthorizeFdaPackagingForPartner::class);

            foreach ($packages as $packaging) {
                $result = $authorizer->handle($partner, $packaging, autoAddManufacturer: $autoAddManufacturer);
                $added += $result['added'];
                $attached += $result['attached'];
                $skipped += $result['skipped'];
                $manufacturerPending += $result['manufacturer_pending'];
                $manufacturerAdded += $result['manufacturer_added'];
            }
        });

        return [
            'added' => $added,
            'attached' => $attached,
            'skipped' => $skipped,
            'manufacturer_pending' => $manufacturerPending,
            'manufacturer_added' => $manufacturerAdded,
        ];
    }

    public function requiresLabelerScope(TradingPartner $partner): bool
    {
        return $partner->partner_type === PartnerType::Manufacturer;
    }
}
