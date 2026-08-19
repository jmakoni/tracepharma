<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpcisDocumentProductClass extends Model
{
    protected $table = 'epcis_document_product_classes';

    protected $fillable = [
        'document_id',
        'ingest_generation',
        'idpat',
        'gtin14',
        'ndc_raw',
        'ndc11',
        'name',
        'dosage_form',
        'strength',
        'manufacturer',
        'net_content',
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
