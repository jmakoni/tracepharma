<?php

namespace App\Models\Fda;

use App\Enums\FacilityType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FdaWdd3plStaging extends FdaModel
{
    protected $table = 'fda_wdd_3pl_staging';

    public $timestamps = false;

    protected $fillable = [
        'fda_organization_id',
        'facility_name',
        'alternate_name',
        'street_address',
        'city',
        'state',
        'zip',
        'contact_person',
        'contact_email',
        'contact_phone',
        'facility_type',
        'license_number',
        'license_state',
        'expiration_date',
        'reporting_year',
    ];

    protected function casts(): array
    {
        return [
            'reporting_year' => 'integer',
        ];
    }

    public function fdaOrganization(): BelongsTo
    {
        return $this->belongsTo(FdaOrganization::class);
    }

    /**
     * Promotion to catalog is retired; every staging row is treated as unpromoted.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnpromoted(Builder $query): Builder
    {
        return $query;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMissingPromoteFields(Builder $query): Builder
    {
        $validTypes = array_map(
            static fn (FacilityType $type): string => $type->value,
            FacilityType::cases(),
        );

        return $query->where(function (Builder $outer) use ($validTypes): void {
            $outer->where(function (Builder $inner): void {
                $inner->whereNull('license_number')
                    ->orWhere('license_number', '')
                    ->orWhereNull('license_state')
                    ->orWhere('license_state', '')
                    ->orWhereNull('reporting_year');
            })->orWhere(function (Builder $inner) use ($validTypes): void {
                $inner->whereNull('facility_type')
                    ->orWhere('facility_type', '')
                    ->orWhereNotIn('facility_type', $validTypes);
            });
        });
    }
}
