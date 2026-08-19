<?php

namespace App\Models;

use App\Models\Concerns\TenantSearchable;
use App\Models\Epcis\Epc;
use App\Models\Fda\FdaProduct;
use App\Models\Fda\FdaProductPackaging;
use App\Observers\ProductObserver;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[ObservedBy(ProductObserver::class)]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;
    use LogsActivity;
    use TenantSearchable;

    protected $fillable = [
        'fda_product_id',
        'fda_product_packaging_id',
        'trading_partner_id',
        'gtin',
        'name',
        'dosage_form',
        'strength',
        'ndc',
        'package_ndc',
        'ndc11',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['gtin', 'name', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            ...$this->tenantSearchMetadata(),
            'name' => $this->name,
            'gtin' => $this->gtin,
            'ndc' => $this->ndc,
            'ndc11' => $this->ndc11,
            'package_ndc' => $this->package_ndc,
            'dosage_form' => $this->dosage_form,
            'strength' => $this->strength,
            'is_active' => (bool) $this->is_active,
        ];
    }

    /**
     * Rx (HUMAN PRESCRIPTION DRUG) via FDA link, or manually entered (no FDA row).
     *
     * Uses a cross-database EXISTS — fda_products lives on the central connection,
     * while tenant products are queried on the tenant connection (whereHas would fail).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRx(Builder $query): Builder
    {
        $centralConnection = config('tenancy.database.central_connection', config('database.default'));
        $centralDatabase = DB::connection($centralConnection)->getDatabaseName();
        $productsTable = $query->getModel()->getTable();
        $type = FdaProduct::PRODUCT_TYPE_HUMAN_PRESCRIPTION;

        return $query->where(function (Builder $q) use ($centralDatabase, $productsTable, $type): void {
            $q->whereNull("{$productsTable}.fda_product_id")
                ->orWhereExists(function ($sub) use ($centralDatabase, $productsTable, $type): void {
                    $sub->selectRaw('1')
                        ->from(DB::raw(
                            '`'.str_replace('`', '``', $centralDatabase).'`.`fda_products` as `fda_products`'
                        ))
                        ->whereColumn('fda_products.id', "{$productsTable}.fda_product_id")
                        ->where('fda_products.product_type', $type);
                });
        });
    }

    public function fdaProduct(): BelongsTo
    {
        return $this->belongsTo(FdaProduct::class);
    }

    public function fdaProductPackaging(): BelongsTo
    {
        return $this->belongsTo(FdaProductPackaging::class);
    }

    /**
     * Optional manufacturer/labeler partner when known from catalog.
     */
    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    /**
     * Partners this tenant receives this product from (assortment).
     */
    public function tradingPartners(): BelongsToMany
    {
        return $this->belongsToMany(TradingPartner::class, 'trading_partner_product')
            ->withPivot([
                'partner_item_number',
                'uom_code',
                'units_per_case',
                'authorization_status',
                'authorized_at',
                'is_primary',
            ])
            ->withTimestamps();
    }

    public function epcs(): HasMany
    {
        return $this->hasMany(Epc::class);
    }

    /**
     * Packaging links where this product is the parent (e.g. case contains units).
     */
    public function packagingLinksAsParent(): HasMany
    {
        return $this->hasMany(ProductPackagingLink::class, 'parent_product_id');
    }

    /**
     * Packaging links where this product is the child (e.g. unit inside a case).
     */
    public function packagingLinksAsChild(): HasMany
    {
        return $this->hasMany(ProductPackagingLink::class, 'child_product_id');
    }

    /**
     * Child products contained by this packaging parent.
     */
    public function packagingChildren(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_packaging_links', 'parent_product_id', 'child_product_id')
            ->withPivot(['quantity', 'pack_level'])
            ->withTimestamps();
    }

    /**
     * Parent products that contain this packaging child.
     */
    public function packagingParents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_packaging_links', 'child_product_id', 'parent_product_id')
            ->withPivot(['quantity', 'pack_level'])
            ->withTimestamps();
    }
}
