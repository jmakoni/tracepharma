<?php

namespace App\Models\Fda;

use App\Models\Product;
use App\Support\Catalog\IngredientStrength;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class FdaProduct extends FdaModel
{
    public const PRODUCT_TYPE_HUMAN_PRESCRIPTION = 'HUMAN PRESCRIPTION DRUG';

    public const PRODUCT_TYPE_HUMAN_OTC = 'HUMAN OTC DRUG';

    protected $fillable = [
        'product_id',
        'product_ndc',
        'generic_name',
        'brand_name',
        'brand_name_base',
        'name',
        'fda_organization_id',
        'marketing_category',
        'application_number',
        'dosage_form',
        'strength',
        'product_type',
        'dea_schedule',
        'finished',
        'marketing_start_date',
        'listing_expiration_date',
        'spl_id',
        'spl_set_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'finished' => 'boolean',
            'is_active' => 'boolean',
            'marketing_start_date' => 'date',
            'listing_expiration_date' => 'date',
            'manually_edited_fields' => 'array',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePrescription(Builder $query): Builder
    {
        return $query->where('product_type', self::PRODUCT_TYPE_HUMAN_PRESCRIPTION);
    }

    /**
     * Central FDA rows referenced by at least one tenant product (products.fda_product_id).
     *
     * Uses a cross-database EXISTS — fda_products lives on the central connection,
     * while tenant products are queried on the tenant connection (whereHas would fail).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLinkedToTenantProducts(Builder $query): Builder
    {
        $tenantConnection = static::resolveTenantConnectionName();
        $tenantDatabase = DB::connection($tenantConnection)->getDatabaseName();
        $productsTable = (new Product)->getTable();
        $fdaProductsTable = $query->getModel()->getTable();

        return $query->whereExists(function ($sub) use ($tenantDatabase, $productsTable, $fdaProductsTable): void {
            $sub->selectRaw('1')
                ->from(DB::raw(
                    '`'.str_replace('`', '``', $tenantDatabase).'`.`'.str_replace('`', '``', $productsTable).'` as `tenant_products`'
                ))
                ->whereColumn('tenant_products.fda_product_id', "{$fdaProductsTable}.id");
        });
    }

    protected static function resolveTenantConnectionName(): string
    {
        return (new Product)->getConnectionName() ?? config('database.default');
    }

    public function activeIngredients(): HasMany
    {
        return $this->hasMany(FdaProductActiveIngredient::class, 'product_id_fk');
    }

    /**
     * Every active ingredient's strength, in label order — a combination product is not
     * described by its first ingredient alone, and two combinations that share one look
     * identical when only that one is shown.
     */
    public function activeIngredientStrength(): ?string
    {
        $strengths = $this->relationLoaded('activeIngredients')
            ? $this->activeIngredients->sortBy('id')->pluck('strength')
            : $this->activeIngredients()->orderBy('id')->pluck('strength');

        return IngredientStrength::summarize($strengths->all());
    }

    public function packaging(): HasMany
    {
        return $this->hasMany(FdaProductPackaging::class);
    }

    public function pharmClasses(): HasMany
    {
        return $this->hasMany(FdaProductPharmClass::class, 'product_id_fk');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(FdaProductRoute::class, 'product_id_fk');
    }

    public function fdaOrganization(): BelongsTo
    {
        return $this->belongsTo(FdaOrganization::class);
    }
}
