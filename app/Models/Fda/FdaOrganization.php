<?php

namespace App\Models\Fda;

use App\Enums\PartnerType;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FdaOrganization extends FdaModel
{
    protected $fillable = [
        'original_name',
        'canonical_name',
        'name',
        'doing_business_as',
        'duns_number',
        'gln',
        'sgln',
        'partner_type',
        'telephone',
        'email',
        'fax',
        'website',
        'description',
        'logo',
        'street_address',
        'street_address_2',
        'city',
        'state_province',
        'postal_code',
        'country_code',
        'full_address',
        'timezone',
        'latitude',
        'longitude',
        'altitude',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'partner_type' => PartnerType::class,
            'is_active' => 'boolean',
            'altitude' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'manually_edited_fields' => 'array',
        ];
    }

    public function establishments(): HasMany
    {
        return $this->hasMany(FdaEstablishment::class);
    }

    public function wddFacilities(): HasMany
    {
        return $this->hasMany(FdaWddFacility::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(FdaProduct::class);
    }

    public function matchReviews(): HasMany
    {
        return $this->hasMany(FdaOrganizationMatchReview::class, 'resolved_fda_organization_id');
    }
}
