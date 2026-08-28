<?php

namespace App\Models;

use App\Enums\AtpLicenseExpirationStatus;
use App\Enums\FacilityType;
use App\Models\Fda\FdaWddLicense;
use Database\Factories\AtpLicenseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class AtpLicense extends Model
{
    /** @use HasFactory<AtpLicenseFactory> */
    use HasFactory;

    use LogsActivity;

    protected $fillable = [
        'site_id',
        'fda_wdd_license_id',
        'facility_type',
        'license_number',
        'license_country',
        'license_state',
        'license_expiration_date',
        'reporting_year',
        'facility_contact_person',
        'facility_contact_email',
        'facility_contact_phone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'facility_type' => FacilityType::class,
            'license_expiration_date' => 'date',
            'reporting_year' => 'integer',
            'fda_wdd_license_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * A licence is the evidence that a delivery to this location is lawful: it gates
     * outbound sends and raises soft warnings on ingest. An inspector asking why a
     * shipment was allowed — or why one was stopped — needs the licence as it stood
     * then, including when catalog sync deactivated it.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'site_id',
                'facility_type',
                'license_number',
                'license_country',
                'license_state',
                'license_expiration_date',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function fdaWddLicense(): BelongsTo
    {
        return $this->belongsTo(FdaWddLicense::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function expirationStatus(): AtpLicenseExpirationStatus
    {
        $expiration = $this->license_expiration_date;

        if ($expiration === null) {
            return AtpLicenseExpirationStatus::UnknownExpiry;
        }

        $today = now()->startOfDay();

        if ($expiration->lt($today)) {
            return AtpLicenseExpirationStatus::Expired;
        }

        if ($expiration->lte($today->copy()->addDays(90))) {
            return AtpLicenseExpirationStatus::Expiring;
        }

        return AtpLicenseExpirationStatus::Active;
    }
}
