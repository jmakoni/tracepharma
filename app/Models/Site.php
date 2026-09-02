<?php

namespace App\Models;

use App\Actions\MasterData\AssignMissingDefaultSites;
use App\Actions\MasterData\ReleaseSitesFromOrganization;
use App\Enums\SsccNumberRangeStatus;
use App\Models\Concerns\DerivesSgln;
use App\Models\Fda\FdaEstablishment;
use App\Models\Fda\FdaWddFacility;
use App\Support\MasterData\SiteReferences;
use App\Support\Receiving\EligibleReceiveSites;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Site extends Model
{
    use DerivesSgln;

    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    use LogsActivity;

    protected static function booted(): void
    {
        static::saved(function (Site $site): void {
            app(AssignMissingDefaultSites::class)->handle($site);
        });

        static::saved(function (Site $site): void {
            if (! (bool) $site->is_headquarters) {
                return;
            }

            if (! $site->wasRecentlyCreated && ! $site->wasChanged('is_headquarters')) {
                return;
            }

            self::demoteSiblingHeadquarters($site);
        });

        static::saved(function (Site $site): void {
            if (! $site->wasChanged(['trading_partner_id', 'is_organization_facility'])) {
                return;
            }

            if ($site->trading_partner_id === null && (bool) $site->is_organization_facility) {
                return;
            }

            app(ReleaseSitesFromOrganization::class)->handle([(int) $site->getKey()]);
        });

        static::saved(function (Site $site): void {
            if (EligibleReceiveSites::isEligible($site)) {
                return;
            }

            SsccNumberRange::query()
                ->where('site_id', $site->getKey())
                ->where('status', SsccNumberRangeStatus::Active)
                ->get()
                ->each(fn (SsccNumberRange $range) => $range->markInactive());
        });

        // Registered before the cleanup listeners so a blocked delete leaves the
        // site's licenses and SSCC ranges untouched.
        static::deleting(function (Site $site): void {
            SiteReferences::assertDeletable($site);
        });

        static::deleting(function (Site $site): void {
            // Licenses belong to the location they authorize; nothing else points at them.
            $site->atpLicenses()->delete();

            SsccNumberRange::query()
                ->where('site_id', $site->getKey())
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

    protected $fillable = [
        'fda_establishment_id',
        'fda_wdd_facility_id',
        'trading_partner_id',
        'principal_id',
        'name',
        'code',
        'is_headquarters',
        'description',
        'gln',
        'sgln',
        'duns_number',
        'dea_number',
        'hin_number',
        'chemical_reg_number',
        'google_place_id',
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
        'is_active',
        'is_organization_facility',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_headquarters' => 'boolean',
            'is_organization_facility' => 'boolean',
            'altitude' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * A site is the physical party on every EPCIS event it reads or authors, so an
     * inspector asking "where was this shipped from, and who owned that dock" needs the
     * identity, ownership, activation and address history — not just the current row.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name',
                'gln',
                'sgln',
                'duns_number',
                'dea_number',
                'hin_number',
                'chemical_reg_number',
                'is_active',
                'is_headquarters',
                'trading_partner_id',
                'street_address',
                'street_address_2',
                'city',
                'state',
                'zipcode',
                'country_code',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    public function principal(): BelongsTo
    {
        return $this->belongsTo(Principal::class);
    }

    public function fdaEstablishment(): BelongsTo
    {
        return $this->belongsTo(FdaEstablishment::class);
    }

    public function fdaWddFacility(): BelongsTo
    {
        return $this->belongsTo(FdaWddFacility::class);
    }

    public function readPoints(): HasMany
    {
        return $this->hasMany(ReadPoint::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function locationDevices(): HasMany
    {
        return $this->hasMany(LocationDevice::class);
    }

    public function atpLicenses(): HasMany
    {
        return $this->hasMany(AtpLicense::class);
    }

    public function ssccNumberRanges(): HasMany
    {
        return $this->hasMany(SsccNumberRange::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    /**
     * Organization facility flag from trading partner: blank partner ⇒ owned by org.
     */
    public static function organizationFacilityFlagFromPartnerId(mixed $tradingPartnerId): bool
    {
        return blank($tradingPartnerId);
    }

    /**
     * Keep is_organization_facility honest on every create/save from form data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function syncOrganizationFacilityFlag(array $data): array
    {
        $data['is_organization_facility'] = self::organizationFacilityFlagFromPartnerId(
            $data['trading_partner_id'] ?? null,
        );

        return $data;
    }

    /**
     * Leave one headquarters per owner: the partner that owns the site, or the
     * organization itself when the site carries no partner.
     */
    private static function demoteSiblingHeadquarters(Site $site): void
    {
        $query = static::query()
            ->whereKeyNot($site->getKey())
            ->where('is_headquarters', true);

        $hasFlag = Schema::hasColumn($site->getTable(), 'is_organization_facility');

        if ($site->trading_partner_id !== null) {
            $query->where('trading_partner_id', $site->trading_partner_id);
        } elseif (! $hasFlag || (bool) $site->is_organization_facility) {
            $query->ownedByOrganization();
        } else {
            // Neither a partner location nor an organization facility: it can only
            // share the headquarters slot with other ownerless rows.
            $query->whereNull('trading_partner_id')->where('is_organization_facility', false);
        }

        $query->update(['is_headquarters' => false]);
    }

    public function scopeOwnedByOrganization(Builder $query): Builder
    {
        // Qualified: the scope is also applied through the site_user relation join.
        $table = $query->getModel()->getTable();

        $query->whereNull($table.'.trading_partner_id');

        if (Schema::hasColumn($table, 'is_organization_facility')) {
            $query->where($table.'.is_organization_facility', true);
        }

        return $query;
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'site_user')
            ->withPivot('is_default')
            ->withTimestamps();
    }
}
