<?php

namespace App\Models\Fda;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FdaWddLicense extends FdaModel
{
    protected $fillable = [
        'fda_wdd_facility_id',
        'license_number',
        'jurisdiction',
        'expiration_date',
        'reporting_year',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'expiration_date' => 'date',
            'reporting_year' => 'integer',
            'is_active' => 'boolean',
            'manually_edited_fields' => 'array',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(FdaWddFacility::class, 'fda_wdd_facility_id');
    }

}
