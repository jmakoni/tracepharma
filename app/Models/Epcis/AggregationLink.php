<?php

namespace App\Models\Epcis;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class AggregationLink extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'aggregation_links';

    protected $fillable = [
        'parent_epc_id',
        'child_epc_id',
        'established_by_event_id',
        'link_type',
        'valid_from',
        'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function parentEpc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'parent_epc_id');
    }

    public function childEpc(): BelongsTo
    {
        return $this->belongsTo(Epc::class, 'child_epc_id');
    }

    public function establishedByEvent(): BelongsTo
    {
        return $this->belongsTo(EpcisEvent::class, 'established_by_event_id');
    }

    /**
     * Links that have not been closed by AggregationEvent DELETE or reprocess supersede.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull($query->getModel()->getTable().'.valid_to');
    }

    /**
     * Links established by events belonging to this document's current ingest generation
     * (falls back to all events for the document when generation columns are absent).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForDocumentProjection(Builder $query, EpcisDocument $document): Builder
    {
        return $query->whereIn('established_by_event_id', function ($sub) use ($document): void {
            $sub->select('id')
                ->from('epcis_events')
                ->where('document_id', $document->getKey());

            if (
                Schema::hasColumn('epcis_events', 'ingest_generation')
                && Schema::hasColumn('epcis_documents', 'ingest_generation')
                && filled($document->getAttribute('ingest_generation'))
            ) {
                $sub->where('ingest_generation', (int) $document->getAttribute('ingest_generation'));
            }
        });
    }
}
