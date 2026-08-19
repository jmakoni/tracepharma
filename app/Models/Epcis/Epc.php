<?php

namespace App\Models\Epcis;

use App\Models\Product;
use App\Support\Gs1\Sgtin;
use App\Support\Gs1\Sscc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Epc extends Model
{
    protected $table = 'epcs';

    protected $fillable = [
        'epc_uri',
        'epc_type',
        'company_prefix',
        'indicator_digit',
        'item_reference',
        'serial_number',
        'extension_digit',
        'gtin14',
        'sscc18',
        'ai_01_21',
        'ai_00',
        'digital_link',
        'packaging_level',
        'packaging_type',
        'product_id',
        'first_seen_at',
        'last_event_id',
    ];

    protected function casts(): array
    {
        return [
            'indicator_digit' => 'integer',
            'extension_digit' => 'integer',
            'first_seen_at' => 'datetime',
        ];
    }

    /**
     * Build an unsaved Epc from a Pure Identity URN (does not persist).
     */
    public static function fromUri(string $uri): self
    {
        return new self(static::materializeAttributesFromUri($uri));
    }

    /**
     * Map a Pure Identity URN to epcs column attributes via Sscc/Sgtin parsers.
     *
     * @return array<string, mixed>
     */
    public static function materializeAttributesFromUri(string $uri): array
    {
        $uri = trim($uri);

        if ($sgtin = Sgtin::fromUrn($uri)) {
            return [
                'epc_uri' => $sgtin['epc_uri'],
                'epc_type' => 'sgtin',
                'company_prefix' => $sgtin['company_prefix'],
                'indicator_digit' => (int) $sgtin['indicator_digit'],
                'item_reference' => $sgtin['item_reference'],
                'serial_number' => $sgtin['serial_number'],
                'gtin14' => $sgtin['gtin14'],
                'ai_01_21' => $sgtin['ai_01_21'],
            ];
        }

        if ($sscc = Sscc::fromUrn($uri)) {
            return [
                'epc_uri' => $sscc['epc_uri'],
                'epc_type' => 'sscc',
                'company_prefix' => $sscc['company_prefix'],
                'extension_digit' => (int) $sscc['extension_digit'],
                'serial_number' => $sscc['serial_reference'],
                'sscc18' => $sscc['sscc18'],
                'ai_00' => $sscc['ai_00'],
            ];
        }

        return [
            'epc_uri' => $uri,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ilmd(): HasOne
    {
        return $this->hasOne(EpcIlmd::class, 'epc_id');
    }

    public function eventEpcs(): HasMany
    {
        return $this->hasMany(EventEpc::class, 'epc_id');
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(EpcisEvent::class, 'event_epcs', 'epc_id', 'event_id')
            ->withPivot(['role', 'quantity', 'uom']);
    }

    public function aggregationLinksAsParent(): HasMany
    {
        return $this->hasMany(AggregationLink::class, 'parent_epc_id');
    }

    public function aggregationLinksAsChild(): HasMany
    {
        return $this->hasMany(AggregationLink::class, 'child_epc_id');
    }

    public function childEpcs(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'aggregation_links', 'parent_epc_id', 'child_epc_id')
            ->withPivot(['established_by_event_id', 'link_type', 'valid_from', 'valid_to', 'created_at']);
    }

    public function parentEpcs(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'aggregation_links', 'child_epc_id', 'parent_epc_id')
            ->withPivot(['established_by_event_id', 'link_type', 'valid_from', 'valid_to', 'created_at']);
    }
}
