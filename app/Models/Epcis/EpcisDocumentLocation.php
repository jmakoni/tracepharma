<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpcisDocumentLocation extends Model
{
    protected $table = 'epcis_document_locations';

    protected $fillable = [
        'document_id',
        'ingest_generation',
        'gln_uri',
        'gln',
        'name',
        'street_address',
        'city',
        'state',
        'postal_code',
        'country_code',
        'attributes_json',
    ];

    protected function casts(): array
    {
        return [
            'ingest_generation' => 'integer',
            'attributes_json' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'document_id');
    }
}
