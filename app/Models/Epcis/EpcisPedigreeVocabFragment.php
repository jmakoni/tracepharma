<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EpcisPedigreeVocabFragment extends Model
{
    protected $table = 'epcis_pedigree_vocab_fragments';

    protected $fillable = [
        'document_id',
        'ingest_generation',
        'vocabulary_type',
        'element_id',
        'xml_sha256',
        'element_xml',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'document_id');
    }
}
