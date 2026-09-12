<?php

declare(strict_types=1);

namespace App\Models\L3;

use App\Models\Epcis\EpcisDocument;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot master row for a Guardian lot-close feed: UniTrace-style metadata (Material/NDC,
 * times, full LotControlData bag) alongside the projected EPCIS document id.
 *
 * Hierarchy for tracing stays in `aggregation_links` — {@see SerializationLotContainerField}
 * only carries per-container asset-tracking fields (GS1_XML, RawSeq, URI, ...).
 */
class SerializationLot extends Model
{
    protected $table = 'serialization_lots';

    protected $fillable = [
        'feed_id',
        'epcis_document_id',
        'lot_number',
        'ndc',
        'unit_gtin14',
        'case_gtin14',
        'product_name',
        'expire_date',
        'mfg_date',
        'site_id',
        'line_name',
        'lot_processed_at',
        'timezone_offset',
        'lot_info_saved_at',
        'lot_control_data',
        'pallet_count',
        'case_count',
        'unit_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'expire_date' => 'date',
            'mfg_date' => 'date',
            'lot_processed_at' => 'datetime',
            'lot_info_saved_at' => 'datetime',
            'lot_control_data' => 'array',
            'pallet_count' => 'integer',
            'case_count' => 'integer',
            'unit_count' => 'integer',
        ];
    }

    public function feed(): BelongsTo
    {
        return $this->belongsTo(L3LotFeed::class, 'feed_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function containerFields(): HasMany
    {
        return $this->hasMany(SerializationLotContainerField::class, 'lot_id');
    }

    public function epcisDocument(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'epcis_document_id');
    }
}
