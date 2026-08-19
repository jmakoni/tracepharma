<?php

namespace App\Models\Epcis;

use App\Models\Concerns\TenantSearchable;
use App\Models\TradingPartner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EpcisEvent extends Model
{
    use TenantSearchable;

    protected $table = 'epcis_events';

    protected $fillable = [
        'document_id',
        'ingest_generation',
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
    ];

    protected function casts(): array
    {
        return [
            'ingest_generation' => 'integer',
            'event_time' => 'datetime',
            'record_time' => 'datetime',
            'error_declaration' => 'array',
            'corrective_event_ids' => 'array',
            'extension_json' => 'array',
            'certification_info' => 'array',
            'sensor_element_list' => 'array',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            ...$this->tenantSearchMetadata(),
            'event_id' => $this->event_id,
            'biz_step' => $this->biz_step,
            'action' => $this->action,
            'read_point' => $this->read_point_gln,
            'document_id' => $this->document_id,
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForGeneration(Builder $query, int $generation): Builder
    {
        return $query->where('ingest_generation', $generation);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EpcisDocument::class, 'document_id');
    }

    public function tradingPartner(): BelongsTo
    {
        return $this->belongsTo(TradingPartner::class);
    }

    public function eventEpcs(): HasMany
    {
        return $this->hasMany(EventEpc::class, 'event_id');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(EventParty::class, 'event_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(EventLocation::class, 'event_id');
    }

    public function bizTransactions(): HasMany
    {
        return $this->hasMany(EventBizTransaction::class, 'event_id');
    }

    public function epcIlmd(): HasMany
    {
        return $this->hasMany(EventEpcIlmd::class, 'event_id');
    }

    public function quantities(): HasMany
    {
        return $this->hasMany(EventQuantity::class, 'event_id');
    }

    public function epcs(): BelongsToMany
    {
        return $this->belongsToMany(Epc::class, 'event_epcs', 'event_id', 'epc_id')
            ->withPivot(['role', 'quantity', 'uom']);
    }
}
