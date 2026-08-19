<?php

namespace App\Models\Epcis;

use App\Models\LocationDevice;
use App\Models\ReadPoint;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventLocation extends Model
{
    protected $table = 'event_locations';

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'location_type',
        'gln',
        'gln_uri',
        'name',
        'street_address',
        'city',
        'state',
        'postal_code',
        'country_code',
        'latitude',
        'longitude',
        'site_id',
        'location_device_id',
        'read_point_id',
        'extra_json',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'extra_json' => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(EpcisEvent::class, 'event_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function locationDevice(): BelongsTo
    {
        return $this->belongsTo(LocationDevice::class);
    }

    public function readPoint(): BelongsTo
    {
        return $this->belongsTo(ReadPoint::class);
    }
}
