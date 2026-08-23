<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;

/**
 * Non-persisted row for the document Exceptions tab (one unique type + status).
 */
class EpcisExceptionGroup extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $table = 'epcis_exception_groups';

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'case_id' => 'integer',
            'signal_id' => 'integer',
            'event_count' => 'integer',
            'epc_count' => 'integer',
            'gtins' => 'array',
            'ssccs' => 'array',
            'ndcs' => 'array',
        ];
    }
}
