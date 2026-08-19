<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpcisDocumentVocabularyElement extends Model
{
    protected $table = 'epcis_document_vocabulary_elements';

    protected $fillable = [
        'document_id',
        'ingest_generation',
        'vocabulary_type',
        'element_id',
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
