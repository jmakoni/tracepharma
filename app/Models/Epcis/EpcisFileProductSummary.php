<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;

/**
 * Non-persisted row for EPCIS file product summary tables.
 * Filament RelationManagers require Eloquent models for record actions.
 */
class EpcisFileProductSummary extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $table = 'epcis_file_product_summaries';

    protected function casts(): array
    {
        return [
            'linked' => 'boolean',
            'document_epc_count' => 'integer',
            'product_id' => 'integer',
        ];
    }
}
