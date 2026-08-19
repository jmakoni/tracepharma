<?php

namespace App\Models\Fda;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FdaEstablishmentOperation extends FdaModel
{
    protected $fillable = [
        'fda_establishment_id',
        'operation_code',
    ];

    protected function casts(): array
    {
        return [
            'manually_edited_fields' => 'array',
        ];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(FdaEstablishment::class, 'fda_establishment_id');
    }
}
