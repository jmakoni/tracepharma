<?php

namespace App\Models;

use App\Models\Concerns\DerivesSgln;
use Database\Factories\LocationDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationDevice extends Model
{
    use DerivesSgln;

    /** @use HasFactory<LocationDeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'site_id',
        'name',
        'description',
        'gln',
        'sgln',
        'altitude',
        'latitude',
        'longitude',
        'logo',
    ];

    protected function casts(): array
    {
        return [
            'altitude' => 'decimal:2',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
