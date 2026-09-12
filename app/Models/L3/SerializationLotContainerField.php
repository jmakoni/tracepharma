<?php

declare(strict_types=1);

namespace App\Models\L3;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-container asset-tracking fields from a Guardian lot-close feed
 * (`ContainerId[@Name]` -> value: GS1_XML, RawSeq, URI, Helper2D, ...).
 *
 * Never select `fields` on list pages — Fields tab / detail views only.
 */
class SerializationLotContainerField extends Model
{
    protected $table = 'serialization_lot_container_fields';

    protected $fillable = [
        'lot_id',
        'epc_uri',
        'container_type',
        'parent_epc_uri',
        'fields',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(SerializationLot::class, 'lot_id');
    }
}
