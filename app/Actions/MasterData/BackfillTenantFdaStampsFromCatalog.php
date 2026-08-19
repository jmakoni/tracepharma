<?php

namespace App\Actions\MasterData;

use App\Enums\PartnerType;
use App\Models\AtpLicense;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProductPackaging;
use App\Models\Fda\FdaWddFacility;
use App\Models\Fda\FdaWddLicense;
use App\Models\Product;
use App\Models\Site;
use App\Models\TradingPartner;
use App\Support\Gs1\Ndc;
use App\Support\Places\UsState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot: resolve tenant FDA stamps from fda_* identity (GLN / GTIN / NDC),
 * then drop leftover tenant catalog_*_id columns.
 */
class BackfillTenantFdaStampsFromCatalog
{
    /**
     * @return array{partners: int, sites: int, products: int, licenses: int}
     */
    public function handle(): array
    {
        $counts = [
            'partners' => 0,
            'sites' => 0,
            'products' => 0,
            'licenses' => 0,
        ];

        $this->backfillPartners($counts);
        $this->backfillSites($counts);
        $this->backfillProducts($counts);
        $this->backfillLicenses($counts);
        $this->dropCatalogStampColumns();

        return $counts;
    }

    /**
     * @param  array{partners: int, sites: int, products: int, licenses: int}  $counts
     */
    protected function backfillPartners(array &$counts): void
    {
        if (! Schema::hasColumn('trading_partners', 'catalog_trading_partner_id')) {
            return;
        }

        $this->constrainPartners(
            TradingPartner::query()
                ->whereNull('fda_organization_id')
                ->whereNotNull('gln')
        )->each(function (TradingPartner $partner) use (&$counts): void {
                $fdaId = FdaOrganization::query()
                    ->where('gln', $partner->gln)
                    ->value('id');

                if ($fdaId === null) {
                    return;
                }

                $partner->forceFill(['fda_organization_id' => $fdaId])->save();
                $counts['partners']++;
            });
    }

    /**
     * @param  array{partners: int, sites: int, products: int, licenses: int}  $counts
     */
    protected function backfillSites(array &$counts): void
    {
        if (! Schema::hasColumn('sites', 'catalog_site_id')) {
            return;
        }

        $sites = Site::query()
            ->whereNull('fda_establishment_id')
            ->whereNull('fda_wdd_facility_id')
            ->whereNotNull('gln')
            ->whereNotNull('trading_partner_id')
            ->with('tradingPartner');

        if (Schema::hasColumn('sites', 'is_organization_facility')) {
            $sites->where('is_organization_facility', false);
        }

        $this->constrainSites($sites)->each(function (Site $site) use (&$counts): void {
                $preferEstablishment = $site->tradingPartner?->partner_type === PartnerType::Manufacturer;

                if ($preferEstablishment) {
                    $establishmentId = FdaEstablishment::query()
                        ->where('gln', $site->gln)
                        ->value('id');

                    if ($establishmentId !== null) {
                        $site->forceFill([
                            'fda_establishment_id' => $establishmentId,
                            'fda_wdd_facility_id' => null,
                        ])->save();
                        $counts['sites']++;

                        return;
                    }
                }

                $facilityId = FdaWddFacility::query()
                    ->where('gln', $site->gln)
                    ->value('id');

                if ($facilityId !== null) {
                    $site->forceFill([
                        'fda_wdd_facility_id' => $facilityId,
                        'fda_establishment_id' => null,
                    ])->save();
                    $counts['sites']++;

                    return;
                }

                if (! $preferEstablishment) {
                    $establishmentId = FdaEstablishment::query()
                        ->where('gln', $site->gln)
                        ->value('id');

                    if ($establishmentId !== null) {
                        $site->forceFill([
                            'fda_establishment_id' => $establishmentId,
                            'fda_wdd_facility_id' => null,
                        ])->save();
                        $counts['sites']++;
                    }
                }
            });
    }

