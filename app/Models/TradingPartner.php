<?php

namespace App\Models;

use App\Enums\AtpVerificationSource;
use App\Enums\PartnerType;
use App\Enums\SsccNumberRangeStatus;
use App\Models\Concerns\DerivesSgln;
use App\Models\Concerns\TenantSearchable;
use App\Models\Fda\FdaOrganization;
use App\Models\Fda\FdaProduct;
use App\Support\MasterData\SiteReferences;
use App\Support\MasterData\TradingPartnerReferences;
use Database\Factories\TradingPartnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class TradingPartner extends Model
{
    use DerivesSgln;

    /** @use HasFactory<TradingPartnerFactory> */
    use HasFactory;

    use LogsActivity;
    use TenantSearchable;

    protected static function booted(): void
    {
        static::saved(function (TradingPartner $partner): void {
            if ($partner->is_active) {
                return;
            }

            SsccNumberRange::query()
                ->where('trading_partner_id', $partner->getKey())
                ->where('status', SsccNumberRangeStatus::Active)
                ->get()
                ->each(fn (SsccNumberRange $range) => $range->markInactive());
        });

        // Registered before the cleanup listeners so a blocked delete leaves sites and
        // ranges untouched.
        static::deleting(function (TradingPartner $partner): void {
            TradingPartnerReferences::assertDeletable($partner);
        });

        // Partner-owned sites go with the partner, which frees their GLNs for a later
        // re-import. Each site runs its own reference guard, so one that traceability
        // records still name blocks the whole delete.
        static::deleting(function (TradingPartner $partner): void {
            $sites = $partner->sites()->get();

            // Every site is cleared before the first one goes, so a blocked site never
            // leaves the partner standing with half of its locations deleted.
            $sites->each(fn (Site $site) => SiteReferences::assertDeletable($site));
            $sites->each(fn (Site $site) => $site->delete());
        });

        static::deleting(function (TradingPartner $partner): void {
            SsccNumberRange::query()
                ->where('trading_partner_id', $partner->getKey())
                ->get()
                ->each(function (SsccNumberRange $range): void {
                    if (! $range->hasIssuedSerials()) {
                        $range->delete();

                        return;
                    }

                    $range->markInactive();
                });
        });
    }

    /**
     * `portal_share_uuid` and `customer_portal_uuid` are intentionally absent: portal
     * tokens are issued only through SupplierPortalService / CustomerPortalService.
     */
    protected $fillable = [
        'fda_organization_id',
        'name',
        'doing_business_as',
        'description',
        'gln',
        'sgln',
        'duns_number',
        'dea_number',
        'hin_number',
        'chemical_reg_number',
        'partner_type',
        'street_address',
        'street_address_2',
        'city',
        'state',
        'zipcode',
        'country_code',
        'timezone',
        'altitude',
        'latitude',
        'longitude',
        'logo',
        'website',
        'telephone',
        'email',
        'vrs_notify_email',
        'fax',
        'is_active',
        'atp_verified_at',
        'atp_verified_by',
        'atp_verification_source',
        'atp_verification_url',
        'atp_verification_note',
    ];

    protected function casts(): array
    {
        return [
            'partner_type' => PartnerType::class,
            'atp_verification_source' => AtpVerificationSource::class,
            'atp_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'altitude' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function ssccNumberRanges(): HasMany
    {
        return $this->hasMany(SsccNumberRange::class);
    }

    /**
     * Self-relation so Filament can host a Contact tab without a child table.
     */
    public function contactCard(): HasOne
    {
        return $this->hasOne(static::class, 'id', 'id');
    }

    /**
     * Products this partner manufactures (labeler mirror on products.trading_partner_id).
     */
    public function manufacturedProducts(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Products this tenant expects to receive from this partner (assortment).
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'trading_partner_product')
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

    public function fdaOrganization(): BelongsTo
    {
        return $this->belongsTo(FdaOrganization::class);
    }

    /**
     * Who last recorded that this partner is authorized to transact.
     */
    public function atpVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atp_verified_by');
    }

    /**
     * FDA labeler products on the central registry (matched via FDA organization id).
     */
    public function fdaProducts(): HasMany
    {
        return $this->hasMany(FdaProduct::class, 'fda_organization_id', 'fda_organization_id');
    }

    public function locationDevices(): HasManyThrough
    {
        return $this->hasManyThrough(LocationDevice::class, Site::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'gln', 'sgln', 'duns_number', 'dea_number', 'hin_number', 'chemical_reg_number', 'partner_type', 'is_active', 'atp_verified_at', 'atp_verification_source'])
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
            'doing_business_as' => $this->doing_business_as,
            'gln' => $this->gln,
            'sgln' => $this->sgln,
            'partner_type' => $this->partner_type?->value,
            'city' => $this->city,
            'state' => $this->state,
            'street_address' => $this->street_address,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
