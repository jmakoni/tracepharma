<?php

namespace App\Models\Fda;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FdaEstablishment extends FdaModel
{
    protected $fillable = [
        'fda_organization_id',
        'fei_number',
        'firm_name',
        'name',
        'code',
        'duns_number',
        'gln',
        'sgln',
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
        'expiration_date',
        'exclusion_flag',
        'is_currently_registered',
        'is_headquarters',
        'establishment_contact_name',
        'establishment_contact_email',
        'agent_details',
        'registrant_contact_name',
        'registrant_contact_email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'expiration_date' => 'date',
            'exclusion_flag' => 'boolean',
            'is_currently_registered' => 'boolean',
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

    public function operations(): HasMany
    {
        return $this->hasMany(FdaEstablishmentOperation::class);
    }

}
