<?php

namespace App\Models\Fda;

use App\Enums\FacilityType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FdaWddFacility extends FdaModel
{
    protected $fillable = [
        'fda_organization_id',
        'facility_type',
        'facility_name',
        'name',
        'alternate_name',
        'code',
        'duns_number',
        'gln',
        'sgln',
        'dea_number',
        'hin_number',
        'chemical_reg_number',
        'street_address',
        'street_address_2',
        'city',
        'state_province',
        'postal_code',
        'country_code',
        'full_address',
        'address_fingerprint',
        'timezone',
        'latitude',
        'longitude',
        'altitude',
        'contact_person',
        'contact_email',
        'contact_phone',
        'is_headquarters',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'facility_type' => FacilityType::class,
            'is_headquarters' => 'boolean',
            'is_active' => 'boolean',
            'altitude' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'manually_edited_fields' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(FdaOrganization::class, 'fda_organization_id');
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(FdaWddLicense::class);
    }
}
