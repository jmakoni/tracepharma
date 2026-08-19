<?php

namespace App\Models;

use App\Enums\DeviceType;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'device_type',
        'manufacturer',
        'model',
        'serial_number',
        'site_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'device_type' => DeviceType::class,
            'is_active' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