    /**
     * @param  array{partners: int, sites: int, products: int, licenses: int}  $counts
     */
    protected function backfillProducts(array &$counts): void
    {
        if (! Schema::hasColumn('products', 'catalog_product_id')) {
            return;
        }

        $this->constrainProducts(
            Product::query()
                ->where(function ($query): void {
                    $query->whereNull('fda_product_id')
                        ->orWhereNull('fda_product_packaging_id');
                })
        )->each(function (Product $product) use (&$counts): void {
                $updates = [];
                $packagingId = filled($product->fda_product_packaging_id)
                    ? (int) $product->fda_product_packaging_id
                    : $this->resolvePackagingId($product);

                if ($packagingId === null) {
                    return;
                }

                if (blank($product->fda_product_packaging_id)) {
                    $updates['fda_product_packaging_id'] = $packagingId;
                }

                $fdaProductId = FdaProductPackaging::query()
                    ->whereKey($packagingId)
                    ->value('fda_product_id');

                if (blank($product->fda_product_id) && filled($fdaProductId)) {
                    $updates['fda_product_id'] = $fdaProductId;
                }

                if ($updates === []) {
                    return;
                }

                $product->forceFill($updates)->save();
                $counts['products']++;
            });
    }

    private function resolvePackagingId(Product $product): ?int
    {
        $query = FdaProductPackaging::query();

        if (filled($product->gtin)) {
            $byGtin = (clone $query)->where('gtin', $product->gtin)->value('id');

            if ($byGtin !== null) {
                return (int) $byGtin;
            }
        }

        $ndc11 = filled($product->ndc11)
            ? (string) $product->ndc11
            : Ndc::toNdc11($product->package_ndc ?? $product->ndc);

        if (filled($ndc11)) {
            $byNdc11 = (clone $query)->where('ndc11', $ndc11)->value('id');

            if ($byNdc11 !== null) {
                return (int) $byNdc11;
            }
        }

        if (filled($product->package_ndc)) {
            $byPackage = (clone $query)->where('package_ndc', $product->package_ndc)->value('id');

            if ($byPackage !== null) {
                return (int) $byPackage;
            }
        }

        return null;
    }

    /**
     * @param  array{partners: int, sites: int, products: int, licenses: int}  $counts
     */
    protected function backfillLicenses(array &$counts): void
    {
        if (! Schema::hasColumn('atp_licenses', 'catalog_atp_license_id')) {
            return;
        }

        $this->constrainLicenses(
            AtpLicense::query()
                ->whereNull('fda_wdd_license_id')
                ->whereNotNull('license_state')
                ->whereNotNull('license_number')
                ->with('site.tradingPartner')
        )->each(function (AtpLicense $license) use (&$counts): void {
                $site = $license->site;

                if ($site === null
                    || $site->trading_partner_id === null
                    || $site->tradingPartner?->partner_type === PartnerType::Manufacturer
                    || (Schema::hasColumn('sites', 'is_organization_facility') && (bool) $site->is_organization_facility)
                    || blank($site->fda_wdd_facility_id)
                ) {
                    return;
                }

                $state = UsState::normalize($license->license_state)
                    ?? strtoupper(trim((string) $license->license_state));

                $fdaId = FdaWddLicense::query()
                    ->where('fda_wdd_facility_id', $site->fda_wdd_facility_id)
                    ->where('jurisdiction', $state)
                    ->where('license_number', $license->license_number)
                    ->where('is_active', true)
                    ->value('id');

                if ($fdaId === null) {
                    return;
                }

                $license->forceFill(['fda_wdd_license_id' => $fdaId])->save();
                $counts['licenses']++;
            });
    }

    /**
     * @param  Builder<TradingPartner>  $query
     * @return Builder<TradingPartner>
     */
    protected function constrainPartners(Builder $query): Builder
    {
        return $query;
    }

    /**
     * @param  Builder<Site>  $query
     * @return Builder<Site>
     */
    protected function constrainSites(Builder $query): Builder
    {
        return $query;
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    protected function constrainProducts(Builder $query): Builder
    {
        return $query;
    }

    /**
     * @param  Builder<AtpLicense>  $query
     * @return Builder<AtpLicense>
     */
    protected function constrainLicenses(Builder $query): Builder
    {
        return $query;
    }

    private function dropCatalogStampColumns(): void
    {
        $this->dropIfPresent('trading_partners', ['catalog_trading_partner_id']);
        $this->dropIfPresent('sites', ['catalog_site_id']);
        $this->dropIfPresent('products', ['catalog_product_id']);
        $this->dropNamedIndexIfPresent('atp_licenses', 'atp_licenses_catalog_license_index');
        $this->dropIfPresent('atp_licenses', ['catalog_atp_license_id']);
        $this->dropIfPresent('devices', ['catalog_device_id']);
        $this->dropIfPresent('location_devices', ['catalog_location_device_id']);
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropIfPresent(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $present = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column)
        ));

        if ($present === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($present): void {
            $blueprint->dropColumn($present);
        });
    }

    private function dropNamedIndexIfPresent(string $table, string $index): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropIndex($index);
        });
    }
}
