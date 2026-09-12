<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpcisEventArchive extends Model
{
    protected $table = 'epcis_events_archive';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'document_id',
        'ingest_generation',
        'superseded_at',
        'superseded_by_generation',
        'event_id',
        'event_type',
        'event_time',
        'event_timezone_offset',
        'record_time',
        'action',
        'biz_step',
        'disposition',
        'persistent_disposition',
        'error_declaration',
        'corrective_event_ids',
        'read_point_gln',
        'biz_location_gln',
        'trading_partner_id',
        'extension_json',
        'certification_info',
        'sensor_element_list',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'ingest_generation' => 'integer',
            'superseded_by_generation' => 'integer',
            'superseded_at' => 'datetime',
            'event_time' => 'datetime',
            'record_time' => 'datetime',
            'archived_at' => 'datetime',
            'error_declaration' => 'array',
            'corrective_event_ids' => 'array',
            'extension_json' => 'array',
            'certification_info' => 'array',
            'sensor_element_list' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'document_id');
    }
}
