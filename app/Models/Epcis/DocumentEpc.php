<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentEpc extends Model
{
    protected $table = 'document_epcs';

    public $incrementing = false;

    protected $primaryKey = null;

    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'epc_id',
        'ingest_generation',
    ];

    protected function casts(): array
    {
        return [
            'ingest_generation' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'document_id');
    }

    public function epc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'epc_id');
    }
}
