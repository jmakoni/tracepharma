<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpcisPedigreeEventFragment extends Model
{
    protected $table = 'epcis_pedigree_event_fragments';

    protected $fillable = [
        'document_id',
        'ingest_generation',
        'event_local_name',
        'biz_step',
        'event_time',
        'seq',
        'xml_sha256',
        'event_xml',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'document_id');
    }
}
